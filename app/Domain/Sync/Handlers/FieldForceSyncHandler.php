<?php

namespace App\Domain\Sync\Handlers;

use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\FieldForce\Models\FieldOrder;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Domain\FieldForce\Services\FieldOrderService;
use App\Domain\FieldForce\Services\FollowUpService;
use App\Domain\FieldForce\Services\VisitService;
use App\Domain\FieldForce\Support\FieldForceRules;
use App\Domain\Sync\Models\SyncInboxItem;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Field-force operations arriving through the sync inbox. They run through
 * the same services and validation as the online endpoints. Records are
 * referenced by their device client_reference, so a device never needs a
 * server id before it can send the next step.
 */
class FieldForceSyncHandler
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FieldForceScope $scope,
        private readonly VisitService $visits,
        private readonly FieldOrderService $orders,
        private readonly FollowUpService $followUps,
    ) {}

    public function handle(SyncInboxItem $item): array
    {
        $p = $item->payload;
        $org = $this->tenant->id();

        return match ($item->type) {
            'field.visit.check_in' => $this->visit($this->visits->checkIn($this->valid('field_force.visits.capture', $p, FieldForceRules::checkIn()))['visit']),
            'field.visit.check_out' => $this->visit($this->visits->checkOut(
                $this->ownVisit($p['visit_client_reference'] ?? null),
                $this->valid('field_force.visits.capture', $p, FieldForceRules::checkOut($org) + ['visit_client_reference' => ['required', 'string', 'max:64']]),
            )),
            'field.visit.cancel' => $this->visit($this->visits->cancel(
                $this->ownVisit($p['visit_client_reference'] ?? null),
                $this->valid('field_force.visits.capture', $p, ['visit_client_reference' => ['required', 'string'], 'reason' => ['required', 'string', 'max:2000']])['reason'],
            )),
            'field.order.capture' => $this->captureOrder($p, $org),
            'field.order.resubmit' => $this->resubmitOrder($p, $org),
            'field.follow_up.complete' => $this->completeFollowUp($p),
        };
    }

    private function valid(string $ability, array $payload, array $rules): array
    {
        Gate::authorize($ability);

        return Validator::make($payload, $rules)->validate();
    }

    private function ownVisit(?string $reference): FieldVisit
    {
        return FieldVisit::query()->where('client_reference', (string) $reference)->where('representative_id', Auth::id())->first()
            ?? throw new BusinessRuleViolation('visit_not_found', 'The visit this step belongs to was not found on the server.', 404);
    }

    private function visit(FieldVisit $v): array
    {
        return $v->only(['id', 'number', 'client_reference', 'status', 'check_in_verification', 'check_out_verification', 'check_in_distance_m', 'check_out_distance_m']);
    }

    private function captureOrder(array $p, string $org): array
    {
        $data = $this->valid('field_force.orders.capture', $p, FieldForceRules::orderCapture($org) + ['field_visit_client_reference' => ['nullable', 'string', 'max:64']]);
        if (filled($data['field_visit_client_reference'] ?? null)) {
            // The visit may itself have been refused; the order still goes through without the link.
            $data['field_visit_id'] = FieldVisit::query()->where('client_reference', $data['field_visit_client_reference'])->where('representative_id', Auth::id())->value('id');
        }

        return $this->orders->present($this->orders->capture($data)['order']);
    }

    private function resubmitOrder(array $p, string $org): array
    {
        $data = $this->valid('field_force.orders.capture', $p, FieldForceRules::order($org, false) + ['order_client_reference' => ['required', 'string', 'max:64']]);
        $order = FieldOrder::query()->where('client_reference', $data['order_client_reference'])->where('representative_id', Auth::id())->first()
            ?? throw new BusinessRuleViolation('field_order_not_found', 'The order was not found on the server.', 404);
        if ($order->status !== 'accepted') {
            $this->orders->update($order, $data);
        }

        return $this->orders->present($this->orders->submit($order));
    }

    private function completeFollowUp(array $p): array
    {
        $data = $this->valid('field_force.visits.capture', $p, ['follow_up_id' => ['required', 'ulid'], 'outcome' => ['required', 'string', 'max:5000']]);
        $followUp = $this->scope->scopeActivity(FieldFollowUp::query(), 'assigned_to')->find($data['follow_up_id'])
            ?? throw new BusinessRuleViolation('follow_up_not_found', 'The follow-up was not found.', 404);
        if ($followUp->status === 'done') {
            return $followUp->only(['id', 'status']); // already completed (for example from another device)
        }

        return $this->followUps->close($followUp, 'done', $data['outcome'])->only(['id', 'status']);
    }
}
