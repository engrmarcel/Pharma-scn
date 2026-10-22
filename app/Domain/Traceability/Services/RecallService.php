<?php

namespace App\Domain\Traceability\Services;

use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\BatchQualityService;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Traceability\Events\RecallNoticeIssued;
use App\Domain\Traceability\Models\Recall;
use App\Domain\Traceability\Models\RecallNotice;
use App\Domain\Traceability\Models\TraceEvent;
use App\Models\User;
use App\Notifications\RecallNoticeNotification;
use App\Support\Events\DomainEvents;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Recall management (spec §16.5).
 *
 * Who: platform compliance staff (recalls.manage) for any product, or the brand owner (the
 * organization the product's manufacturer record points to) with recalls.initiate. Activation
 * needs a second person: the activator is never the creator.
 *
 * Activation reaches every organization that holds or has held the lots on the platform:
 * its batches are marked recalled and available stock moves to quarantine (in its own books,
 * through its own ledger, so its trace chain records the recall), and it gets a notice, an
 * e-mail and a recall.notice_issued webhook event. These are the only writes into other
 * organizations, and they only make stock less available.
 *
 * Recovery is derived from what each organization then records (write-offs, supplier
 * returns, customer returns, remaining stock), not self-reported. Coverage reports say what
 * the platform cannot see: customers outside the platform and anything never recorded here.
 */
class RecallService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BatchQualityService $quality,
        private readonly AuditLogger $audit,
    ) {}

    /** Recalls the caller may see: platform staff all; the issuer its own; notified organizations theirs. */
    public function visible(): Builder
    {
        $org = $this->tenant->id();
        if ($org === null) {
            Gate::authorize('recalls.manage');

            return Recall::query();
        }

        return Recall::query()->where(fn ($q) => $q->where('issuer_organization_id', $org)
            ->orWhereIn('id', RecallNotice::acrossTenants()->where('organization_id', $org)->select('recall_id')));
    }

    public function create(array $data, User $actor): Recall
    {
        $issuer = $this->tenant->organization();
        if ($issuer === null) {
            Gate::authorize('recalls.manage');
        } else {
            Gate::authorize('recalls.initiate');
            $owner = DB::table('products as p')->join('manufacturers as m', 'm.id', '=', 'p.manufacturer_id')->where('p.id', $data['product_id'])->value('m.organization_id');
            if ($owner !== $issuer->id) {
                throw new BusinessRuleViolation('recall_not_brand_owner', 'Only the brand owner of a product (or platform compliance) can recall it.', 403);
            }
        }

        return DB::transaction(function () use ($data, $actor, $issuer) {
            $recall = Recall::query()->create([
                'number' => $this->nextNumber(), 'issuer_organization_id' => $issuer?->id, 'product_id' => $data['product_id'], 'title' => $data['title'],
                'reason' => $data['reason'], 'classification' => $data['classification'], 'depth' => $data['depth'], 'authority' => $data['authority'] ?? null,
                'regulatory_reference' => $data['regulatory_reference'] ?? null, 'quarantine_instructions' => $data['quarantine_instructions'], 'created_by' => $actor->id,
            ]);
            foreach (array_unique(array_map(fn ($b) => strtoupper(trim($b)), $data['batch_numbers'])) as $number) {
                $recall->batches()->create(['batch_number' => $number]);
            }
            $this->audit->record('recall.created', $recall, new: ['number' => $recall->number, 'batches' => $recall->batchNumbers()], organizationId: $issuer?->id ?? false);

            return $recall;
        });
    }

    public function activate(Recall $recall, User $actor): Recall
    {
        $this->authorizeIssuer($recall);
        if ($recall->status !== 'draft') {
            throw new BusinessRuleViolation('recall_not_draft', "A {$recall->status} recall cannot be activated.", 409);
        }
        if ($recall->created_by === $actor->id) {
            throw new BusinessRuleViolation('approval_segregation', 'A recall is activated by someone other than the person who prepared it.', 409);
        }

        $notices = DB::transaction(function () use ($recall, $actor) {
            $locked = Recall::query()->lockForUpdate()->findOrFail($recall->id);
            $locked->forceFill(['status' => 'active', 'activated_by' => $actor->id, 'activated_at' => now()])->save();
            $batchNumbers = $locked->batchNumbers();
            $holders = Batch::acrossTenants()->where('product_id', $locked->product_id)->whereIn('batch_number', $batchNumbers)->get()->groupBy('organization_id');

            $notices = [];
            foreach ($holders as $orgId => $batches) {
                $org = Organization::query()->findOrFail($orgId);
                $notices[] = $this->tenant->runAs($org, function () use ($locked, $batches, $org) {
                    // Exact before/after boundary: the organization's chain position before the recall's own events.
                    $sequence = (int) DB::table('trace_chain_heads')->where('organization_id', $org->id)->value('sequence');
                    $moved = '0';
                    foreach ($batches as $batch) {
                        $moved = bcadd($moved, $this->quality->quarantineForRecall($batch, $locked->id, $locked->number), 4);
                    }
                    $notice = RecallNotice::query()->create(['recall_id' => $locked->id, 'organization_id' => $org->id, 'notified_at' => now(),
                        'held_at_notice' => $this->held($batches->pluck('id')->all()), 'quarantined_at_notice' => $moved, 'chain_sequence_at_notice' => $sequence]);
                    $this->audit->record('recall.notice_issued', $notice, new: ['recall' => $locked->number, 'held_at_notice' => (string) $notice->held_at_notice]);
                    DomainEvents::raise(new RecallNoticeIssued($locked->id, $notice->id, $org->id));

                    return $notice;
                });
            }
            $this->audit->record('recall.activated', $locked, ['status' => 'draft'], ['status' => 'active', 'organizations_notified' => count($notices)],
                organizationId: $locked->issuer_organization_id ?? false);

            return $notices;
        });

        foreach ($notices as $notice) {
            $this->notify($recall->fresh(), $notice);
        }

        return $recall->fresh();
    }

    public function close(Recall $recall, User $actor, string $notes, ?string $override): Recall
    {
        $this->authorizeIssuer($recall);
        if ($recall->status !== 'active') {
            throw new BusinessRuleViolation('recall_not_active', "A {$recall->status} recall cannot be closed.", 409);
        }
        $pending = $recall->notices()->withoutGlobalScopes()->where('status', 'pending')->count();
        if ($pending > 0 && blank($override)) {
            throw new BusinessRuleViolation('recall_notices_unacknowledged', "{$pending} organization(s) have not acknowledged the recall. Close only with an override reason.", 409,
                ['pending' => $pending]);
        }
        $recall->forceFill(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now(), 'closure_notes' => $notes,
            'closure_override_reason' => $pending > 0 ? $override : null])->save();
        $this->audit->record('recall.closed', $recall, ['status' => 'active'], ['status' => 'closed', 'pending_notices' => $pending, 'override' => $override],
            organizationId: $recall->issuer_organization_id ?? false);

        return $recall;
    }

    /**
     * Cancelling an active recall (issued in error) lifts the recall status of batches no other
     * recall covers. Quarantined stock is not released automatically: each organization decides.
     */
    public function cancel(Recall $recall, string $reason): Recall
    {
        $this->authorizeIssuer($recall);
        if (! in_array($recall->status, ['draft', 'active'], true)) {
            throw new BusinessRuleViolation('recall_not_cancellable', "A {$recall->status} recall cannot be cancelled.", 409);
        }
        $wasActive = $recall->status === 'active';

        DB::transaction(function () use ($recall, $reason, $wasActive) {
            $recall->forceFill(['status' => 'cancelled', 'cancellation_reason' => $reason])->save();
            if ($wasActive) {
                $stillRecalled = DB::table('recall_batches as rb')->join('recalls as r', 'r.id', '=', 'rb.recall_id')
                    ->where('r.product_id', $recall->product_id)->whereIn('r.status', ['active', 'closed'])->pluck('rb.batch_number')->all();
                Batch::acrossTenants()->where('product_id', $recall->product_id)->whereIn('batch_number', array_diff($recall->batchNumbers(), $stillRecalled))
                    ->update(['recall_status' => 'none', 'updated_at' => now()]);
            }
            $this->audit->record('recall.cancelled', $recall, new: ['reason' => $reason], organizationId: $recall->issuer_organization_id ?? false);
        });
        if ($wasActive) {
            foreach ($recall->notices()->withoutGlobalScopes()->get() as $notice) {
                $this->notify($recall, $notice, cancelled: true);
            }
        }

        return $recall;
    }

    public function acknowledge(RecallNotice $notice, User $actor, ?string $notes): RecallNotice
    {
        if ($notice->status !== 'pending') {
            throw new BusinessRuleViolation('recall_notice_acknowledged', 'This notice is already acknowledged.', 409);
        }
        $notice->forceFill(['status' => 'acknowledged', 'acknowledged_by' => $actor->id, 'acknowledged_at' => now(), 'acknowledgement_notes' => $notes])->save();
        $this->audit->record('recall.notice_acknowledged', $notice, ['status' => 'pending'], ['status' => 'acknowledged']);

        return $notice;
    }

    public function complete(RecallNotice $notice, User $actor, string $notes): RecallNotice
    {
        if ($notice->status !== 'acknowledged') {
            throw new BusinessRuleViolation('recall_notice_not_acknowledged', 'Acknowledge the notice before completing it.', 409);
        }
        $recovery = $this->recovery($notice);
        if (bccomp($recovery['still_held'], '0', 4) > 0 && blank($notes)) {
            throw new BusinessRuleViolation('recall_stock_still_held', 'Stock of the recalled batches is still held; explain why when completing.', 422);
        }
        $notice->forceFill(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now(), 'completion_notes' => $notes])->save();
        $this->audit->record('recall.notice_completed', $notice, ['status' => 'acknowledged'], ['status' => 'completed'] + $recovery);

        return $notice;
    }

    /**
     * What one organization did with the recalled lots since it was notified, from its own records.
     * Unaccounted = held when notified − still held − written off − returned to supplier − sold or shipped since.
     */
    public function recovery(RecallNotice $notice): array
    {
        $recall = $notice->recall;
        $batchIds = Batch::acrossTenants()->where('organization_id', $notice->organization_id)->where('product_id', $recall->product_id)
            ->whereIn('batch_number', $recall->batchNumbers())->pluck('id')->all();
        $after = TraceEvent::query()->where('organization_id', $notice->organization_id)->where('product_id', $recall->product_id)
            ->whereIn('batch_number', $recall->batchNumbers())->where('sequence', '>', $notice->chain_sequence_at_notice)->get();
        $sum = fn (array $types, bool $negativeOnly = false) => $after->whereIn('event_type', $types)
            ->reduce(fn ($carry, $e) => $negativeOnly
                ? (bccomp((string) ($e->details['signed_quantity'] ?? '0'), '0', 4) < 0 ? bcsub($carry, (string) $e->details['signed_quantity'], 4) : $carry)
                : bcadd($carry, (string) ($e->quantity ?? '0'), 4), '0');

        $held = $this->held($batchIds);
        $destroyed = $sum(['stock_adjustment'], negativeOnly: true); // a count that finds less stock is unaccounted, not written off
        $returned = $sum(['return_dispatch']);
        $recovered = $sum(['return_receipt']);
        $leftAfterRecall = $sum(['shipment_dispatch', 'dispensing_or_sale', 'transfer_dispatch']);
        $unaccounted = bcsub(bcsub(bcsub(bcadd((string) $notice->held_at_notice, $recovered, 4), $held, 4), bcadd($destroyed, $returned, 4), 4), $leftAfterRecall, 4);

        return [
            'held_at_notice' => (string) $notice->held_at_notice,
            'still_held' => $held,
            'written_off' => $destroyed,
            'returned_to_supplier' => $returned,
            'recovered_from_customers' => $recovered,
            'left_after_recall' => $leftAfterRecall,
            'unaccounted' => bccomp($unaccounted, '0', 4) > 0 ? $unaccounted : '0.0000',
        ];
    }

    /**
     * Exposure across the platform: per notified organization what it held, how much of the lots it
     * passed on before the recall (to platform organizations, to parties outside the platform, to
     * patients) and its recovery. Names of parties outside the platform are shown to platform
     * staff only; the coverage statement says what the platform cannot see.
     */
    public function exposure(Recall $recall): array
    {
        $platform = $this->tenant->id() === null;
        $rows = [];
        $offPlatform = ['parties' => [], 'quantity' => '0'];
        $total = fn ($events) => $events->reduce(fn ($carry, $e) => bcadd($carry, (string) ($e->quantity ?? '0'), 4), '0.0000');
        foreach ($recall->notices()->withoutGlobalScopes()->get() as $notice) {
            $events = TraceEvent::query()->where('organization_id', $notice->organization_id)->where('product_id', $recall->product_id)
                ->whereIn('batch_number', $recall->batchNumbers())->where('sequence', '<=', $notice->chain_sequence_at_notice)->get();
            $toOff = $events->where('event_type', 'shipment_dispatch')->whereNull('destination_organization_id');
            foreach ($toOff as $e) {
                $offPlatform['parties'][$e->counterparty_name ?? 'unnamed'] = true;
                $offPlatform['quantity'] = bcadd($offPlatform['quantity'], (string) $e->quantity, 4);
            }
            $rows[] = [
                'organization_id' => $notice->organization_id,
                'organization' => Organization::query()->whereKey($notice->organization_id)->value('name'),
                'notice_status' => $notice->status,
                'acknowledged_at' => $notice->acknowledged_at?->toIso8601String(),
                'received_before_recall' => $total($events->whereIn('event_type', ['stock_receipt', 'pharmacy_receipt', 'import_receipt', 'return_receipt'])),
                'shipped_to_platform_organizations' => $total($events->where('event_type', 'shipment_dispatch')->whereNotNull('destination_organization_id')),
                'shipped_outside_platform' => $total($toOff),
                'outside_platform_parties' => $platform ? $toOff->pluck('counterparty_name')->filter()->unique()->values()->all() : $toOff->pluck('counterparty_name')->unique()->count(),
                'dispensed_before_recall' => $total($events->where('event_type', 'dispensing_or_sale')),
            ] + $this->recovery($notice);
        }

        return [
            'recall_id' => $recall->id,
            'number' => $recall->number,
            'status' => $recall->status,
            'batch_numbers' => $recall->batchNumbers(),
            'organizations' => $rows,
            'totals' => [
                'organizations_notified' => count($rows),
                'acknowledged' => collect($rows)->where('notice_status', '!=', 'pending')->count(),
                'held_at_notice' => collect($rows)->reduce(fn ($c, $r) => bcadd($c, $r['held_at_notice'], 4), '0'),
                'still_held' => collect($rows)->reduce(fn ($c, $r) => bcadd($c, $r['still_held'], 4), '0'),
                'dispensed_before_recall' => collect($rows)->reduce(fn ($c, $r) => bcadd($c, $r['dispensed_before_recall'], 4), '0'),
                'unaccounted' => collect($rows)->reduce(fn ($c, $r) => bcadd($c, $r['unaccounted'], 4), '0'),
            ],
            'coverage' => [
                'outside_platform_parties' => count($offPlatform['parties']),
                'quantity_shipped_outside_platform' => $offPlatform['quantity'],
                'statement' => 'This report covers stock recorded on the platform. Quantities shipped to parties outside the platform, and any movement not recorded here, cannot be followed further: those parties must be contacted by the organizations that supplied them. Coverage is therefore not complete.',
            ],
        ];
    }

    /** The notified organization's own customers for the lots, so it can contact them. */
    public function downstream(RecallNotice $notice): array
    {
        $recall = $notice->recall;

        return TraceEvent::query()->where('organization_id', $notice->organization_id)->where('product_id', $recall->product_id)
            ->whereIn('batch_number', $recall->batchNumbers())->where('event_type', 'shipment_dispatch')
            ->get(['batch_number', 'quantity', 'destination_organization_id', 'counterparty_name', 'event_time', 'related_type', 'related_id'])
            ->map(fn ($e) => ['batch_number' => $e->batch_number, 'quantity' => (string) $e->quantity, 'customer' => $e->counterparty_name,
                'on_platform' => $e->destination_organization_id !== null, 'shipped_at' => $e->event_time->toIso8601String(),
                'document' => ['type' => $e->related_type, 'id' => $e->related_id]])->all();
    }

    private function held(array $batchIds): string
    {
        return bcadd((string) DB::table('inventory_balances')->whereIn('batch_id', $batchIds)->sum('quantity'), '0', 4);
    }

    private function notify(Recall $recall, RecallNotice $notice, bool $cancelled = false): void
    {
        $recipients = OrganizationMembership::query()->where('organization_id', $notice->organization_id)->where('status', 'active')
            ->whereHas('roles', fn ($r) => $r->where('code', 'org_owner')->orWhereHas('permissions', fn ($p) => $p->where('code', 'recalls.respond')))
            ->with('user')->get()->pluck('user')->filter();
        $product = DB::table('products')->where('id', $recall->product_id)->value('display_name');
        Notification::send($recipients, new RecallNoticeNotification(Organization::query()->whereKey($notice->organization_id)->value('name'), $recall->number,
            $recall->title, $recall->classification, (string) $product, $recall->batchNumbers(), (string) $notice->held_at_notice, $recall->quarantine_instructions, $cancelled));
    }

    private function authorizeIssuer(Recall $recall): void
    {
        $org = $this->tenant->id();
        if ($org === null) {
            Gate::authorize('recalls.manage');

            return;
        }
        Gate::authorize('recalls.initiate');
        if ($recall->issuer_organization_id !== $org) {
            throw new BusinessRuleViolation('recall_not_issuer', 'Only the issuer of a recall can manage it.', 403);
        }
    }

    private function nextNumber(): string
    {
        $year = now()->format('Y');
        $count = Recall::query()->where('number', 'like', "RCL-{$year}-%")->lockForUpdate()->count();

        return sprintf('RCL-%s-%05d', $year, $count + 1);
    }
}
