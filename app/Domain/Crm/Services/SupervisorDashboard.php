<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\CustomerFeedback;
use App\Domain\Crm\Models\Opportunity;
use App\Domain\Crm\Models\SalesTarget;
use App\Domain\Crm\Models\VisitPlan;
use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\FieldForce\Models\FieldOrder;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Support\BusinessCalendar;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Supervisor dashboard (spec Phase 6): one day of the team's field work and what
 * needs the supervisor's attention, entirely within the supervisor's activity scope
 * (ADR-0014). Plans awaiting approval are listed with whether this supervisor can
 * decide them.
 */
class SupervisorDashboard
{
    public function __construct(
        private readonly FieldForceScope $scope,
        private readonly VisitPlanService $plans,
        private readonly TargetService $targets,
        private readonly OpportunityService $opportunities,
        private readonly TenantContext $tenant,
    ) {}

    public function build(string $date): array
    {
        $day = Carbon::parse($date);
        $range = [app(BusinessCalendar::class)->dayStart($day), app(BusinessCalendar::class)->dayEnd($day)];
        $weekStart = $day->copy()->subDays(6)->toDateString();

        $visits = $this->scope->scopeActivity(FieldVisit::query())->whereBetween('check_in_at', $range)->get();
        $planned = $this->scope->scopeActivity(DB::table('visit_plan_items as i')->join('visit_plans as p', 'p.id', '=', 'i.visit_plan_id')
            ->where('i.organization_id', $this->tenantId())->whereIn('p.status', ['submitted', 'approved'])->where('i.status', '!=', 'cancelled'), 'p.representative_id', 'i.customer_id');
        $today = (clone $planned)->where('i.planned_date', $day->toDateString())->get(['p.representative_id', 'i.status']);
        $week = (clone $planned)->whereBetween('i.planned_date', [$weekStart, $day->toDateString()])->get(['p.representative_id', 'i.status', 'i.planned_date']);
        $orders = $this->scope->scopeActivity(FieldOrder::query(), 'field_orders.representative_id', 'field_orders.customer_id')->whereBetween('field_orders.captured_at', [$weekStart.' 00:00:00', $range[1]])
            ->leftJoin('sales_orders as so', 'so.id', '=', 'field_orders.sales_order_id')->get(['field_orders.representative_id', 'field_orders.status', 'so.total']);
        $overdue = $this->scope->scopeActivity(FieldFollowUp::query(), 'assigned_to')->where('status', 'open')->where('due_on', '<', $day->toDateString())->get(['assigned_to']);

        $repIds = collect([$visits->pluck('representative_id'), $today->pluck('representative_id'), $week->pluck('representative_id'),
            $orders->pluck('representative_id'), $overdue->pluck('assigned_to'), $this->teamIds()])->flatten()->unique()->filter()->values();
        $names = DB::table('users')->whereIn('id', $repIds)->pluck('name', 'id');

        $team = $repIds->map(function ($id) use ($visits, $today, $week, $orders, $overdue, $names, $day) {
            $v = $visits->where('representative_id', $id);
            $w = $week->where('representative_id', $id);
            $due = $w->filter(fn ($i) => $i->status === 'visited' || $i->planned_date < $day->toDateString() || $i->status === 'missed');
            $o = $orders->where('representative_id', $id);
            $last = $v->sortByDesc('check_in_at')->first();

            return [
                'representative_id' => (int) $id,
                'name' => $names[$id] ?? null,
                'planned_today' => $today->where('representative_id', $id)->count(),
                'visited_planned_today' => $today->where('representative_id', $id)->where('status', 'visited')->count(),
                'visits_today' => $v->count(),
                'in_progress' => $v->where('status', 'in_progress')->count(),
                'unverified_check_ins_today' => $v->where('check_in_verification', '!=', 'verified')->count(),
                'last_check_in_at' => $last?->check_in_at?->toIso8601String(),
                'last_check_in_verification' => $last?->check_in_verification,
                'plan_adherence_7d' => $due->count() > 0 ? round($due->where('status', 'visited')->count() / $due->count() * 100, 1) : null,
                'orders_7d' => $o->count(),
                'orders_accepted_7d' => $o->where('status', 'accepted')->count(),
                'accepted_value_7d' => bcadd((string) $o->where('status', 'accepted')->sum('total'), '0', 4),
                'overdue_follow_ups' => $overdue->where('assigned_to', $id)->count(),
            ];
        })->sortBy('name')->values();

        return [
            'date' => $day->toDateString(),
            'totals' => [
                'visits_today' => $visits->count(),
                'in_progress' => $visits->where('status', 'in_progress')->count(),
                'unverified_check_ins_today' => $visits->where('check_in_verification', '!=', 'verified')->count(),
                'planned_today' => $today->count(),
                'visited_planned_today' => $today->where('status', 'visited')->count(),
                'overdue_follow_ups' => $overdue->count(),
                'escalated_to_me' => FieldFollowUp::query()->where('assigned_to', Auth::id())->where('status', 'open')->where('automation_key', 'like', 'escalation:%')->count(),
            ],
            'in_progress' => $visits->where('status', 'in_progress')->values()->map(fn (FieldVisit $v) => ['visit_id' => $v->id, 'representative_id' => $v->representative_id,
                'representative' => $names[$v->representative_id] ?? null, 'customer_id' => $v->customer_id,
                'customer' => DB::table('customers')->where('id', $v->customer_id)->value('name'),
                'checked_in_at' => $v->check_in_at?->toIso8601String(), 'verification' => $v->check_in_verification]),
            'team' => $team,
            'pending_approvals' => $this->pendingApprovals($names),
            'pipeline' => $this->pipeline(),
            'targets' => $this->targets->visible()->where('period_start', '<=', $day->toDateString())->where('period_end', '>=', $day->toDateString())
                ->limit(20)->get()->map(fn (SalesTarget $t) => ['id' => $t->id, 'scope_type' => $t->scope_type, 'representative_id' => $t->representative_id,
                    'territory_id' => $t->territory_id, 'metric' => $t->metric] + $this->targets->achievement($t))->values(),
            'feedback' => Gate::allows('crm.feedback.manage') ? [
                'open' => $this->scope->scopeCustomers(CustomerFeedback::query())->where('status', '!=', 'resolved')->count(),
                'possible_adverse_events_open' => $this->scope->scopeCustomers(CustomerFeedback::query())->where('status', '!=', 'resolved')->where('possible_adverse_event', true)->count(),
            ] : null,
        ];
    }

    /** Submitted plans of the team; decidable only when every planned customer is within the viewer's reach. */
    private function pendingApprovals($names): array
    {
        if (! Gate::allows('crm.plans.approve')) {
            return [];
        }

        return $this->scope->scopeRepresentatives(VisitPlan::query())->where('status', 'submitted')->where('representative_id', '!=', Auth::id())
            ->orderBy('submitted_at')->limit(50)->get()->map(function (VisitPlan $p) use ($names) {
                $customers = $p->items()->pluck('customer_id')->unique();

                return ['visit_plan_id' => $p->id, 'representative_id' => $p->representative_id,
                    'representative' => $names[$p->representative_id] ?? DB::table('users')->where('id', $p->representative_id)->value('name'),
                    'period_start' => $p->period_start->toDateString(), 'period_end' => $p->period_end->toDateString(),
                    'calls' => $customers->count(), 'submitted_at' => $p->submitted_at?->toIso8601String(),
                    'decidable' => $customers->every(fn ($id) => $this->scope->customerIsVisible($id))];
            })->all();
    }

    private function pipeline(): array
    {
        $open = $this->opportunities->visible()->whereIn('stage', Opportunity::OPEN_STAGES)->get(['expected_value', 'probability', 'next_step_date']);

        return ['open' => $open->count(), 'value' => bcadd((string) $open->sum('expected_value'), '0', 4),
            'weighted_value' => bcadd((string) $open->sum(fn ($o) => (float) $o->expected_value * $o->probability / 100), '0', 4),
            'next_steps_overdue' => $open->filter(fn ($o) => $o->next_step_date !== null && $o->next_step_date->lt(business_today()))->count()];
    }

    private function teamIds(): array
    {
        $ids = $this->scope->visibleRepresentativeIds();

        return $ids === null ? DB::table('territory_members')->where('organization_id', $this->tenantId())->where('role', 'representative')->distinct()->pluck('user_id')->all() : $ids;
    }

    private function tenantId(): string
    {
        return $this->tenant->id();
    }
}
