<?php

namespace App\Domain\Traceability\Services;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Catalogue\Support\Gtin;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Traceability\Models\TraceIdentifier;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QR codes and serialization (spec §16.3).
 *
 * - A QR code encodes only "<scan base URL>/<opaque token>"; the token is random (131 bits),
 *   so codes cannot be guessed or enumerated, and nothing confidential is in the code.
 * - Serials are registered per GTIN; a GTIN + serial pair exists once on the platform
 *   (duplicate detection across all organizations).
 * - Serials can be aggregated into a logistic unit (SSCC); revoking or decommissioning is a
 *   status change with a reason, and history is kept.
 */
class IdentifierService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly TraceChain $chain,
        private readonly AuditLogger $audit,
    ) {}

    public function issue(array $data, User $actor): TraceIdentifier
    {
        $this->assertInCatalogue($data['product_id'] ?? null);
        if ($data['kind'] === 'batch') {
            $batch = Batch::query()->where('product_id', $data['product_id'])->where('batch_number', strtoupper($data['batch_number'] ?? ''))->first()
                ?? throw new BusinessRuleViolation('batch_not_found', 'Record the batch before issuing a code for it.', 422);
            $data['batch_number'] = $batch->batch_number;
            $data['expiry_date'] = $batch->expiry_date?->toDateString();
        }
        if ($data['kind'] === 'sscc' && ! preg_match('/^\d{18}$/', (string) ($data['serial'] ?? ''))) {
            throw new BusinessRuleViolation('sscc_invalid', 'An SSCC has 18 digits.', 422);
        }

        $identifier = TraceIdentifier::query()->create(['kind' => $data['kind'], 'token' => $this->token(), 'product_id' => $data['product_id'] ?? null,
            'gtin' => $this->gtin($data['product_id'] ?? null), 'batch_number' => isset($data['batch_number']) ? strtoupper($data['batch_number']) : null,
            'expiry_date' => $data['expiry_date'] ?? null, 'serial' => $data['kind'] === 'sscc' ? $data['serial'] : null, 'created_by' => $actor->id]);
        $this->audit->record('trace_identifier.issued', $identifier, new: ['kind' => $identifier->kind, 'batch_number' => $identifier->batch_number]);

        return $identifier;
    }

    /**
     * Registers serials of one batch (given, or generated). All or nothing: any serial already
     * registered for this GTIN on the platform refuses the whole request.
     *
     * @return list<TraceIdentifier>
     */
    public function commissionSerials(string $productId, string $batchNumber, array $serials, ?string $parentId, User $actor): array
    {
        $this->assertInCatalogue($productId);
        $batch = Batch::query()->where('product_id', $productId)->where('batch_number', strtoupper($batchNumber))->first()
            ?? throw new BusinessRuleViolation('batch_not_found', 'Record the batch before registering its serials.', 422);
        $gtin = $this->gtin($productId) ?? throw new BusinessRuleViolation('gtin_required', 'Serialized packs are identified by GTIN + serial; the product has no GTIN.', 422);
        $serials = array_values(array_unique(array_map('trim', $serials)));
        if (count($serials) > config('traceability.max_serials_per_request')) {
            throw new BusinessRuleViolation('too_many_serials', 'Register at most '.config('traceability.max_serials_per_request').' serials per request.', 422);
        }
        foreach ($serials as $s) {
            if (! preg_match('/^[\x21-\x22\x25-\x2F\x30-\x39\x3A-\x3F\x41-\x5A\x5F\x61-\x7A]{1,20}$/', $s)) {
                throw new BusinessRuleViolation('serial_invalid', "Serial '{$s}' is not a valid GS1 serial (1–20 characters of the GS1 character set).", 422);
            }
        }
        $parent = $parentId ? TraceIdentifier::query()->where('kind', 'sscc')->findOrFail($parentId) : null;

        return DB::transaction(function () use ($productId, $batch, $gtin, $serials, $parent, $actor) {
            $taken = TraceIdentifier::acrossTenants()->where('gtin', $gtin)->whereIn('serial', $serials)->lockForUpdate()->pluck('serial');
            if ($taken->isNotEmpty()) {
                throw new BusinessRuleViolation('serial_duplicate', 'Some serials are already registered for this GTIN on the platform.', 409,
                    ['serials' => $taken->values()->all()]);
            }
            $created = [];
            foreach ($serials as $serial) {
                $created[] = TraceIdentifier::query()->create(['kind' => 'serial', 'token' => $this->token(), 'product_id' => $productId, 'gtin' => $gtin,
                    'batch_number' => $batch->batch_number, 'expiry_date' => $batch->expiry_date?->toDateString(), 'serial' => $serial,
                    'parent_id' => $parent?->id, 'created_by' => $actor->id]);
            }
            $this->chain->append($this->tenant->id(), ['event_type' => 'identifiers_commissioned', 'product_id' => $productId,
                'batch_number' => $batch->batch_number, 'batch_id' => $batch->id, 'quantity' => count($serials),
                'country_code' => $this->tenant->organization()->country_code,
                'details' => ['gtin' => $gtin, 'serials' => count($serials), 'parent_sscc' => $parent?->serial]]);

            return $created;
        });
    }

    /** @param list<string> $childIds */
    public function aggregate(TraceIdentifier $parent, array $childIds): int
    {
        if ($parent->kind !== 'sscc') {
            throw new BusinessRuleViolation('aggregation_parent_invalid', 'Packs are aggregated into a logistic unit (SSCC).', 422);
        }

        return TraceIdentifier::query()->whereIn('id', $childIds)->whereIn('kind', ['serial', 'sscc'])->whereKeyNot($parent->id)->update(['parent_id' => $parent->id]);
    }

    public function changeStatus(TraceIdentifier $identifier, string $status, string $reason): TraceIdentifier
    {
        if ($identifier->status === 'revoked') {
            throw new BusinessRuleViolation('identifier_revoked', 'A revoked identifier cannot change status.', 409);
        }
        $before = $identifier->status;
        $identifier->forceFill(['status' => $status, 'status_reason' => $reason])->save();
        // Children of a revoked logistic unit are revoked with it.
        if ($status === 'revoked' && $identifier->kind === 'sscc') {
            TraceIdentifier::query()->where('parent_id', $identifier->id)->where('status', 'active')->update(['status' => 'revoked', 'status_reason' => "Parent {$identifier->serial} revoked: {$reason}"]);
        }
        $this->audit->record('trace_identifier.status_changed', $identifier, ['status' => $before], ['status' => $status, 'reason' => $reason]);

        return $identifier;
    }

    public function qrSvg(TraceIdentifier $identifier, int $size = 256): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)))->writeString($identifier->url());
    }

    private function token(): string
    {
        do {
            $token = Str::random(22);
        } while (TraceIdentifier::acrossTenants()->where('token', $token)->exists());

        return $token;
    }

    private function gtin(?string $productId): ?string
    {
        if ($productId === null) {
            return null;
        }
        $value = DB::table('product_identifiers')->where('product_id', $productId)->where('type', 'gtin')->orderByDesc('is_primary')->orderBy('value')->value('value');

        return $value !== null && Gtin::isValid($value) ? str_pad($value, 14, '0', STR_PAD_LEFT) : null;
    }

    private function assertInCatalogue(?string $productId): void
    {
        if ($productId !== null && ! OrganizationProduct::query()->where('product_id', $productId)->exists()) {
            throw new BusinessRuleViolation('product_not_in_catalogue', 'The product is not in your catalogue.', 422);
        }
    }
}
