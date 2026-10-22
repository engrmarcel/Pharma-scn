<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\Models\DetailingMessage;
use App\Domain\Crm\Models\Opportunity;
use App\Domain\Crm\Models\SalesTarget;
use App\Domain\Crm\Models\VisitPlan;
use App\Domain\Crm\Models\VisitPlanItem;
use App\Domain\Crm\Services\CrmAutomation;
use App\Domain\Crm\Services\HandoverService;
use App\Domain\Crm\Services\OpportunityService;
use App\Domain\Crm\Services\TargetService;
use App\Domain\Crm\Services\VisitPlanService;
use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Domain\Parties\Models\Customer;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * CRM planning and selling: visit plans and approvals, targets, opportunities,
 * follow-up automation and representative handover.
 */
class CrmPlanningController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FieldForceScope $scope,
    ) {}

    // ---- Visit plans ---------------------------------------------------------------------------

    public function plans(Request $request, VisitPlanService $plans): JsonResponse
    {
        abort_unless($this->isFieldUser(), 403);
        $f = $request->validate(['representative_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['draft', 'submitted', 'approved', 'rejected', 'cancelled'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $page = $this->scope->scopeRepresentatives(VisitPlan::query())
            ->when($f['representative_id'] ?? null, fn ($q, $v) => $q->where('representative_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('period_end', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('period_start', '<=', $v))
            ->orderByDesc('period_start')->paginate(50);

        return response()->json($page->through(fn (VisitPlan $p) => $this->plan($p, $plans, false)));
    }

    public function showPlan(string $id, VisitPlanService $plans): JsonResponse
    {
        return response()->json(['data' => $this->plan($this->findPlan($id), $plans)]);
    }

    private function plan(VisitPlan $plan, VisitPlanService $plans, bool $items = true): array
    {
        $own = $plan->representative_id === Auth::id();
        $data = $plan->only(['id', 'representative_id', 'status', 'notes', 'decided_by', 'decision_notes']) + [
            'period_start' => $plan->period_start->toDateString(), 'period_end' => $plan->period_end->toDateString(),
            'submitted_at' => $plan->submitted_at?->toIso8601String(), 'decided_at' => $plan->decided_at?->toIso8601String(),
            'adherence' => $plans->adherence($plan),
        ];
        if ($items) {
            // A supervisor sees only the calls on customers within their own reach.
            $all = $plan->items()->get();
            $visible = $own ? $all : $all->filter(fn ($i) => $this->scope->customerIsVisible($i->customer_id))->values();
            $data['items'] = $visible->map(fn (VisitPlanItem $i) => $i->only(['id', 'customer_id', 'sequence', 'objective', 'status', 'field_visit_id', 'cancel_reason'])
                + ['planned_date' => $i->planned_date->toDateString()]);
            $data['hidden_items'] = $all->count() - $visible->count();
        }

        return $data;
    }

    public function storePlan(Request $request, VisitPlanService $plans): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $plan = $plans->create($request->validate($this->planRules(true)));

        return response()->json(['data' => $this->plan($plan, $plans)], 201);
    }

    public function updatePlan(Request $request, string $id, VisitPlanService $plans): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');

        return response()->json(['data' => $this->plan($plans->update($this->findPlan($id), $request->validate($this->planRules(false))), $plans)]);
    }

    public function planAction(Request $request, string $id, string $action, VisitPlanService $plans): JsonResponse
    {
        $plan = $this->findPlan($id);
        $result = match ($action) {
            'submit' => $plans->submit($plan),
            'approve', 'reject' => $plans->decide($plan, $action === 'approve', $request->validate(['notes' => ['nullable', 'string', 'max:2000']])['notes'] ?? null, $request->user()),
            'cancel' => $plans->cancel($plan, $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']),
        };

        return response()->json(['data' => $this->plan($result, $plans)]);
    }

    public function orderRoute(Request $request, string $id, VisitPlanService $plans): JsonResponse
    {
        $d = $request->validate(['date' => ['required', 'date'], 'start_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:start_longitude'],
            'start_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:start_latitude']]);

        return response()->json(['data' => $plans->orderRoute($this->findPlan($id), $d['date'],
            isset($d['start_latitude']) ? (float) $d['start_latitude'] : null, isset($d['start_longitude']) ? (float) $d['start_longitude'] : null)]);
    }

    public function cancelPlanItem(Request $request, string $id, VisitPlanService $plans): JsonResponse
    {
        $item = VisitPlanItem::query()->findOrFail($id);
        $this->findPlan($item->visit_plan_id);

        return response()->json(['data' => $plans->cancelItem($item, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'])]);
    }

    public function suggestions(Request $request, VisitPlanService $plans): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return response()->json(['data' => $plans->suggestions($d['from'], $d['to'])]);
    }

    /**
     * The representative's day for the mobile app: planned calls (with customer and route
     * order), open follow-ups due by that day, and the approved key messages to cache offline.
     */
    public function myDay(Request $request): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? today()->toDateString();

        $calls = VisitPlanItem::query()->join('visit_plans as p', 'p.id', '=', 'visit_plan_items.visit_plan_id')
            ->join('customers as c', 'c.id', '=', 'visit_plan_items.customer_id')
            ->where('p.representative_id', Auth::id())->whereIn('p.status', ['submitted', 'approved'])
            ->where('visit_plan_items.planned_date', $date)->where('visit_plan_items.status', '!=', 'cancelled')
            ->orderBy('visit_plan_items.sequence')
            ->get(['visit_plan_items.id', 'visit_plan_items.customer_id', 'c.name as customer_name', 'c.city', 'visit_plan_items.sequence',
                'visit_plan_items.objective', 'visit_plan_items.status', 'p.status as plan_status', 'p.id as visit_plan_id']);

        return response()->json(['data' => [
            'date' => $date,
            'calls' => $calls,
            'follow_ups' => FieldFollowUp::query()->where('assigned_to', Auth::id())->where('status', 'open')
                ->where('due_on', '<=', $date)->orderBy('due_on')->get(['id', 'customer_id', 'title', 'due_on', 'source']),
            'detailing_messages' => DetailingMessage::query()->where('status', 'approved')
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date))
                ->get(['id', 'product_id', 'title', 'key_message', 'valid_until']),
        ]]);
    }

    // ---- Targets ---------------------------------------------------------------------------------------

    public function targets(Request $request, TargetService $targets): JsonResponse
    {
        abort_unless($this->isFieldUser(), 403);
        $f = $request->validate(['representative_id' => ['nullable', 'integer'], 'territory_id' => ['nullable', 'ulid'], 'on' => ['nullable', 'date']]);

        $rows = $targets->visible()
            ->when($f['representative_id'] ?? null, fn ($q, $v) => $q->where('representative_id', $v))
            ->when($f['territory_id'] ?? null, fn ($q, $v) => $q->where('territory_id', $v))
            ->when($f['on'] ?? null, fn ($q, $v) => $q->where('period_start', '<=', $v)->where('period_end', '>=', $v))
            ->orderByDesc('period_start')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (SalesTarget $t) => $this->target($t, $targets))]);
    }

    public function storeTarget(Request $request, TargetService $targets): JsonResponse
    {
        Gate::authorize('crm.targets.manage');
        $data = $request->validate([
            'scope_type' => ['required', Rule::in(['representative', 'territory'])],
            'representative_id' => ['required_if:scope_type,representative', 'nullable', 'integer'],
            'territory_id' => ['required_if:scope_type,territory', 'nullable', 'ulid'],
            'metric' => ['required', Rule::in(SalesTarget::METRICS)],
            'product_id' => ['nullable', 'ulid', Rule::exists('organization_products', 'product_id')->where('organization_id', $this->tenant->id())],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'target_value' => ['required', 'decimal:0,4', 'gt:0', 'max:999999999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $this->target($targets->create($data, $request->user()), $targets)], 201);
    }

    public function showTarget(string $id, TargetService $targets): JsonResponse
    {
        abort_unless($this->isFieldUser(), 403);

        return response()->json(['data' => $this->target($targets->visible()->findOrFail($id), $targets)]);
    }

    private function target(SalesTarget $target, TargetService $targets): array
    {
        return $target->only(['id', 'scope_type', 'representative_id', 'territory_id', 'metric', 'product_id', 'notes']) + [
            'period_start' => $target->period_start->toDateString(), 'period_end' => $target->period_end->toDateString(),
            'target_value' => (string) $target->target_value, 'achievement' => $targets->achievement($target),
        ];
    }

    public function destroyTarget(string $id, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('crm.targets.manage');
        $target = SalesTarget::query()->findOrFail($id);
        $audit->record('sales_target.deleted', $target, $target->only(['scope_type', 'representative_id', 'territory_id', 'metric', 'target_value']));
        $target->delete();

        return response()->json(null, 204);
    }

    // ---- Opportunities ------------------------------------------------------------------------------------

    public function opportunities(Request $request, OpportunityService $service): JsonResponse
    {
        abort_unless($this->isFieldUser(), 403);
        $f = $request->validate(['stage' => ['nullable', Rule::in(Opportunity::STAGES)], 'owner_id' => ['nullable', 'integer'], 'customer_id' => ['nullable', 'ulid'],
            'open' => ['nullable', 'boolean']]);
        $base = fn () => $service->visible()
            ->when($f['owner_id'] ?? null, fn ($q, $v) => $q->where('owner_id', $v))
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v));

        $page = $base()->when($f['stage'] ?? null, fn ($q, $v) => $q->where('stage', $v))
            ->when($f['open'] ?? false, fn ($q) => $q->whereIn('stage', Opportunity::OPEN_STAGES))
            ->orderByDesc('last_activity_at')->paginate(50);
        $pipeline = $base()->toBase()->groupBy('stage')
            ->selectRaw('stage, COUNT(*) as count, COALESCE(SUM(expected_value), 0) as value, COALESCE(SUM(expected_value * probability / 100), 0) as weighted')
            ->get()->keyBy('stage');

        return response()->json($page->toArray() + ['pipeline' => collect(Opportunity::STAGES)->mapWithKeys(fn ($s) => [$s => [
            'count' => (int) ($pipeline[$s]->count ?? 0), 'value' => bcadd((string) ($pipeline[$s]->value ?? '0'), '0', 4),
            'weighted_value' => bcadd((string) ($pipeline[$s]->weighted ?? '0'), '0', 4)]])]);
    }

    public function opportunity(string $id, OpportunityService $service): JsonResponse
    {
        abort_unless($this->isFieldUser(), 403);

        return response()->json(['data' => $service->visible()->findOrFail($id)]);
    }

    public function storeOpportunity(Request $request, OpportunityService $service): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $result = $service->create($request->validate($this->opportunityRules() + [
            'client_reference' => ['nullable', 'string', 'max:64'],
            'customer_id' => ['nullable', 'ulid'],
            'title' => ['required', 'string', 'max:255'],
            'stage' => ['nullable', Rule::in(Opportunity::OPEN_STAGES)],
            'currency_code' => ['nullable', 'string', 'size:3'],
        ]));

        return response()->json(['data' => $result['opportunity'], 'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function updateOpportunity(Request $request, string $id, OpportunityService $service): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $opportunity = $service->visible()->findOrFail($id);

        return response()->json(['data' => $service->update($opportunity, $request->validate($this->opportunityRules() + [
            'title' => ['sometimes', 'string', 'max:255'],
            'stage' => ['sometimes', Rule::in(Opportunity::STAGES)],
            'lost_reason' => ['nullable', 'string', 'max:255'],
            'field_order_id' => ['nullable', 'ulid'],
            'sales_order_id' => ['nullable', 'ulid'],
        ]))]);
    }

    public function convertLead(Request $request, string $id, OpportunityService $service): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32', 'alpha_dash'], 'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(Customer::TYPES)]]);

        return response()->json(['data' => $service->convertLead($service->visible()->findOrFail($id), $data)]);
    }

    // ---- Automation and handover ----------------------------------------------------------------------------

    public function automationSettings(CrmAutomation $automation): JsonResponse
    {
        Gate::authorize('crm.automation.manage');

        return response()->json(['data' => $automation->settings()]);
    }

    public function updateAutomationSettings(Request $request, CrmAutomation $automation, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('crm.automation.manage');
        $data = $request->validate(['missed_plan_items' => ['sometimes', 'boolean'], 'overdue_visits' => ['sometimes', 'boolean'],
            'stale_opportunity_days' => ['sometimes', 'nullable', 'integer', 'between:1,365'], 'escalation_days' => ['sometimes', 'nullable', 'integer', 'between:0,90']]);
        $org = $this->tenant->organization();
        $before = $automation->settings();
        $settings = $org->settings ?? [];
        data_set($settings, 'crm.automation', array_replace(data_get($settings, 'crm.automation', []), $data));
        $org->forceFill(['settings' => $settings])->save();
        $audit->record('crm.automation_settings_changed', $org, $before, $data);

        return response()->json(['data' => $automation->settings()]);
    }

    public function runAutomation(CrmAutomation $automation): JsonResponse
    {
        Gate::authorize('crm.automation.manage');

        return response()->json(['data' => $automation->run()]);
    }

    public function handover(Request $request, HandoverService $handover): JsonResponse
    {
        Gate::authorize('field_force.territories.manage');
        $d = $request->validate(['from_user_id' => ['required', 'integer'], 'to_user_id' => ['required', 'integer'],
            'territory_id' => ['nullable', 'ulid', Rule::exists('territories', 'id')->where('organization_id', $this->tenant->id())]]);

        return response()->json(['data' => $handover->handover((int) $d['from_user_id'], (int) $d['to_user_id'], $d['territory_id'] ?? null)]);
    }

    // ---- Helpers ---------------------------------------------------------------------------------------------

    private function findPlan(string $id): VisitPlan
    {
        abort_unless($this->isFieldUser(), 403);

        return $this->scope->scopeRepresentatives(VisitPlan::query())->findOrFail($id);
    }

    private function isFieldUser(): bool
    {
        foreach (['field_force.visits.capture', 'field_force.team.view', 'field_force.view_all', 'field_force.reports.view', 'crm.plans.approve', 'crm.targets.manage'] as $ability) {
            if (Gate::allows($ability)) {
                return true;
            }
        }

        return false;
    }

    private function planRules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';

        return [
            'period_start' => [$required, 'date'],
            'period_end' => [$required, 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => [$create ? 'present' : 'sometimes', 'array', 'max:300'],
            'items.*.customer_id' => ['required', 'ulid'],
            'items.*.planned_date' => ['required', 'date'],
            'items.*.sequence' => ['nullable', 'integer', 'between:0,999'],
            'items.*.objective' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function opportunityRules(): array
    {
        return [
            'lead_name' => ['nullable', 'string', 'max:255'],
            'lead_contact_name' => ['nullable', 'string', 'max:255'],
            'lead_phone' => ['nullable', 'string', 'max:32'],
            'lead_email' => ['nullable', 'email', 'max:255'],
            'lead_city' => ['nullable', 'string', 'max:100'],
            'territory_id' => ['nullable', 'ulid'],
            'expected_value' => ['nullable', 'decimal:0,4', 'min:0', 'max:999999999999999'],
            'probability' => ['nullable', 'integer', 'between:0,100'],
            'expected_close_date' => ['nullable', 'date'],
            'next_step' => ['nullable', 'string', 'max:255'],
            'next_step_date' => ['nullable', 'date'],
            'product_ids' => ['nullable', 'array', 'max:50'],
            'product_ids.*' => ['ulid', Rule::exists('organization_products', 'product_id')->where('organization_id', $this->tenant->id())],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
