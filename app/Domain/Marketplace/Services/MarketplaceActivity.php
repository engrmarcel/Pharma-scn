<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The trading floor (spec §14): what needs the organization's attention on the marketplace, as a
 * buyer and as a supplier, its recent trading activity and headline figures, and the directory of
 * listed suppliers. Read-only; every action is taken through the marketplace endpoints.
 */
class MarketplaceActivity
{
    public function __construct(private readonly MarketplaceAccess $access) {}

    /** @return array<string, mixed> */
    public function summary(Organization $org): array
    {
        $id = $org->id;
        $since = now()->subDays(30);
        $orders = fn () => DB::table('marketplace_orders');

        $buying = [
            'drafts' => $orders()->where('buyer_organization_id', $id)->where('status', 'draft')->count(),
            'awaiting_internal_approval' => $orders()->where('buyer_organization_id', $id)->where('status', 'pending_approval')->count(),
            'awaiting_supplier' => $orders()->where('buyer_organization_id', $id)->where('status', 'submitted')->count(),
            'shipments_to_receive' => DB::table('marketplace_shipments as s')->join('marketplace_orders as o', 'o.id', '=', 's.marketplace_order_id')
                ->where('o.buyer_organization_id', $id)->where('s.status', 'in_transit')->count(),
            'invoices_to_record' => DB::table('marketplace_invoices as i')->join('marketplace_orders as o', 'o.id', '=', 'i.marketplace_order_id')
                ->where('o.buyer_organization_id', $id)->whereNull('i.buyer_supplier_invoice_id')->count(),
            'quotes_to_review' => DB::table('marketplace_quotes')->where('buyer_organization_id', $id)->where('status', 'submitted')->where('valid_until', '>=', business_today())->count(),
            'open_rfqs' => DB::table('rfqs')->where('buyer_organization_id', $id)->where('status', 'open')->count(),
            'partner_requests_pending' => DB::table('trading_relationships')->where('buyer_organization_id', $id)->where('status', 'requested')->count(),
        ];
        $selling = [
            'orders_to_accept' => $orders()->where('supplier_organization_id', $id)->where('status', 'submitted')->count(),
            'orders_to_ship' => $orders()->where('supplier_organization_id', $id)->whereIn('status', ['accepted', 'partially_shipped'])->count(),
            'rfqs_to_answer' => DB::table('rfq_invitations as v')->join('rfqs as r', 'r.id', '=', 'v.rfq_id')->where('v.supplier_organization_id', $id)
                ->where('v.status', 'invited')->where('r.status', 'open')->where('r.response_deadline', '>=', now())->count(),
            'partner_requests_to_decide' => DB::table('trading_relationships')->where('supplier_organization_id', $id)->where('status', 'requested')->count(),
            'active_listings' => DB::table('marketplace_listings')->where('organization_id', $id)->where('status', 'active')->count(),
            'listed' => (bool) DB::table('marketplace_profiles')->where('organization_id', $id)->value('listed'),
        ];
        $disputes = DB::table('marketplace_disputes')->where(fn ($q) => $q->where('buyer_organization_id', $id)->orWhere('supplier_organization_id', $id))
            ->whereIn('status', ['open', 'escalated'])->count();

        $money = fn ($q) => $q->whereNotIn('status', ['draft', 'pending_approval', 'cancelled', 'rejected'])->where('submitted_at', '>=', $since)
            ->groupBy('currency_code')->selectRaw('currency_code, SUM(subtotal) as amount, COUNT(*) as orders')->get()
            ->map(fn ($r) => ['currency_code' => $r->currency_code, 'amount' => bcadd((string) $r->amount, '0', 4), 'orders' => (int) $r->orders])->values()->all();

        return [
            'organization' => ['id' => $id, 'name' => $org->name, 'type' => $org->type->value,
                'can_sell' => array_key_exists($org->type->value, config('marketplace.buyer_types_by_supplier_type')),
                'can_buy' => collect(config('marketplace.buyer_types_by_supplier_type'))->flatten()->contains($org->type->value),
                'verification' => MarketplaceAccess::verificationLabel($org)],
            'buying' => $buying,
            'selling' => $selling,
            'open_disputes' => $disputes,
            'last_30_days' => [
                'purchases' => $money($orders()->where('buyer_organization_id', $id)),
                // A sale counts once the supplier has accepted the order; a purchase once it was sent.
                'sales' => $money($orders()->where('supplier_organization_id', $id)->where('status', '!=', 'submitted')),
            ],
            'partners' => [
                'suppliers' => DB::table('trading_relationships')->where('buyer_organization_id', $id)->where('status', 'approved')->count(),
                'buyers' => DB::table('trading_relationships')->where('supplier_organization_id', $id)->where('status', 'approved')->count(),
            ],
            'recent' => $this->recent($id),
        ];
    }

    /**
     * Latest trading events involving the organization, newest first.
     *
     * @return list<array{at: string, kind: string, side: string, reference: string, title: string, status: string, counterparty: ?string, link: array}>
     */
    private function recent(string $id, int $limit = 15): array
    {
        $names = fn ($ids) => DB::table('organizations')->whereIn('id', collect($ids)->filter()->unique()->values())->pluck('name', 'id');
        $events = collect();

        foreach (DB::table('marketplace_orders')->where(fn ($q) => $q->where('buyer_organization_id', $id)->orWhere('supplier_organization_id', $id))
            ->where('status', '!=', 'draft')->latest('updated_at')->limit($limit)->get() as $o) {
            $buyer = $o->buyer_organization_id === $id;
            $events->push(['at' => $o->updated_at, 'kind' => 'order', 'side' => $buyer ? 'buying' : 'selling', 'reference' => $o->number,
                'title' => 'Order '.$o->number, 'status' => $o->status, 'counterparty_id' => $buyer ? $o->supplier_organization_id : $o->buyer_organization_id,
                'amount' => $o->subtotal, 'currency_code' => $o->currency_code, 'link' => ['route' => 'marketplace.orders', 'params' => ['open' => $o->id]]]);
        }
        foreach (DB::table('marketplace_quotes')->where(fn ($q) => $q->where('buyer_organization_id', $id)->orWhere('supplier_organization_id', $id))
            ->latest('updated_at')->limit($limit)->get() as $q) {
            $buyer = $q->buyer_organization_id === $id;
            $events->push(['at' => $q->updated_at, 'kind' => 'quote', 'side' => $buyer ? 'buying' : 'selling', 'reference' => $q->number,
                'title' => 'Quote '.$q->number, 'status' => $q->status, 'counterparty_id' => $buyer ? $q->supplier_organization_id : $q->buyer_organization_id,
                'amount' => $q->subtotal, 'currency_code' => $q->currency_code, 'link' => ['route' => 'marketplace.quotes', 'params' => []]]);
        }
        foreach (DB::table('rfqs')->where('buyer_organization_id', $id)->where('status', '!=', 'draft')->latest('updated_at')->limit($limit)->get() as $r) {
            $events->push(['at' => $r->updated_at, 'kind' => 'rfq', 'side' => 'buying', 'reference' => $r->number, 'title' => $r->title, 'status' => $r->status,
                'counterparty_id' => null, 'amount' => null, 'currency_code' => $r->currency_code, 'link' => ['route' => 'marketplace.rfqs', 'params' => []]]);
        }
        foreach (DB::table('rfq_invitations as v')->join('rfqs as r', 'r.id', '=', 'v.rfq_id')->where('v.supplier_organization_id', $id)->where('r.status', '!=', 'draft')
            ->latest('v.updated_at')->limit($limit)->get(['r.number', 'r.title', 'r.buyer_organization_id', 'r.currency_code', 'v.status', 'v.updated_at']) as $v) {
            $events->push(['at' => $v->updated_at, 'kind' => 'rfq', 'side' => 'selling', 'reference' => $v->number, 'title' => $v->title, 'status' => $v->status === 'invited' ? 'invited' : $v->status,
                'counterparty_id' => $v->buyer_organization_id, 'amount' => null, 'currency_code' => $v->currency_code, 'link' => ['route' => 'marketplace.rfqs', 'params' => []]]);
        }
        foreach (DB::table('trading_relationships')->where(fn ($q) => $q->where('buyer_organization_id', $id)->orWhere('supplier_organization_id', $id))
            ->latest('updated_at')->limit($limit)->get() as $t) {
            $buyer = $t->buyer_organization_id === $id;
            $events->push(['at' => $t->updated_at, 'kind' => 'partner', 'side' => $buyer ? 'buying' : 'selling', 'reference' => '',
                'title' => $buyer ? 'Trading request to a supplier' : 'Trading request from a buyer', 'status' => $t->status,
                'counterparty_id' => $buyer ? $t->supplier_organization_id : $t->buyer_organization_id, 'amount' => null, 'currency_code' => null,
                'link' => ['route' => 'marketplace.relationships', 'params' => []]]);
        }
        foreach (DB::table('marketplace_disputes')->where(fn ($q) => $q->where('buyer_organization_id', $id)->orWhere('supplier_organization_id', $id))
            ->latest('updated_at')->limit($limit)->get() as $d) {
            $buyer = $d->buyer_organization_id === $id;
            $events->push(['at' => $d->updated_at, 'kind' => 'dispute', 'side' => $buyer ? 'buying' : 'selling', 'reference' => $d->number, 'title' => 'Dispute '.$d->number,
                'status' => $d->status, 'counterparty_id' => $buyer ? $d->supplier_organization_id : $d->buyer_organization_id, 'amount' => null, 'currency_code' => null,
                'link' => ['route' => 'marketplace.disputes', 'params' => []]]);
        }

        $events = $events->sortByDesc('at')->take($limit)->values();
        $orgNames = $names($events->pluck('counterparty_id'));

        return $events->map(fn ($e) => collect($e)->except('counterparty_id')->all() + [
            'at' => Carbon::parse($e['at'])->toIso8601String(), 'counterparty' => $e['counterparty_id'] ? ($orgNames[$e['counterparty_id']] ?? null) : null,
        ])->all();
    }

    /**
     * Listed suppliers the buyer can see, with what they offer and whether the buyer may trade with them.
     *
     * @param  array{q?: ?string, type?: ?string, state?: ?string, delivery?: ?string, verified_only?: bool}  $f
     * @return array{data: list<array>, meta: array}
     */
    public function suppliers(Organization $buyer, array $f, int $page = 1, int $perPage = 24): array
    {
        $query = DB::table('organizations as o')
            ->join('marketplace_profiles as mp', fn ($j) => $j->on('mp.organization_id', '=', 'o.id')->where('mp.listed', true))
            ->join('organization_modules as om', fn ($j) => $j->on('om.organization_id', '=', 'o.id')->where('om.module', 'marketplace')->where('om.enabled', true))
            ->whereIn('o.type', array_keys(config('marketplace.buyer_types_by_supplier_type')))
            ->where('o.id', '!=', $buyer->id)
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('o.name', 'like', '%'.addcslashes($v, '%_').'%')->orWhere('mp.headline', 'like', '%'.addcslashes($v, '%_').'%')))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('o.type', $v))
            ->when($f['state'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('o.state_region', $v)->orWhereRaw('JSON_CONTAINS(mp.regions_served, ?)', [json_encode($v)])))
            ->when($f['delivery'] ?? null, fn ($q, $v) => $q->whereRaw('JSON_CONTAINS(mp.delivery_options, ?)', [json_encode($v)]))
            ->when(! empty($f['verified_only']), fn ($q) => $q->where('o.verification_status', 'verified'))
            ->select(['o.id', 'o.name', 'o.type', 'o.city', 'o.state_region', 'o.country_code', 'mp.headline', 'mp.regions_served', 'mp.delivery_options', 'mp.minimum_order_value', 'mp.currency_code'])
            ->selectSub(fn ($s) => $s->from('marketplace_listings as l')->whereColumn('l.organization_id', 'o.id')->where('l.status', 'active')->selectRaw('COUNT(*)'), 'active_listings')
            ->orderByDesc('active_listings')->orderBy('o.name');
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $ids = collect($paginator->items())->pluck('id');
        $suppliers = Organization::query()->whereIn('id', $ids)->get()->keyBy('id');
        $relationships = DB::table('trading_relationships')->where('buyer_organization_id', $buyer->id)->whereIn('supplier_organization_id', $ids)->pluck('status', 'supplier_organization_id');

        $rows = collect($paginator->items())->filter(fn ($r) => $this->access->participates($suppliers[$r->id]))->map(function ($r) use ($buyer, $suppliers, $relationships) {
            $block = $this->access->tradeBlock($buyer, $suppliers[$r->id]);

            return [
                'id' => $r->id, 'name' => $r->name, 'type' => $r->type, 'type_label' => OrganizationType::from($r->type)->label(),
                'location' => collect([$r->city, $r->state_region, $r->country_code])->filter()->implode(', '),
                'verification' => MarketplaceAccess::verificationLabel($suppliers[$r->id]),
                'headline' => $r->headline, 'regions_served' => json_decode($r->regions_served ?? '[]', true), 'delivery_options' => json_decode($r->delivery_options ?? '[]', true),
                'minimum_order_value' => $r->minimum_order_value, 'currency_code' => $r->currency_code, 'active_listings' => (int) $r->active_listings,
                'relationship' => $relationships[$r->id] ?? null, 'you_may_trade' => $block === null, 'trade_block' => $block['message'] ?? null,
            ];
        })->values()->all();

        return ['data' => $rows, 'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()]];
    }
}
