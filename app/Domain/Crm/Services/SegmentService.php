<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\CustomerSegment;
use App\Domain\Crm\Models\CustomerSegmentMember;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Customer segmentation (spec §17).
 *
 * Rule segments are defined by criteria, all of which must match (AND):
 *   customer_types[]    customer type
 *   territory_ids[]     territory
 *   tiers[]             account tier (A, B, C)
 *   cities[]            city, case-insensitive
 *   not_visited_days    no completed visit in that many days (or never visited)
 *   min_revenue_12m / max_revenue_12m   issued sales invoices less credits, last 365 days
 * Only active customers match. A refresh replaces the rule members and keeps
 * members added by hand.
 */
class SegmentService
{
    public const CRITERIA = ['customer_types', 'territory_ids', 'tiers', 'cities', 'not_visited_days', 'min_revenue_12m', 'max_revenue_12m'];

    public function __construct(private readonly TenantContext $tenant, private readonly AuditLogger $audit) {}

    public function create(array $data, User $actor): CustomerSegment
    {
        if (CustomerSegment::query()->where('code', $data['code'])->exists()) {
            throw new BusinessRuleViolation('segment_code_taken', "A segment with code {$data['code']} already exists.", 409);
        }
        $this->assertCriteria($data, creating: true);
        $segment = CustomerSegment::query()->create($data + ['created_by' => $actor->id]);
        $this->audit->record('customer_segment.created', $segment, new: $data);
        if ($segment->type === 'rule') {
            $this->refresh($segment);
        }

        return $segment->fresh();
    }

    public function update(CustomerSegment $segment, array $data): CustomerSegment
    {
        $this->assertCriteria($data + ['type' => $segment->type]);
        $original = $segment->getAttributes();
        $segment->fill($data)->save();
        [$old, $new] = $this->audit->diff($segment, $original);
        if ($new !== []) {
            $this->audit->record('customer_segment.updated', $segment, $old, $new);
        }
        if ($segment->type === 'rule' && array_key_exists('criteria', $data)) {
            $this->refresh($segment);
        }

        return $segment->fresh();
    }

    /** @return int members after the refresh */
    public function refresh(CustomerSegment $segment): int
    {
        if ($segment->type !== 'rule') {
            throw new BusinessRuleViolation('segment_not_rule_based', 'Only rule-based segments are refreshed.', 409);
        }

        return DB::transaction(function () use ($segment) {
            $matching = $this->matching($segment->criteria ?? [])->pluck('c.id')->all();
            $manual = CustomerSegmentMember::query()->where('segment_id', $segment->id)->where('source', 'manual')->pluck('customer_id')->all();

            CustomerSegmentMember::query()->where('segment_id', $segment->id)->where('source', 'rule')->delete();
            $now = now();
            foreach (array_chunk(array_values(array_diff($matching, $manual)), 500) as $chunk) {
                CustomerSegmentMember::query()->insert(array_map(fn ($id) => ['organization_id' => $segment->organization_id, 'segment_id' => $segment->id,
                    'customer_id' => $id, 'source' => 'rule', 'created_at' => $now, 'updated_at' => $now], $chunk));
            }
            $segment->forceFill(['last_refreshed_at' => $now])->save();

            return CustomerSegmentMember::query()->where('segment_id', $segment->id)->count();
        });
    }

    /** Adds or removes members of a manual segment. */
    public function changeMembers(CustomerSegment $segment, array $add, array $remove): void
    {
        if ($segment->type !== 'manual') {
            throw new BusinessRuleViolation('segment_rule_based', 'Members of a rule-based segment follow its criteria. Change the criteria instead.', 409);
        }

        DB::transaction(function () use ($segment, $add, $remove) {
            $known = DB::table('customers')->where('organization_id', $this->tenant->id())->whereIn('id', $add)->pluck('id')->all();
            if (count($known) !== count(array_unique($add))) {
                throw new BusinessRuleViolation('customer_not_found', 'One or more customers were not found.', 404);
            }
            foreach ($known as $id) {
                CustomerSegmentMember::query()->firstOrCreate(['segment_id' => $segment->id, 'customer_id' => $id], ['source' => 'manual']);
            }
            CustomerSegmentMember::query()->where('segment_id', $segment->id)->whereIn('customer_id', $remove)->delete();
            $this->audit->record('customer_segment.members_changed', $segment, new: ['added' => $known, 'removed' => $remove]);
        });
    }

    /** Customers matching the criteria (organization's active customers). */
    public function matching(array $criteria): Builder
    {
        $q = DB::table('customers as c')->where('c.organization_id', $this->tenant->id())->where('c.status', 'active')->select('c.id');

        if (! empty($criteria['customer_types'])) {
            $q->whereIn('c.type', $criteria['customer_types']);
        }
        if (! empty($criteria['territory_ids'])) {
            $q->whereIn('c.territory_id', $criteria['territory_ids']);
        }
        if (! empty($criteria['tiers'])) {
            $q->whereIn('c.tier', $criteria['tiers']);
        }
        if (! empty($criteria['cities'])) {
            $q->whereIn(DB::raw('LOWER(c.city)'), array_map('mb_strtolower', $criteria['cities']));
        }
        if (! empty($criteria['not_visited_days'])) {
            $since = now()->subDays((int) $criteria['not_visited_days']);
            $q->whereNotExists(fn ($v) => $v->from('field_visits as v')->whereColumn('v.customer_id', 'c.id')
                ->where('v.status', 'completed')->where('v.check_in_at', '>=', $since));
        }
        if (isset($criteria['min_revenue_12m']) || isset($criteria['max_revenue_12m'])) {
            $revenue = DB::table('sales_invoices')->where('organization_id', $this->tenant->id())->where('status', 'issued')
                ->where('invoice_date', '>=', business_today()->subDays(365)->toDateString())
                ->groupBy('customer_id')->selectRaw('customer_id, SUM(total - amount_credited) as revenue');
            $q->leftJoinSub($revenue, 'r', 'r.customer_id', '=', 'c.id');
            if (isset($criteria['min_revenue_12m'])) {
                $q->whereRaw('COALESCE(r.revenue, 0) >= ?', [$criteria['min_revenue_12m']]);
            }
            if (isset($criteria['max_revenue_12m'])) {
                $q->whereRaw('COALESCE(r.revenue, 0) <= ?', [$criteria['max_revenue_12m']]);
            }
        }

        return $q;
    }

    private function assertCriteria(array $data, bool $creating = false): void
    {
        $type = $data['type'] ?? null;
        $criteria = $data['criteria'] ?? null;
        // Validation drops an empty criteria object, so a new rule segment must always bring criteria.
        if ($type === 'rule' && ($creating || array_key_exists('criteria', $data)) && (empty($criteria) || array_diff_key($criteria, array_flip(self::CRITERIA)) !== [])) {
            throw new BusinessRuleViolation('segment_criteria_invalid', 'Rule-based segments need at least one known criterion.', 422, ['criteria' => self::CRITERIA]);
        }
        if ($type === 'manual' && ! empty($criteria)) {
            throw new BusinessRuleViolation('segment_criteria_invalid', 'Manual segments have no criteria.', 422);
        }
        if (! empty($criteria['territory_ids'])
            && DB::table('territories')->where('organization_id', $this->tenant->id())->whereIn('id', $criteria['territory_ids'])->count() !== count(array_unique($criteria['territory_ids']))) {
            throw new BusinessRuleViolation('territory_not_found', 'One or more territories were not found.', 422);
        }
    }
}
