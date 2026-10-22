<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Opportunity;
use App\Domain\Crm\Models\VisitPlan;
use App\Domain\Crm\Models\VisitPlanItem;
use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Models\TerritoryMember;
use App\Domain\Parties\Models\Customer;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up automation (spec §17), run daily per organization and on demand.
 * Every rule creates its follow-up once per cause (automation_key), so running
 * it again the same day changes nothing.
 *
 *   missed_plan_items     planned calls whose day has passed become "missed", with a follow-up to reschedule
 *   overdue_visits        customers past their call frequency get a follow-up for their assigned representative
 *   stale_opportunities   open opportunities without activity for N days get a follow-up for their owner
 *   escalation            follow-ups overdue by N days are escalated to a supervisor of the customer's territory
 *
 * Settings: organizations.settings.crm.automation {missed_plan_items, overdue_visits: bool,
 * stale_opportunity_days, escalation_days: int|null (null switches the rule off)}.
 */
class CrmAutomation
{
    public const DEFAULTS = ['missed_plan_items' => true, 'overdue_visits' => true, 'stale_opportunity_days' => 14, 'escalation_days' => 3];

    public function __construct(private readonly TenantContext $tenant, private readonly AuditLogger $audit) {}

    public function settings(): array
    {
        return array_replace(self::DEFAULTS, data_get($this->tenant->organization()->settings ?? [], 'crm.automation', []));
    }

    /** @return array<string, int> follow-ups created (and items marked missed) per rule */
    public function run(): array
    {
        $s = $this->settings();
        $result = ['missed_plan_items' => 0, 'overdue_visits' => 0, 'stale_opportunities' => 0, 'escalated' => 0];

        DB::transaction(function () use ($s, &$result) {
            if ($s['missed_plan_items']) {
                $result['missed_plan_items'] = $this->missedPlanItems();
            }
            if ($s['overdue_visits']) {
                $result['overdue_visits'] = $this->overdueVisits();
            }
            if ($s['stale_opportunity_days'] !== null) {
                $result['stale_opportunities'] = $this->staleOpportunities((int) $s['stale_opportunity_days']);
            }
            if ($s['escalation_days'] !== null) {
                $result['escalated'] = $this->escalate((int) $s['escalation_days']);
            }
        });
        $this->audit->record('crm.automation_run', null, new: $result);

        return $result;
    }

    private function missedPlanItems(): int
    {
        $items = VisitPlanItem::query()->where('status', 'planned')->where('planned_date', '<', business_today()->toDateString())
            ->whereIn('visit_plan_id', VisitPlan::query()->whereIn('status', ['submitted', 'approved'])->select('id'))->with('plan')->get();
        foreach ($items as $item) {
            $item->forceFill(['status' => 'missed'])->save();
            $this->followUp("missed_plan_item:{$item->id}", $item->customer_id, $item->plan->representative_id,
                "Planned visit on {$item->planned_date->toDateString()} was missed: reschedule it");
        }

        return $items->count();
    }

    private function overdueVisits(): int
    {
        $last = FieldVisit::query()->where('status', 'completed')->groupBy('customer_id')->selectRaw('customer_id, MAX(check_in_at) as last_visit')->pluck('last_visit', 'customer_id');
        $created = 0;
        foreach (Customer::query()->where('status', 'active')->whereNotNull('visit_frequency_days')->whereNotNull('assigned_representative_id')->get() as $c) {
            $due = isset($last[$c->id]) ? Carbon::parse($last[$c->id])->startOfDay()->addDays($c->visit_frequency_days) : null;
            if ($due !== null && $due->gt(business_today())) {
                continue;
            }
            $cycle = $due?->toDateString() ?? 'never';
            $created += $this->followUp("overdue_visit:{$c->id}:{$cycle}", $c->id, $c->assigned_representative_id,
                $due ? "Visit overdue since {$cycle} (every {$c->visit_frequency_days} days)" : 'Customer never visited: plan a first visit');
        }

        return $created;
    }

    private function staleOpportunities(int $days): int
    {
        $created = 0;
        // Leads without a customer cannot carry a customer follow-up; they show in the pipeline report instead.
        foreach (Opportunity::query()->whereIn('stage', Opportunity::OPEN_STAGES)->whereNotNull('customer_id')
            ->where('last_activity_at', '<', now()->subDays($days))->get() as $o) {
            $created += $this->followUp("stale_opportunity:{$o->id}:{$o->last_activity_at->toDateString()}", $o->customer_id, $o->owner_id,
                "Opportunity {$o->number} has had no activity for {$days} days: update it or close it");
        }

        return $created;
    }

    private function escalate(int $days): int
    {
        $escalated = 0;
        $overdue = FieldFollowUp::query()->where('status', 'open')->whereNull('escalated_at')->where('due_on', '<', business_today()->subDays($days)->toDateString())
            ->where(fn ($q) => $q->whereNull('automation_key')->orWhere('automation_key', 'not like', 'escalation:%'))->get();
        foreach ($overdue as $f) {
            $territory = Customer::query()->whereKey($f->customer_id)->value('territory_id');
            $supervisor = $territory ? TerritoryMember::query()->where('territory_id', $territory)->where('role', 'supervisor')
                ->where('user_id', '!=', $f->assigned_to)->orderBy('user_id')->value('user_id') : null;
            if ($supervisor === null) {
                continue; // retried on a later run once the territory has a supervisor
            }
            $this->followUp("escalation:{$f->id}", $f->customer_id, $supervisor, mb_strimwidth("Escalated: \"{$f->title}\" is overdue since {$f->due_on->toDateString()}", 0, 255));
            $f->forceFill(['escalated_at' => now()])->save();
            $escalated++;
        }

        return $escalated;
    }

    /** @return int 1 when created, 0 when this cause already has its follow-up */
    private function followUp(string $key, string $customerId, int $assignee, string $title): int
    {
        $followUp = FieldFollowUp::query()->firstOrCreate(['automation_key' => $key], ['customer_id' => $customerId, 'assigned_to' => $assignee,
            'created_by' => null, 'source' => 'automation', 'title' => mb_strimwidth($title, 0, 255), 'due_on' => business_today()->toDateString()]);

        return $followUp->wasRecentlyCreated ? 1 : 0;
    }
}
