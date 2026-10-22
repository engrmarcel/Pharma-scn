<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\CrmInteraction;
use App\Domain\Crm\Models\CustomerFeedback;
use App\Domain\Crm\Models\CustomerSegment;
use App\Domain\Crm\Models\CustomerSegmentMember;
use App\Domain\Crm\Models\DetailingRecord;
use App\Domain\FieldForce\Models\FieldOrder;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Domain\Parties\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CRM analytics (spec §17, §19.1 field-force performance) for a period, over
 * the customers and activity the viewer may see, optionally for one territory.
 * Figures are computed from the operational tables at request time.
 */
class CrmAnalytics
{
    public function __construct(private readonly FieldForceScope $scope, private readonly OpportunityService $opportunities) {}

    public function build(string $from, string $to, ?string $territoryId = null): array
    {
        $range = [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
        $customers = $this->scope->scopeCustomers(Customer::query(), 'id')->where('status', 'active')
            ->when($territoryId, fn ($q, $v) => $q->where('territory_id', $v))->get(['id', 'tier', 'visit_frequency_days']);
        $ids = $customers->pluck('id');

        $visits = $this->scope->scopeActivity(FieldVisit::query())->whereBetween('check_in_at', $range)->whereIn('customer_id', $ids)
            ->get(['id', 'customer_id', 'status', 'visit_type', 'outcome', 'check_in_verification', 'order_opportunity']);
        $completed = $visits->where('status', 'completed');
        $visited = $completed->pluck('customer_id')->unique();
        $orders = $this->scope->scopeActivity(FieldOrder::query(), 'field_orders.representative_id', 'field_orders.customer_id')
            ->whereBetween('field_orders.captured_at', $range)->whereIn('field_orders.customer_id', $ids)
            ->leftJoin('sales_orders as so', 'so.id', '=', 'field_orders.sales_order_id')->get(['field_orders.customer_id', 'field_orders.status', 'so.total']);
        $accepted = $orders->where('status', 'accepted');

        return [
            'from' => $from, 'to' => $to, 'territory_id' => $territoryId,
            'coverage' => $this->coverage($customers, $visited, $completed, $range[1]),
            'segments' => CustomerSegment::query()->where('status', 'active')->orderBy('name')->get()->map(function ($s) use ($ids, $visited) {
                $members = CustomerSegmentMember::query()->where('segment_id', $s->id)->whereIn('customer_id', $ids)->pluck('customer_id');

                return ['segment_id' => $s->id, 'name' => $s->name, 'customers' => $members->count(), 'visited' => $members->intersect($visited)->count()];
            })->values(),
            'visits' => [
                'total' => $visits->count(),
                'completed' => $completed->count(),
                'verified_percent' => $visits->count() > 0 ? round($visits->where('check_in_verification', 'verified')->count() / $visits->count() * 100, 1) : null,
                'by_type' => $visits->countBy('visit_type'),
                'by_outcome' => $completed->countBy(fn ($v) => $v->outcome ?? 'unrecorded'),
            ],
            'conversion' => [
                'visits_with_order_opportunity' => $completed->where('order_opportunity', true)->count(),
                'orders_captured' => $orders->count(),
                'orders_accepted' => $accepted->count(),
                'accepted_value' => bcadd((string) $accepted->sum('total'), '0', 4),
                'visited_customers_ordering_percent' => $visited->count() > 0
                    ? round($visited->intersect($accepted->pluck('customer_id')->unique())->count() / $visited->count() * 100, 1) : null,
            ],
            'pipeline' => $this->pipeline($range, $territoryId),
            'detailing' => $this->detailing($range, $ids),
            'feedback' => $this->scope->scopeActivity(CustomerFeedback::query(), 'recorded_by')->whereBetween('created_at', $range)->whereIn('customer_id', $ids)
                ->get(['category', 'status', 'possible_adverse_event', 'rating'])->pipe(fn ($f) => [
                    'total' => $f->count(), 'by_category' => $f->countBy('category'), 'open' => $f->where('status', '!=', 'resolved')->count(),
                    'possible_adverse_events' => $f->where('possible_adverse_event', true)->count(),
                    'average_rating' => $f->whereNotNull('rating')->isNotEmpty() ? round($f->whereNotNull('rating')->avg('rating'), 2) : null]),
            'interactions' => $this->scope->scopeActivity(CrmInteraction::query())->whereBetween('occurred_at', $range)->whereIn('customer_id', $ids)
                ->get(['channel'])->countBy('channel'),
            'note' => 'Coverage counts active customers visible to you; frequency compliance counts customers with a call frequency that are not overdue at the end of the period.',
        ];
    }

    private function coverage($customers, $visited, $completed, Carbon $end): array
    {
        $lastVisit = FieldVisit::query()->where('status', 'completed')->where('check_in_at', '<=', $end)->whereIn('customer_id', $customers->pluck('id'))
            ->groupBy('customer_id')->selectRaw('customer_id, MAX(check_in_at) as last')->pluck('last', 'customer_id');
        $withFrequency = $customers->whereNotNull('visit_frequency_days');
        $compliant = $withFrequency->filter(fn ($c) => isset($lastVisit[$c->id])
            && Carbon::parse($lastVisit[$c->id])->startOfDay()->addDays($c->visit_frequency_days)->gte($end->copy()->startOfDay()));

        $byTier = $customers->groupBy(fn ($c) => $c->tier ?? 'unclassified')->map(fn ($group) => [
            'customers' => $group->count(),
            'visited' => $group->pluck('id')->intersect($visited)->count(),
            'visits' => $completed->whereIn('customer_id', $group->pluck('id'))->count(),
        ])->sortKeys();

        return [
            'customers' => $customers->count(),
            'visited' => $visited->count(),
            'coverage_percent' => $customers->count() > 0 ? round($visited->count() / $customers->count() * 100, 1) : null,
            'frequency_compliance_percent' => $withFrequency->count() > 0 ? round($compliant->count() / $withFrequency->count() * 100, 1) : null,
            'by_tier' => $byTier,
        ];
    }

    private function pipeline(array $range, ?string $territoryId): array
    {
        $all = $this->opportunities->visible()->when($territoryId, fn ($q, $v) => $q->where('territory_id', $v))->get(['stage', 'expected_value', 'probability', 'created_at', 'closed_at']);
        $closed = $all->filter(fn ($o) => $o->closed_at !== null && $o->closed_at->between(...$range));
        $won = $closed->where('stage', 'won');

        return [
            'open' => $all->whereIn('stage', ['lead', 'qualified', 'proposal', 'negotiation'])->count(),
            'open_weighted_value' => bcadd((string) $all->whereIn('stage', ['lead', 'qualified', 'proposal', 'negotiation'])->sum(fn ($o) => (float) $o->expected_value * $o->probability / 100), '0', 4),
            'won' => $won->count(),
            'lost' => $closed->where('stage', 'lost')->count(),
            'win_rate_percent' => $closed->count() > 0 ? round($won->count() / $closed->count() * 100, 1) : null,
            'won_value' => bcadd((string) $won->sum('expected_value'), '0', 4),
            'average_days_to_win' => $won->isNotEmpty() ? round($won->avg(fn ($o) => $o->created_at->diffInDays($o->closed_at)), 1) : null,
        ];
    }

    private function detailing(array $range, $customerIds): array
    {
        $records = $this->scope->scopeActivity(DetailingRecord::query())->whereBetween('recorded_at', $range)->whereIn('customer_id', $customerIds)
            ->get(['product_id', 'customer_id', 'reception', 'samples_quantity']);
        $names = DB::table('products')->whereIn('id', $records->pluck('product_id')->unique())->pluck('display_name', 'id');

        return $records->groupBy('product_id')->map(fn ($group, $productId) => [
            'product_id' => $productId,
            'product' => $names[$productId] ?? null,
            'records' => $group->count(),
            'customers_reached' => $group->pluck('customer_id')->unique()->count(),
            'positive_percent' => round($group->where('reception', 'positive')->count() / $group->count() * 100, 1),
            'samples_quantity' => bcadd((string) $group->sum('samples_quantity'), '0', 4),
        ])->sortByDesc('records')->values()->all();
    }
}
