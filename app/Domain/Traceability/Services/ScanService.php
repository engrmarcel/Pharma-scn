<?php

namespace App\Domain\Traceability\Services;

use App\Domain\Traceability\Models\TraceIdentifier;
use App\Domain\Traceability\Models\TraceScan;
use App\Domain\Traceability\Support\Gs1;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a scanned code (platform QR token, GS1 element string or GS1 Digital
 * Link) to what the platform knows about the pack, and logs the scan.
 *
 * The result reports the recorded status of the lot and serial (recalled,
 * expired, revoked, already dispensed, not registered) and never states that a
 * pack is genuine (spec §16.3: a code by itself does not establish authenticity).
 * Public results contain no organization names, prices or patient data.
 */
class ScanService
{
    /** @return array{result: string, message: string, product: ?array, batch_number: ?string, expiry_date: ?string, serial: ?string, warnings: list<string>, disclaimer: string, identifier?: TraceIdentifier} */
    public function scan(string $code, ?string $clientIp = null): array
    {
        [$identifier, $parsed, $format] = $this->resolve($code);
        $productId = $identifier?->product_id ?? ($parsed['gtin'] ?? null ? $this->productByGtin($parsed['gtin']) : null);
        $batch = $identifier?->batch_number ?? ($parsed['batch_number'] ?? null);
        $serial = $identifier?->kind === 'serial' ? $identifier->serial : ($parsed['serial'] ?? null);
        if ($serial !== null && $identifier === null && isset($parsed['gtin'])) {
            $identifier = TraceIdentifier::acrossTenants()->where('gtin', str_pad($parsed['gtin'], 14, '0', STR_PAD_LEFT))->where('serial', $serial)->first();
        }
        $expiry = $parsed['expiry_date'] ?? $identifier?->expiry_date?->toDateString() ?? ($productId && $batch ? $this->recordedExpiry($productId, $batch) : null);

        $warnings = [];
        if (isset($parsed['expiry_date']) && $productId && $batch && ($recorded = $this->recordedExpiry($productId, $batch)) && $recorded !== $parsed['expiry_date']) {
            $warnings[] = "The expiry printed on the pack ({$parsed['expiry_date']}) differs from the expiry recorded for this batch ({$recorded}).";
        }

        [$result, $message] = match (true) {
            $productId === null => ['unknown', 'This code does not match a product registered on the platform.'],
            $identifier?->status === 'revoked' || $identifier?->status === 'decommissioned' => ['revoked', 'This code has been withdrawn by the organization that issued it.'],
            $batch !== null && $this->recalled($productId, $batch) => ['recalled', 'This batch is subject to a recall. Do not sell, dispense or use it; quarantine it and contact your supplier.'],
            $expiry !== null && Carbon::parse($expiry)->lt(today()) => ['expired', 'This batch is past its expiry date. Do not sell, dispense or use it.'],
            $identifier?->status === 'dispensed' => ['dispensed', 'This serial number has already been recorded as dispensed. A second pack with the same serial may be a counterfeit: report it.'],
            $serial !== null && $identifier === null => ['unregistered', 'This serial number has not been registered on the platform.'],
            default => ['ok', 'No recall, expiry or withdrawal is recorded for this pack.'],
        };

        TraceScan::query()->create(['identifier_id' => $identifier?->id, 'product_id' => $productId, 'batch_number' => $batch, 'serial' => $serial,
            'code_format' => $format, 'result' => $result, 'organization_id' => app(TenantContext::class)->id(), 'user_id' => Auth::id(),
            'client_hash' => $clientIp ? hash_hmac('sha256', $clientIp, (string) config('app.key')) : null, 'scanned_at' => now()]);

        return array_filter([
            'result' => $result,
            'message' => $message,
            'product_id' => $productId,
            'product' => $productId ? $this->product($productId) : null,
            'batch_number' => $batch,
            'expiry_date' => $expiry,
            'serial' => $serial,
            'warnings' => $warnings,
            'disclaimer' => config('traceability.disclaimer'),
            'identifier' => $identifier,
        ], fn ($v) => $v !== null);
    }

    /**
     * Records that a serialized pack has been supplied to a patient (decommissioned at dispensing),
     * as an event in the dispensing organization's chain. A pack can be dispensed once.
     */
    public function dispense(TraceIdentifier $identifier, TraceChain $chain): TraceIdentifier
    {
        return DB::transaction(function () use ($identifier, $chain) {
            $locked = TraceIdentifier::acrossTenants()->lockForUpdate()->findOrFail($identifier->id);
            if ($locked->kind !== 'serial') {
                throw new BusinessRuleViolation('identifier_not_serial', 'Only serialized packs are dispensed by code.', 422);
            }
            if ($locked->status !== 'active') {
                throw new BusinessRuleViolation('identifier_not_active', "This pack is {$locked->status} and cannot be dispensed.", 409);
            }
            if ($locked->batch_number && $this->recalled($locked->product_id, $locked->batch_number)) {
                throw new BusinessRuleViolation('batch_recalled', 'This batch is recalled and must not be dispensed.', 409);
            }
            if ($locked->expiry_date !== null && $locked->expiry_date->lt(today())) {
                throw new BusinessRuleViolation('batch_expired', 'This pack is expired and must not be dispensed.', 409);
            }
            $tenant = app(TenantContext::class);
            $locked->forceFill(['status' => 'dispensed', 'status_reason' => 'Dispensed by '.$tenant->organization()->name])->save();
            $chain->append($tenant->id(), ['event_type' => 'dispensing_or_sale', 'product_id' => $locked->product_id, 'batch_number' => $locked->batch_number,
                'serial' => $locked->serial, 'quantity' => 1, 'source_organization_id' => $tenant->id(), 'country_code' => $tenant->organization()->country_code,
                'details' => ['decommissioned_identifier_id' => $locked->id]]);

            return $locked;
        });
    }

    /** @return array{0: ?TraceIdentifier, 1: array, 2: string} */
    private function resolve(string $code): array
    {
        $code = trim($code);
        $base = rtrim(config('traceability.scan_base_url'), '/');
        $token = str_starts_with($code, $base.'/') ? substr($code, strlen($base) + 1) : $code;
        if (preg_match('/^[A-Za-z0-9]{22}$/', $token)) {
            return [TraceIdentifier::acrossTenants()->where('token', $token)->first(), [], 'token'];
        }
        $parsed = Gs1::parse($code);

        return [null, $parsed ?? [], $parsed['format'] ?? 'unknown'];
    }

    private function productByGtin(string $gtin): ?string
    {
        $core = ltrim($gtin, '0');
        $variants = array_values(array_unique(array_filter(array_map(fn ($l) => strlen($core) <= $l ? str_pad($core, $l, '0', STR_PAD_LEFT) : null, [8, 12, 13, 14]))));
        $ids = DB::table('product_identifiers')->where('type', 'gtin')->whereIn('value', $variants)->distinct()->pluck('product_id');

        return $ids->count() === 1 ? $ids->first() : null;
    }

    private function recalled(string $productId, string $batchNumber): bool
    {
        return DB::table('batches')->where('product_id', $productId)->where('batch_number', strtoupper($batchNumber))->where('recall_status', 'recalled')->exists()
            || DB::table('recall_batches as rb')->join('recalls as r', 'r.id', '=', 'rb.recall_id')->where('r.product_id', $productId)
                ->where('rb.batch_number', strtoupper($batchNumber))->whereIn('r.status', ['active', 'closed'])->exists();
    }

    private function recordedExpiry(string $productId, string $batchNumber): ?string
    {
        $value = DB::table('batches')->where('product_id', $productId)->where('batch_number', strtoupper($batchNumber))->whereNotNull('expiry_date')
            ->groupBy('expiry_date')->orderByRaw('COUNT(*) DESC')->value('expiry_date');

        return $value ? substr((string) $value, 0, 10) : null;
    }

    private function product(string $id): ?array
    {
        $p = DB::table('products as p')->leftJoin('dosage_forms as f', 'f.code', '=', 'p.dosage_form_code')->where('p.id', $id)
            ->first(['p.display_name', 'p.generic_name', 'p.strength_text', 'f.name as dosage_form']);

        return $p ? (array) $p : null;
    }
}
