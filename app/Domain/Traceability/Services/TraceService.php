<?php

namespace App\Domain\Traceability\Services;

use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Traceability\Models\TraceEvent;
use App\Domain\Traceability\Models\TraceEventVerification;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reading and amending the traceability ledger.
 *
 * Visibility: an organization sees the events it recorded and the events in which it is the
 * source or destination. Platform staff with traceability.view_all see every event (recall
 * and regulatory work). Nothing else is disclosed: an organization does not see where a lot
 * went after it left a customer that is another organization.
 *
 * Integrity: events are never edited. A correction or reversal is a new event pointing at the
 * original; verifications are separate records. The verification state shown is:
 *   recorded                a participant recorded it (the default; not proof)
 *   corroborated            the counterparty recorded the matching movement
 *   independently_verified  platform staff confirmed it from evidence (documents, inspection, regulator)
 *   disputed                platform staff found the record doubtful
 */
class TraceService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly TraceChain $chain,
        private readonly AuditLogger $audit,
    ) {}

    public function visible(): Builder
    {
        $org = $this->tenant->id();
        if ($org === null) {
            Gate::authorize('traceability.view_all');

            return TraceEvent::query();
        }

        return TraceEvent::query()->where(fn ($q) => $q->where('organization_id', $org)->orWhere('source_organization_id', $org)->orWhere('destination_organization_id', $org));
    }

    /** The visible history of one lot, oldest first, with corrections and verification state. */
    public function lot(string $productId, string $batchNumber): array
    {
        $events = $this->visible()->where('product_id', $productId)->where('batch_number', strtoupper($batchNumber))->orderBy('event_time')->orderBy('id')->get();

        return ['product_id' => $productId, 'batch_number' => strtoupper($batchNumber), 'events' => $this->present($events),
            'organizations' => $this->organizations($events),
            'note' => 'Only events your organization recorded or took part in are shown. Recorded events are statements by participants, not independent proof.'];
    }

    public function present(Collection $events): array
    {
        $ids = $events->pluck('id');
        $verifications = TraceEventVerification::query()->whereIn('trace_event_id', $ids)->orderBy('id')->get()->groupBy('trace_event_id');
        $corrections = TraceEvent::query()->where(fn ($q) => $q->whereIn('corrects_event_id', $ids)->orWhereIn('reverses_event_id', $ids))
            ->get(['id', 'event_type', 'corrects_event_id', 'reverses_event_id']);
        $names = DB::table('organizations')->whereIn('id', $events->pluck('organization_id')->merge($events->pluck('source_organization_id'))
            ->merge($events->pluck('destination_organization_id'))->filter()->unique())->pluck('name', 'id');

        return $events->map(fn (TraceEvent $e) => [
            'id' => $e->id,
            'event_type' => $e->event_type,
            'event_time' => $e->event_time->toIso8601String(),
            'recorded_at' => $e->recorded_at->toIso8601String(),
            'recorded_by_organization' => ['id' => $e->organization_id, 'name' => $names[$e->organization_id] ?? null],
            'product_id' => $e->product_id,
            'batch_number' => $e->batch_number,
            'serial' => $e->serial,
            'quantity' => $e->quantity === null ? null : (string) $e->quantity,
            'source' => array_filter(['organization_id' => $e->source_organization_id, 'name' => $names[$e->source_organization_id] ?? null, 'location' => $e->source_location]),
            'destination' => array_filter(['organization_id' => $e->destination_organization_id, 'name' => $names[$e->destination_organization_id] ?? null, 'location' => $e->destination_location]),
            'counterparty_name' => $e->organization_id === $this->tenant->id() || $this->tenant->id() === null ? $e->counterparty_name : null,
            'related' => $e->related_type ? ['type' => $e->related_type, 'id' => $e->organization_id === $this->tenant->id() || $this->tenant->id() === null ? $e->related_id : null] : null,
            'source_system' => $e->source_system,
            'corrects_event_id' => $e->corrects_event_id,
            'reverses_event_id' => $e->reverses_event_id,
            'correction_reason' => $e->correction_reason,
            'superseded_by' => $corrections->filter(fn ($c) => $c->corrects_event_id === $e->id || $c->reverses_event_id === $e->id)->pluck('id')->values(),
            'verification' => $this->verificationState($verifications[$e->id] ?? collect()),
            'details' => Arr::except($e->details ?? [], ['correlation_id']),
        ])->values()->all();
    }

    /** A correction restates the event with corrected values; the original stays in the history. */
    public function correct(TraceEvent $event, array $changes, string $reason): TraceEvent
    {
        $this->assertOwn($event);

        return $this->chain->append($event->organization_id, [
            'event_type' => 'correction',
            'product_id' => $event->product_id,
            'batch_number' => isset($changes['batch_number']) ? strtoupper($changes['batch_number']) : $event->batch_number,
            'batch_id' => $event->batch_id,
            'serial' => $changes['serial'] ?? $event->serial,
            'quantity' => $changes['quantity'] ?? $event->quantity,
            'source_organization_id' => $event->source_organization_id,
            'destination_organization_id' => $event->destination_organization_id,
            'source_location' => $changes['source_location'] ?? $event->source_location,
            'destination_location' => $changes['destination_location'] ?? $event->destination_location,
            'counterparty_name' => $changes['counterparty_name'] ?? $event->counterparty_name,
            'related_type' => $event->related_type,
            'related_id' => $event->related_id,
            'event_time' => $changes['event_time'] ?? $event->event_time,
            'country_code' => $event->country_code,
            'corrects_event_id' => $event->id,
            'correction_reason' => $reason,
            'details' => ['corrected_event_type' => $event->event_type, 'changed' => array_keys($changes)],
        ]);
    }

    /** A reversal states that the event did not happen as recorded. */
    public function reverse(TraceEvent $event, string $reason): TraceEvent
    {
        $this->assertOwn($event);
        if (TraceEvent::query()->where('reverses_event_id', $event->id)->exists()) {
            throw new BusinessRuleViolation('trace_event_already_reversed', 'This event has already been reversed.', 409);
        }

        return $this->chain->append($event->organization_id, [
            'event_type' => 'reversal', 'product_id' => $event->product_id, 'batch_number' => $event->batch_number, 'batch_id' => $event->batch_id,
            'serial' => $event->serial, 'quantity' => $event->quantity, 'related_type' => $event->related_type, 'related_id' => $event->related_id,
            'country_code' => $event->country_code, 'reverses_event_id' => $event->id, 'correction_reason' => $reason,
            'details' => ['reversed_event_type' => $event->event_type],
        ]);
    }

    public function verify(TraceEvent $event, string $level, string $method, string $evidence, User $verifier): TraceEventVerification
    {
        Gate::authorize('traceability.verify');
        $verification = TraceEventVerification::query()->create(['trace_event_id' => $event->id, 'level' => $level, 'method' => $method,
            'evidence' => $evidence, 'verified_by' => $verifier->id]);
        $this->audit->record("trace_event.{$level}", $event, new: ['method' => $method], organizationId: false);

        return $verification;
    }

    private function verificationState(Collection $verifications): array
    {
        $platform = $verifications->whereIn('level', ['independently_verified', 'disputed'])->last();
        $corroborated = $verifications->firstWhere('level', 'corroborated');
        $state = $platform?->level ?? ($corroborated ? 'corroborated' : 'recorded');

        return array_filter(['state' => $state, 'method' => $platform?->method ?? $corroborated?->method,
            'at' => ($platform ?? $corroborated)?->created_at?->toIso8601String()]);
    }

    /** Organizations appearing in the visible history, with the role they play. */
    private function organizations(Collection $events): array
    {
        $roles = [];
        foreach ($events as $e) {
            foreach (['organization_id' => 'recorder', 'source_organization_id' => 'source', 'destination_organization_id' => 'destination'] as $column => $role) {
                if ($e->{$column}) {
                    $roles[$e->{$column}][$role] = true;
                }
            }
        }
        $names = DB::table('organizations')->whereIn('id', array_keys($roles))->get(['id', 'name', 'type'])->keyBy('id');

        return collect($roles)->map(fn ($r, $id) => ['organization_id' => $id, 'name' => $names[$id]->name ?? null, 'type' => $names[$id]->type ?? null,
            'roles' => array_keys($r)])->values()->all();
    }

    private function assertOwn(TraceEvent $event): void
    {
        if ($event->organization_id !== $this->tenant->id()) {
            throw new BusinessRuleViolation('trace_event_not_yours', 'Only the organization that recorded an event can correct or reverse it.', 403);
        }
        if (in_array($event->event_type, ['reversal'], true)) {
            throw new BusinessRuleViolation('trace_event_not_correctable', 'A reversal cannot itself be corrected.', 409);
        }
    }
}
