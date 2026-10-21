<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Models\TradingRelationship;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Buyer/supplier relationships. A buyer asks to trade; the supplier approves
 * or rejects; either side can suspend. Each side's ERP party record is
 * created by that side, in its own tenant: the buyer gets a Supplier when it
 * asks, the supplier gets a Customer when it approves. Both are linked to
 * the counterpart organization (linked_organization_id), which gives no
 * access to the other side's data.
 */
class RelationshipService
{
    private const SUPPLIER_TYPES = ['manufacturer' => 'manufacturer', 'importer' => 'importer', 'national_distributor' => 'distributor', 'regional_distributor' => 'distributor', 'wholesaler' => 'wholesaler'];

    private const CUSTOMER_TYPES = ['pharmacy' => 'pharmacy', 'pharmacy_chain' => 'pharmacy', 'hospital' => 'hospital', 'healthcare_facility' => 'healthcare_facility',
        'wholesaler' => 'wholesaler', 'national_distributor' => 'distributor', 'regional_distributor' => 'distributor', 'importer' => 'distributor', 'institutional_procurement' => 'institution'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MarketplaceAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function request(Organization $supplier, ?string $message): TradingRelationship
    {
        $buyer = $this->tenant->organization();
        $this->access->assertParticipates($buyer);
        $this->access->assertParticipates($supplier, 'The supplier');
        if ($block = $this->access->tradeBlock($buyer, $supplier)) {
            throw new BusinessRuleViolation($block['code'], $block['message'], 403);
        }

        return DB::transaction(function () use ($buyer, $supplier, $message) {
            $relationship = TradingRelationship::query()->where('buyer_organization_id', $buyer->id)->where('supplier_organization_id', $supplier->id)->lockForUpdate()->first();
            if ($relationship !== null && in_array($relationship->status, ['requested', 'approved', 'suspended'], true)) {
                throw new BusinessRuleViolation('relationship_exists', "A relationship with {$supplier->name} is already {$relationship->status}.", 409);
            }

            $party = Supplier::query()->where('linked_organization_id', $supplier->id)->first()
                ?? Supplier::query()->create([
                    'code' => $this->code(Supplier::class, $supplier),
                    'name' => $supplier->name,
                    'type' => self::SUPPLIER_TYPES[$supplier->type->value] ?? 'other',
                    'linked_organization_id' => $supplier->id,
                    'currency_code' => $supplier->country->currency_code,
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                    'country_code' => $supplier->country_code,
                ]);

            $relationship ??= new TradingRelationship(['buyer_organization_id' => $buyer->id, 'supplier_organization_id' => $supplier->id]);
            $relationship->forceFill(['status' => 'requested', 'requested_by' => Auth::id(), 'request_message' => $message, 'buyer_supplier_id' => $party->id,
                'decided_by' => null, 'decided_at' => null, 'decision_notes' => null])->save();
            $this->audit->record('trading_relationship.requested', $party, new: ['relationship_id' => $relationship->id, 'supplier' => $supplier->name]);

            return $relationship;
        });
    }

    public function decide(TradingRelationship $relationship, bool $approve, ?string $notes, int $paymentTermsDays = 0): TradingRelationship
    {
        $supplier = $this->tenant->organization();

        return DB::transaction(function () use ($relationship, $approve, $notes, $paymentTermsDays, $supplier) {
            $locked = TradingRelationship::query()->lockForUpdate()->findOrFail($relationship->id);
            if ($locked->supplier_organization_id !== $supplier->id) {
                throw new BusinessRuleViolation('relationship_not_yours', 'Only the supplier decides on this request.', 403);
            }
            if (! in_array($locked->status, ['requested', 'suspended'], true)) {
                throw new BusinessRuleViolation('relationship_state_invalid', "The relationship is {$locked->status}.", 409);
            }
            if (! $approve && blank($notes)) {
                throw new BusinessRuleViolation('decision_notes_required', 'Give a reason when rejecting.', 422);
            }

            $customerId = $locked->supplier_customer_id;
            if ($approve) {
                $buyer = Organization::query()->findOrFail($locked->buyer_organization_id);
                $customer = Customer::query()->where('linked_organization_id', $buyer->id)->first()
                    ?? Customer::query()->create([
                        'code' => $this->code(Customer::class, $buyer),
                        'name' => $buyer->name,
                        'type' => self::CUSTOMER_TYPES[$buyer->type->value] ?? 'other',
                        'linked_organization_id' => $buyer->id,
                        'currency_code' => $supplier->country->currency_code,
                        'payment_terms_days' => $paymentTermsDays,
                        'email' => $buyer->email,
                        'phone' => $buyer->phone,
                        'address_line1' => $buyer->address_line1,
                        'city' => $buyer->city,
                        'state_region' => $buyer->state_region,
                        'country_code' => $buyer->country_code,
                    ]);
                $customerId = $customer->id;
            }

            $locked->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_notes' => $notes,
                'payment_terms_days' => $paymentTermsDays, 'supplier_customer_id' => $customerId])->save();
            $this->audit->record($approve ? 'trading_relationship.approved' : 'trading_relationship.rejected', $customerId ? Customer::query()->find($customerId) : null,
                new: ['relationship_id' => $locked->id, 'notes' => $notes]);

            return $locked;
        });
    }

    public function suspend(TradingRelationship $relationship, string $reason): TradingRelationship
    {
        return DB::transaction(function () use ($relationship, $reason) {
            $locked = TradingRelationship::query()->involving($this->tenant->id())->lockForUpdate()->findOrFail($relationship->id);
            if ($locked->status !== 'approved') {
                throw new BusinessRuleViolation('relationship_state_invalid', "The relationship is {$locked->status}.", 409);
            }
            $locked->forceFill(['status' => 'suspended', 'decision_notes' => $reason, 'decided_by' => Auth::id(), 'decided_at' => now()])->save();
            $this->audit->record('trading_relationship.suspended', null, new: ['relationship_id' => $locked->id, 'reason' => $reason]);

            return $locked;
        });
    }

    /** The approved relationship between two organizations, or a refusal explaining why not. */
    public function approved(string $buyerId, string $supplierId): TradingRelationship
    {
        $relationship = TradingRelationship::query()->where('buyer_organization_id', $buyerId)->where('supplier_organization_id', $supplierId)->first();
        if ($relationship?->status !== 'approved') {
            throw new BusinessRuleViolation('relationship_not_approved', 'Trade with this supplier needs an approved relationship.', 403, ['status' => $relationship?->status]);
        }

        return $relationship;
    }

    /** A stable party code such as MP-1A2B3C4D (unique in the acting organization). */
    private function code(string $model, Organization $counterpart): string
    {
        $base = 'MP-'.strtoupper(substr($counterpart->id, -8));
        $code = $base;
        for ($i = 2; $model::query()->where('code', $code)->exists(); $i++) {
            $code = "{$base}-{$i}";
        }

        return $code;
    }
}
