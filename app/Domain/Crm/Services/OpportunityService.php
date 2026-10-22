<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Opportunity;
use App\Domain\FieldForce\Models\FieldOrder;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\PartyContact;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Leads and opportunities (spec §17: leads and opportunities, order conversion).
 *
 * A lead is a prospect without a customer record; converting it creates the
 * customer (moving the lead's contact into a customer contact) and keeps the
 * opportunity. An opportunity is won only with the order that came of it, a
 * mobile order or an ERP sales order for the same customer, so conversion
 * figures always rest on real orders. Lost needs a reason.
 */
class OpportunityService
{
    private const EDITABLE = ['title', 'expected_value', 'probability', 'expected_close_date', 'next_step', 'next_step_date', 'product_ids', 'notes', 'territory_id',
        'lead_name', 'lead_contact_name', 'lead_phone', 'lead_email', 'lead_city'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FieldForceScope $scope,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** Own opportunities, and the team's where the customer (or, for leads, the territory) is within the viewer's reach. */
    public function visible(): Builder
    {
        $query = Opportunity::query();
        $team = $this->scope->visibleRepresentativeIds();
        if ($team === null) {
            return $query;
        }
        $territories = $this->scope->territoryIds();

        return $query->where(fn ($q) => $q->where('owner_id', Auth::id())->orWhere(fn ($w) => $w->whereIn('owner_id', $team)->where(fn ($v) => $v
            ->where(fn ($c) => $this->scope->scopeCustomers($c->whereNotNull('customer_id')))
            ->orWhere(fn ($l) => $l->whereNull('customer_id')->whereIn('territory_id', $territories)))));
    }

    /** @return array{opportunity: Opportunity, replayed: bool} */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            if (! empty($data['client_reference']) && ($existing = Opportunity::query()->where('client_reference', $data['client_reference'])->first())) {
                if ($existing->owner_id !== Auth::id()) {
                    throw new BusinessRuleViolation('client_reference_conflict', 'This client reference is already used by another record.', 409);
                }

                return ['opportunity' => $existing, 'replayed' => true];
            }
            $customer = isset($data['customer_id']) ? $this->scope->visibleCustomer($data['customer_id']) : null;
            if ($customer === null && blank($data['lead_name'] ?? null)) {
                throw new BusinessRuleViolation('lead_name_required', 'Give either a customer or the name of the prospect.', 422);
            }
            $this->assertTerritory($data['territory_id'] ?? null);
            $stage = $data['stage'] ?? 'lead';

            $opportunity = Opportunity::query()->create(Arr::only($data, self::EDITABLE) + [
                'client_reference' => $data['client_reference'] ?? null,
                'number' => $this->numbers->next('OPP'),
                'customer_id' => $customer?->id,
                'territory_id' => $data['territory_id'] ?? $customer?->territory_id,
                'stage' => $stage,
                'probability' => $data['probability'] ?? Opportunity::PROBABILITY[$stage],
                'owner_id' => Auth::id(),
                'currency_code' => $data['currency_code'] ?? $customer?->currency_code ?? $this->tenant->organization()->country->currency_code,
                'last_activity_at' => now(),
            ]);
            $this->audit->record('opportunity.created', $opportunity, new: ['number' => $opportunity->number, 'customer_id' => $customer?->id, 'stage' => $stage]);

            return ['opportunity' => $opportunity, 'replayed' => false];
        });
    }

    public function update(Opportunity $opportunity, array $data): Opportunity
    {
        return DB::transaction(function () use ($opportunity, $data) {
            $locked = $this->owned($opportunity);
            $this->assertTerritory($data['territory_id'] ?? null);
            $original = $locked->getAttributes();
            $locked->fill(Arr::only($data, self::EDITABLE));

            if (isset($data['stage']) && $data['stage'] !== $locked->stage) {
                $this->moveStage($locked, $data);
            }
            $locked->last_activity_at = now();
            $locked->save();
            [$old, $new] = $this->audit->diff($locked, $original);
            unset($new['last_activity_at'], $old['last_activity_at']);
            $this->audit->record('opportunity.updated', $locked, $old, $new);

            return $locked;
        });
    }

    /** Lead → customer. The lead's contact becomes a customer contact (consent unknown) and leaves the opportunity. */
    public function convertLead(Opportunity $opportunity, array $data): Opportunity
    {
        Gate::authorize('customers.manage');

        return DB::transaction(function () use ($opportunity, $data) {
            $locked = $this->visible()->lockForUpdate()->findOrFail($opportunity->id);
            if ($locked->customer_id !== null) {
                throw new BusinessRuleViolation('opportunity_has_customer', 'This opportunity already has a customer.', 409);
            }
            if (! $locked->isOpen()) {
                throw new BusinessRuleViolation('opportunity_closed', 'Closed opportunities cannot be converted.', 409);
            }
            if (Customer::query()->where('code', $data['code'])->exists()) {
                throw new BusinessRuleViolation('customer_code_taken', "Customer code {$data['code']} is already used.", 409);
            }

            $customer = Customer::query()->create(['code' => $data['code'], 'name' => $data['name'] ?? $locked->lead_name, 'type' => $data['type'],
                'city' => $locked->lead_city, 'currency_code' => $locked->currency_code, 'territory_id' => $locked->territory_id,
                'assigned_representative_id' => $locked->owner_id]);
            if (filled($locked->lead_contact_name)) {
                PartyContact::query()->create(['party_type' => 'customer', 'party_id' => $customer->id, 'name' => $locked->lead_contact_name,
                    'email' => $locked->lead_email, 'phone' => $locked->lead_phone, 'is_primary' => true]);
            }
            $locked->forceFill(['customer_id' => $customer->id, 'lead_contact_name' => null, 'lead_phone' => null, 'lead_email' => null,
                'stage' => $locked->stage === 'lead' ? 'qualified' : $locked->stage, 'last_activity_at' => now()])->save();
            $this->audit->record('opportunity.lead_converted', $locked, new: ['customer_id' => $customer->id, 'customer_code' => $customer->code]);

            return $locked;
        });
    }

    /** Won needs the resulting order; lost needs a reason. */
    private function moveStage(Opportunity $o, array $data): void
    {
        $stage = $data['stage'];
        if ($stage === 'won') {
            if ($o->customer_id === null) {
                throw new BusinessRuleViolation('opportunity_needs_customer', 'Convert the lead to a customer before closing it as won.', 422);
            }
            if (! empty($data['field_order_id'])) {
                $order = $this->scope->scopeActivity(FieldOrder::query())->whereKey($data['field_order_id'])->where('customer_id', $o->customer_id)
                    ->whereIn('status', ['submitted', 'accepted'])->first();
                if ($order === null) {
                    throw new BusinessRuleViolation('opportunity_order_invalid', 'The mobile order must be a submitted or accepted order for this customer.', 422);
                }
                $o->field_order_id = $order->id;
                $o->sales_order_id = $order->sales_order_id;
            } elseif (! empty($data['sales_order_id'])) {
                $exists = DB::table('sales_orders')->where('organization_id', $o->organization_id)->where('id', $data['sales_order_id'])
                    ->where('customer_id', $o->customer_id)->whereNotIn('status', ['draft', 'cancelled'])->exists();
                if (! $exists) {
                    throw new BusinessRuleViolation('opportunity_order_invalid', 'The sales order must be a confirmed order for this customer.', 422);
                }
                $o->sales_order_id = $data['sales_order_id'];
            } else {
                throw new BusinessRuleViolation('opportunity_order_required', 'An opportunity is won with the order that came of it: give field_order_id or sales_order_id.', 422);
            }
        }
        if ($stage === 'lost' && blank($data['lost_reason'] ?? null)) {
            throw new BusinessRuleViolation('lost_reason_required', 'Say why the opportunity was lost.', 422);
        }
        $o->stage = $stage;
        $o->lost_reason = $stage === 'lost' ? $data['lost_reason'] : null;
        $o->closed_at = in_array($stage, ['won', 'lost'], true) ? now() : null;
        if (! isset($data['probability']) || ! $o->isOpen()) {
            $o->probability = Opportunity::PROBABILITY[$stage];
        }
    }

    private function owned(Opportunity $opportunity): Opportunity
    {
        $locked = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
        if ($locked->owner_id !== Auth::id()) {
            throw new BusinessRuleViolation('opportunity_not_yours', 'Only the owner can change this opportunity.', 403);
        }
        if (! $locked->isOpen()) {
            throw new BusinessRuleViolation('opportunity_closed', "A {$locked->stage} opportunity cannot be changed.", 409);
        }

        return $locked;
    }

    private function assertTerritory(?string $territoryId): void
    {
        if ($territoryId !== null && ! DB::table('territories')->where('organization_id', $this->tenant->id())->where('id', $territoryId)->exists()) {
            throw new BusinessRuleViolation('territory_not_found', 'Territory not found.', 422);
        }
    }
}
