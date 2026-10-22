<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\CrmInteraction;
use App\Domain\Crm\Models\CustomerFeedback;
use App\Domain\Crm\Models\DetailingRecord;
use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\FieldForce\Models\FieldOrder;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Domain\Parties\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Customer interaction history (spec §17): one timeline of visits, mobile
 * orders, follow-ups, logged interactions, feedback and detailing, plus ERP
 * sales orders and invoices for users who may see sales. Every source is
 * filtered by the same activity scope as its own list, so the timeline never
 * shows more than the viewer could find elsewhere.
 */
class CustomerTimeline
{
    public const TYPES = ['visit', 'field_order', 'follow_up', 'interaction', 'feedback', 'detailing', 'sales_order', 'sales_invoice'];

    public function __construct(private readonly FieldForceScope $scope) {}

    /**
     * @param  list<string>  $types
     * @return array{entries: list<array>, next_before: ?string}
     */
    public function for(Customer $customer, array $types, ?string $before, int $limit = 50): array
    {
        $types = $types === [] ? self::TYPES : array_values(array_intersect($types, self::TYPES));
        $before = $before ? Carbon::parse($before) : now()->addDay();
        $entries = collect();

        foreach ($types as $type) {
            $entries = $entries->merge($this->{'load'.str_replace('_', '', ucwords($type, '_'))}($customer, $before, $limit));
        }

        $sorted = $entries->sortByDesc('at')->values();
        $page = $sorted->take($limit);
        $names = DB::table('users')->whereIn('id', $page->pluck('by')->filter()->unique())->pluck('name', 'id');

        return [
            'entries' => $page->map(fn ($e) => $e + ['by_name' => $e['by'] ? ($names[$e['by']] ?? null) : null])->all(),
            'next_before' => $sorted->count() > $limit ? $page->last()['at'] : null,
        ];
    }

    private function loadVisit(Customer $c, Carbon $before, int $limit): Collection
    {
        return $this->scope->scopeActivity(FieldVisit::query()->where('customer_id', $c->id)->where('check_in_at', '<', $before))
            ->latest('check_in_at')->limit($limit)->get()
            ->map(fn (FieldVisit $v) => $this->entry('visit', $v->id, $v->check_in_at, $v->representative_id,
                "Visit {$v->number} ({$v->visit_type})", ['status' => $v->status, 'outcome' => $v->outcome, 'check_in_verification' => $v->check_in_verification]));
    }

    private function loadFieldOrder(Customer $c, Carbon $before, int $limit): Collection
    {
        return $this->scope->scopeActivity(FieldOrder::query()->where('customer_id', $c->id)->where('captured_at', '<', $before))
            ->latest('captured_at')->limit($limit)->get()
            ->map(fn (FieldOrder $o) => $this->entry('field_order', $o->id, $o->captured_at, $o->representative_id,
                "Mobile order {$o->number}", ['status' => $o->status, 'sales_order_id' => $o->sales_order_id]));
    }

    private function loadFollowUp(Customer $c, Carbon $before, int $limit): Collection
    {
        return $this->scope->scopeActivity(FieldFollowUp::query()->where('customer_id', $c->id)->where('created_at', '<', $before), 'assigned_to')
            ->latest()->limit($limit)->get()
            ->map(fn (FieldFollowUp $f) => $this->entry('follow_up', $f->id, $f->created_at, $f->assigned_to, $f->title,
                ['status' => $f->status, 'due_on' => $f->due_on?->toDateString()]));
    }

    private function loadInteraction(Customer $c, Carbon $before, int $limit): Collection
    {
        return $this->scope->scopeActivity(CrmInteraction::query()->where('customer_id', $c->id)->where('occurred_at', '<', $before))
            ->latest('occurred_at')->limit($limit)->get()
            ->map(fn (CrmInteraction $i) => $this->entry('interaction', $i->id, $i->occurred_at, $i->representative_id, $i->subject,
                ['channel' => $i->channel, 'direction' => $i->direction, 'contact_id' => $i->contact_id]));
    }

    private function loadFeedback(Customer $c, Carbon $before, int $limit): Collection
    {
        return $this->scope->scopeActivity(CustomerFeedback::query()->where('customer_id', $c->id)->where('created_at', '<', $before), 'recorded_by')
            ->latest()->limit($limit)->get()
            ->map(fn (CustomerFeedback $f) => $this->entry('feedback', $f->id, $f->created_at, $f->recorded_by, ucfirst(str_replace('_', ' ', $f->category)).' feedback',
                ['status' => $f->status, 'rating' => $f->rating, 'possible_adverse_event' => $f->possible_adverse_event]));
    }

    private function loadDetailing(Customer $c, Carbon $before, int $limit): Collection
    {
        $records = $this->scope->scopeActivity(DetailingRecord::query()->where('customer_id', $c->id)->where('recorded_at', '<', $before))
            ->latest('recorded_at')->limit($limit)->get();
        $names = DB::table('products')->whereIn('id', $records->pluck('product_id'))->pluck('display_name', 'id');

        return $records->map(fn (DetailingRecord $d) => $this->entry('detailing', $d->id, $d->recorded_at, $d->representative_id,
            'Detailed '.($names[$d->product_id] ?? 'a product'), ['reception' => $d->reception, 'messages' => count($d->message_ids), 'field_visit_id' => $d->field_visit_id]));
    }

    private function loadSalesOrder(Customer $c, Carbon $before, int $limit): Collection
    {
        if (! Gate::allows('sales.view')) {
            return collect();
        }

        return DB::table('sales_orders')->where('organization_id', $c->organization_id)->where('customer_id', $c->id)->where('created_at', '<', $before)
            ->latest()->limit($limit)->get(['id', 'number', 'status', 'total', 'currency_code', 'created_at', 'created_by'])
            ->map(fn ($o) => $this->entry('sales_order', $o->id, Carbon::parse($o->created_at), $o->created_by, "Sales order {$o->number}",
                ['status' => $o->status, 'total' => $o->total, 'currency_code' => $o->currency_code]));
    }

    private function loadSalesInvoice(Customer $c, Carbon $before, int $limit): Collection
    {
        if (! Gate::allows('sales.view')) {
            return collect();
        }

        return DB::table('sales_invoices')->where('organization_id', $c->organization_id)->where('customer_id', $c->id)->where('created_at', '<', $before)
            ->latest()->limit($limit)->get(['id', 'number', 'status', 'total', 'currency_code', 'payment_status', 'created_at', 'created_by'])
            ->map(fn ($i) => $this->entry('sales_invoice', $i->id, Carbon::parse($i->created_at), $i->created_by, "Invoice {$i->number}",
                ['status' => $i->status, 'total' => $i->total, 'currency_code' => $i->currency_code, 'payment_status' => $i->payment_status]));
    }

    private function entry(string $type, string $id, ?Carbon $at, ?int $by, string $title, array $details): array
    {
        return ['type' => $type, 'id' => $id, 'at' => $at?->toIso8601String(), 'by' => $by, 'title' => $title, 'details' => $details];
    }
}
