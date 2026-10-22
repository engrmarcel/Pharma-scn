<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\VisitPlan;
use App\Domain\Crm\Models\VisitPlanItem;
use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Domain\FieldForce\Services\LocationEvidence;
use App\Domain\Parties\Models\Customer;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Visit planning (spec §17: visit scheduling, visit plans, route planning,
 * supervisor approvals).
 *
 * A representative plans calls on visible customers for up to 31 days and
 * submits the plan. A supervisor approves or rejects it, and only when every
 * customer in it is within the supervisor's own visibility: approval never
 * reaches across territories. Checking in at a planned customer on the planned
 * day marks the item visited; past items still planned become missed (see the
 * CRM automation).
 */
class VisitPlanService
{
    public const MAX_DAYS = 31;

    public function __construct(
        private readonly FieldForceScope $scope,
        private readonly LocationEvidence $geo,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data): VisitPlan
    {
        return DB::transaction(function () use ($data) {
            $this->assertPeriod($data['period_start'], $data['period_end']);
            $this->assertNoOverlap(null, $data['period_start'], $data['period_end']);
            $plan = VisitPlan::query()->create(['representative_id' => Auth::id(), 'period_start' => $data['period_start'],
                'period_end' => $data['period_end'], 'notes' => $data['notes'] ?? null]);
            $this->writeItems($plan, $data['items'] ?? []);
            $this->audit->record('visit_plan.created', $plan, new: ['period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'items' => count($data['items'] ?? [])]);

            return $plan->load('items');
        });
    }

    public function update(VisitPlan $plan, array $data): VisitPlan
    {
        return DB::transaction(function () use ($plan, $data) {
            $locked = $this->own($plan);
            if (! $locked->isEditable()) {
                throw new BusinessRuleViolation('visit_plan_locked', "A {$locked->status} plan cannot be edited.", 409);
            }
            $start = $data['period_start'] ?? $locked->period_start->toDateString();
            $end = $data['period_end'] ?? $locked->period_end->toDateString();
            $this->assertPeriod($start, $end);
            $this->assertNoOverlap($locked->id, $start, $end);
            $locked->fill(['period_start' => $start, 'period_end' => $end] + array_intersect_key($data, ['notes' => true]))->save();
            if (array_key_exists('items', $data)) {
                $locked->items()->delete();
                $this->writeItems($locked, $data['items']);
            }

            return $locked->load('items');
        });
    }

    public function submit(VisitPlan $plan): VisitPlan
    {
        return DB::transaction(function () use ($plan) {
            $locked = $this->own($plan);
            if (! $locked->isEditable()) {
                throw new BusinessRuleViolation('visit_plan_locked', "A {$locked->status} plan cannot be submitted.", 409);
            }
            if (! $locked->items()->exists()) {
                throw new BusinessRuleViolation('visit_plan_empty', 'Add at least one planned visit before submitting.', 422);
            }
            $locked->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'decided_by' => null, 'decided_at' => null, 'decision_notes' => null])->save();
            $this->audit->record('visit_plan.submitted', $locked);

            return $locked->load('items');
        });
    }

    public function decide(VisitPlan $plan, bool $approve, ?string $notes, User $approver): VisitPlan
    {
        Gate::authorize('crm.plans.approve');

        return DB::transaction(function () use ($plan, $approve, $notes, $approver) {
            $locked = VisitPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($locked->status !== 'submitted') {
                throw new BusinessRuleViolation('visit_plan_not_submitted', "A {$locked->status} plan cannot be decided.", 409);
            }
            if ($locked->representative_id === $approver->id) {
                throw new BusinessRuleViolation('approval_segregation', 'You cannot approve your own visit plan.', 403);
            }
            if (! $this->scope->canSeeRepresentative($locked->representative_id)) {
                throw new BusinessRuleViolation('visit_plan_not_found', 'Visit plan not found.', 404);
            }
            $customers = $locked->items()->pluck('customer_id')->unique();
            $outside = $customers->reject(fn ($id) => $this->scope->customerIsVisible($id));
            if ($outside->isNotEmpty()) {
                throw new BusinessRuleViolation('visit_plan_outside_scope', 'The plan includes customers outside your territories; a supervisor of those territories or a manager must decide it.', 403,
                    ['customer_count' => $outside->count()]);
            }
            if (! $approve && blank($notes)) {
                throw new BusinessRuleViolation('decision_notes_required', 'Say why the plan is rejected.', 422);
            }
            $locked->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_notes' => $notes])->save();
            $this->audit->record($approve ? 'visit_plan.approved' : 'visit_plan.rejected', $locked, ['status' => 'submitted'], ['status' => $locked->status, 'notes' => $notes]);

            return $locked->load('items');
        });
    }

    public function cancel(VisitPlan $plan, string $reason): VisitPlan
    {
        return DB::transaction(function () use ($plan, $reason) {
            $locked = $this->own($plan);
            if ($locked->status === 'cancelled') {
                throw new BusinessRuleViolation('visit_plan_cancelled', 'The plan is already cancelled.', 409);
            }
            $locked->forceFill(['status' => 'cancelled'])->save();
            $locked->items()->where('status', 'planned')->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
            $this->audit->record('visit_plan.cancelled', $locked, new: ['reason' => $reason]);

            return $locked->load('items');
        });
    }

    public function cancelItem(VisitPlanItem $item, string $reason): VisitPlanItem
    {
        return DB::transaction(function () use ($item, $reason) {
            $this->own($item->plan);
            if ($item->status !== 'planned') {
                throw new BusinessRuleViolation('visit_plan_item_closed', "A {$item->status} visit cannot be cancelled.", 409);
            }
            $item->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();

            return $item;
        });
    }

    /**
     * Orders one day's calls by nearest neighbour from the first located customer (or the given
     * start point). Customers without a recorded location go last. A heuristic, not a road-network
     * route: distances are straight lines.
     *
     * @return array{date: string, total_distance_km: float, stops: list<array>}
     */
    public function orderRoute(VisitPlan $plan, string $date, ?float $startLat = null, ?float $startLng = null): array
    {
        $this->own($plan);
        $items = $plan->items()->where('planned_date', $date)->where('status', 'planned')->get();
        $customers = Customer::query()->whereIn('id', $items->pluck('customer_id'))->get(['id', 'name', 'latitude', 'longitude'])->keyBy('id');

        $located = $items->filter(fn ($i) => $customers[$i->customer_id]->latitude !== null)->values()->all();
        $unlocated = $items->filter(fn ($i) => $customers[$i->customer_id]->latitude === null)->values()->all();
        $route = [];
        $total = 0.0;
        [$lat, $lng] = [$startLat, $startLng];
        while ($located !== []) {
            $best = 0;
            if ($lat !== null) {
                $distances = array_map(fn ($i) => $this->geo->distanceMetres($lat, $lng, (float) $customers[$i->customer_id]->latitude, (float) $customers[$i->customer_id]->longitude), $located);
                $best = array_keys($distances, min($distances))[0];
                $total += $distances[$best];
            }
            $next = array_splice($located, $best, 1)[0];
            [$lat, $lng] = [(float) $customers[$next->customer_id]->latitude, (float) $customers[$next->customer_id]->longitude];
            $route[] = $next;
        }

        $stops = [];
        foreach ([...$route, ...$unlocated] as $n => $item) {
            $item->forceFill(['sequence' => $n + 1])->save();
            $stops[] = ['item_id' => $item->id, 'sequence' => $n + 1, 'customer_id' => $item->customer_id, 'customer_name' => $customers[$item->customer_id]->name,
                'located' => $customers[$item->customer_id]->latitude !== null];
        }

        return ['date' => $date, 'total_distance_km' => round($total / 1000, 2), 'stops' => $stops,
            'note' => 'Straight-line nearest-neighbour order; customers without a recorded location are placed last.'];
    }

    /** Called when a representative checks in: the matching planned call becomes visited. */
    public function linkVisit(FieldVisit $visit): void
    {
        $item = VisitPlanItem::query()
            ->whereIn('visit_plan_id', VisitPlan::query()->where('representative_id', $visit->representative_id)->whereIn('status', ['submitted', 'approved'])->select('id'))
            ->where('customer_id', $visit->customer_id)->where('planned_date', $visit->check_in_at->toDateString())->where('status', 'planned')
            ->orderBy('sequence')->first();
        $item?->forceFill(['status' => 'visited', 'field_visit_id' => $visit->id])->save();
    }

    /**
     * Customers the current representative should plan between the dates: due by call frequency,
     * or with an open follow-up due, and not already planned in that range.
     */
    public function suggestions(string $from, string $to): array
    {
        $planned = VisitPlanItem::query()->whereIn('status', ['planned', 'visited'])->whereBetween('planned_date', [$from, $to])
            ->whereIn('visit_plan_id', VisitPlan::query()->where('representative_id', Auth::id())->whereNotIn('status', ['cancelled', 'rejected'])->select('id'))
            ->pluck('customer_id')->all();
        $lastVisits = FieldVisit::query()->where('status', 'completed')->groupBy('customer_id')->selectRaw('customer_id, MAX(check_in_at) as last_visit')->pluck('last_visit', 'customer_id');
        $followUps = FieldFollowUp::query()->where('assigned_to', Auth::id())->where('status', 'open')->where('due_on', '<=', $to)
            ->get(['customer_id', 'title', 'due_on'])->groupBy('customer_id');

        $rows = [];
        $customers = $this->scope->scopeCustomers(Customer::query(), 'id')->where('status', 'active')->whereNotIn('id', $planned)->get();
        foreach ($customers as $c) {
            $last = $lastVisits[$c->id] ?? null;
            $due = $c->visit_frequency_days ? ($last ? Carbon::parse($last)->startOfDay()->addDays($c->visit_frequency_days) : Carbon::parse($from)) : null;
            $reasons = [];
            if ($due !== null && $due->lte(Carbon::parse($to))) {
                $reasons[] = $last ? "Visit due {$due->toDateString()} (every {$c->visit_frequency_days} days)" : 'Never visited';
            }
            foreach ($followUps[$c->id] ?? [] as $f) {
                $reasons[] = "Follow-up due {$f->due_on->toDateString()}: {$f->title}";
            }
            if ($reasons !== []) {
                $rows[] = ['customer_id' => $c->id, 'name' => $c->name, 'tier' => $c->tier, 'due' => $due?->toDateString(),
                    'last_visit_at' => $last ? Carbon::parse($last)->toIso8601String() : null, 'reasons' => $reasons];
            }
        }
        usort($rows, fn ($a, $b) => [$a['tier'] ?? 'Z', $a['due'] ?? '9999'] <=> [$b['tier'] ?? 'Z', $b['due'] ?? '9999']);

        return $rows;
    }

    /** @return array{planned: int, visited: int, missed: int, cancelled: int, pending: int, adherence_percent: ?float} */
    public function adherence(VisitPlan $plan): array
    {
        $items = $plan->items()->get(['status', 'planned_date']);
        $counts = ['planned' => $items->count(), 'visited' => 0, 'missed' => 0, 'cancelled' => 0, 'pending' => 0];
        foreach ($items as $i) {
            $state = $i->status === 'planned' && $i->planned_date->lt(today()) ? 'missed' : $i->status;
            $counts[$state === 'planned' ? 'pending' : $state]++;
        }
        $due = $counts['visited'] + $counts['missed'];

        return $counts + ['adherence_percent' => $due > 0 ? round($counts['visited'] / $due * 100, 1) : null];
    }

    private function writeItems(VisitPlan $plan, array $items): void
    {
        $seen = [];
        foreach ($items as $i => $row) {
            $customer = $this->scope->visibleCustomer($row['customer_id']);
            $date = Carbon::parse($row['planned_date'])->toDateString();
            if ($date < $plan->period_start->toDateString() || $date > $plan->period_end->toDateString()) {
                throw new BusinessRuleViolation('visit_plan_date_outside_period', 'Every planned visit must fall within the plan period.', 422, ['item' => $i]);
            }
            if (isset($seen["{$customer->id}|{$date}"])) {
                throw new BusinessRuleViolation('visit_plan_duplicate', 'A customer is planned twice on the same day.', 422, ['item' => $i]);
            }
            $seen["{$customer->id}|{$date}"] = true;
            VisitPlanItem::query()->create(['visit_plan_id' => $plan->id, 'customer_id' => $customer->id, 'planned_date' => $date,
                'sequence' => $row['sequence'] ?? $i + 1, 'objective' => $row['objective'] ?? null]);
        }
    }

    private function assertPeriod(string $start, string $end): void
    {
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);
        if ($e->lt($s) || $s->diffInDays($e) + 1 > self::MAX_DAYS) {
            throw new BusinessRuleViolation('visit_plan_period_invalid', 'A plan covers 1 to '.self::MAX_DAYS.' days.', 422);
        }
    }

    private function assertNoOverlap(?string $planId, string $start, string $end): void
    {
        $overlap = VisitPlan::query()->where('representative_id', Auth::id())->whereNotIn('status', ['cancelled', 'rejected'])
            ->when($planId, fn ($q) => $q->whereKeyNot($planId))
            ->where('period_start', '<=', $end)->where('period_end', '>=', $start)->first();
        if ($overlap !== null) {
            throw new BusinessRuleViolation('visit_plan_overlap', 'Another active plan already covers part of this period.', 409, ['visit_plan_id' => $overlap->id]);
        }
    }

    private function own(VisitPlan $plan): VisitPlan
    {
        $locked = VisitPlan::query()->lockForUpdate()->findOrFail($plan->id);
        if ($locked->representative_id !== Auth::id()) {
            throw new BusinessRuleViolation('visit_plan_not_yours', 'Only the representative can change their visit plan.', 403);
        }

        return $locked;
    }
}
