<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\Recommendation;
use App\Domain\Inventory\Services\LocationAccess;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * People's decisions on recommendations (spec §19.2: "do not let an AI model independently
 * authorize purchases, alter inventory, approve financial transactions or override
 * regulatory restrictions").
 *
 * - Viewing needs analytics.view and the type's area permission; warehouse-bound
 *   recommendations follow the member's locations.
 * - Accepting a reorder creates a DRAFT purchase requisition as the accepting person, who
 *   needs procurement.requisition and access to the receiving warehouse. Submission,
 *   approval and ordering follow the normal procurement flow and its approval rules.
 * - Accepting any other type records the decision only; nothing else changes.
 */
class RecommendationService
{
    public function __construct(
        private readonly LocationAccess $locations,
        private readonly PurchaseOrderService $orders,
        private readonly AuditLogger $audit,
    ) {}

    public function visible(): Builder
    {
        Gate::authorize('analytics.view');
        $types = array_keys(array_filter(Recommendation::VIEW_PERMISSION, fn ($permission) => Gate::allows($permission)));
        $warehouses = $this->locations->warehouseIds();

        return Recommendation::query()->whereIn('type', $types ?: [''])
            ->when($warehouses !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('warehouse_id')->orWhereIn('warehouse_id', $warehouses)));
    }

    /** @param array{notes?: ?string, warehouse_id?: ?string, quantity?: ?string, needed_by?: ?string} $data */
    public function accept(Recommendation $rec, User $actor, array $data): Recommendation
    {
        $this->assertOpen($rec);

        return DB::transaction(function () use ($rec, $actor, $data) {
            $action = [null, null];
            if ($rec->type === 'reorder') {
                Gate::authorize('procurement.requisition');
                $warehouseId = $data['warehouse_id'] ?? $rec->warehouse_id
                    ?? throw new BusinessRuleViolation('warehouse_required', 'Choose the warehouse that will receive the order.', 422);
                $this->locations->assertWarehouse($warehouseId);
                $quantity = (string) ($data['quantity'] ?? $rec->figures['suggested_quantity']);
                $requisition = $this->orders->createRequisition(Warehouse::query()->findOrFail($warehouseId), [[
                    'product_id' => $rec->product_id, 'quantity' => $quantity, 'suggested_supplier_id' => $rec->figures['suggested_supplier_id'] ?? null,
                    'notes' => 'From recommendation (machine-generated); quantity in base units',
                ]], $data['needed_by'] ?? null, trim("Created from recommendation: {$rec->title}. Review before submitting. ".($data['notes'] ?? '')));
                $action = ['purchase_requisition', $requisition->id];
            }

            $rec->forceFill(['status' => 'accepted', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_notes' => $data['notes'] ?? null,
                'action_type' => $action[0], 'action_id' => $action[1]])->save();
            $this->audit->record('recommendation.accepted', $rec, new: ['type' => $rec->type, 'action_type' => $action[0], 'action_id' => $action[1]]);

            return $rec;
        });
    }

    public function dismiss(Recommendation $rec, User $actor, string $reason): Recommendation
    {
        $this->assertOpen($rec);
        $rec->forceFill(['status' => 'dismissed', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_notes' => $reason])->save();
        $this->audit->record('recommendation.dismissed', $rec, new: ['type' => $rec->type, 'reason' => $reason]);

        return $rec;
    }

    private function assertOpen(Recommendation $rec): void
    {
        if ($rec->status !== 'open') {
            throw new BusinessRuleViolation('recommendation_not_open', "This recommendation is already {$rec->status}.", 409);
        }
    }
}
