<?php

namespace App\Domain\Pos\Services;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Platform\Services\BrandingService;
use App\Domain\Platform\Support\CountryProfile;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosTerminal;
use App\Domain\Sales\Models\SalesInvoice;
use App\Models\User;
use App\Support\Localizer;
use App\Support\Tenancy\TenantContext;

/**
 * Receipt data for a completed POS sale (screen, print or re-print).
 * Shows the organization's branding, batch numbers and expiry for each
 * line (useful for recalls), and never any prescription or patient details.
 */
class ReceiptPresenter
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly TenantContext $tenant,
        private readonly Localizer $format,
    ) {}

    public function present(PosSale $sale): array
    {
        $organization = $this->tenant->organization();
        $products = Product::query()->whereIn('id', $sale->lines->pluck('product_id'))->pluck('display_name', 'id');
        $batchIds = $sale->lines->flatMap(fn ($l) => $l->allocations->pluck('batch_id'));
        $batches = Batch::query()->whereIn('id', $batchIds)->get(['id', 'batch_number', 'expiry_date'])->keyBy('id');
        $brand = $this->branding->forOrganization($organization);

        return [
            'header' => [
                'organization' => $organization->name,
                'address' => trim(implode(', ', array_filter([$organization->address_line1, $organization->city, $organization->state_region]))),
                'phone' => $organization->phone,
                'tax_identification_number' => $organization->tax_identification_number,
                'brand' => ['display_name' => $brand['display_name'], 'logo_url' => $brand['logo_url']],
            ],
            'sale_number' => $sale->number,
            'invoice_number' => SalesInvoice::query()->whereKey($sale->sales_invoice_id)->value('number'),
            'completed_at' => $sale->completed_at?->toIso8601String(),
            'terminal' => PosTerminal::query()->whereKey($sale->pos_terminal_id)->value('code'),
            'cashier' => User::query()->whereKey($sale->cashier_id)->value('name'),
            'currency_code' => $sale->currency_code,
            'lines' => $sale->lines->map(fn ($l) => [
                'product' => $products[$l->product_id] ?? '',
                'quantity' => (string) $l->quantity,
                'package_level' => $l->package_level_code,
                'unit_price' => (string) $l->unit_price,
                'discount' => (string) $l->line_discount,
                'tax' => (string) $l->line_tax,
                'total' => (string) $l->line_total,
                'dispensed_against_prescription' => $l->prescription !== null,
                'batches' => $l->allocations->map(fn ($a) => [
                    'batch_number' => $batches[$a->batch_id]->batch_number ?? null,
                    'expiry_date' => $batches[$a->batch_id]?->expiry_date?->toDateString(),
                    'quantity_base_units' => (string) $a->base_quantity,
                ])->values(),
            ])->values(),
            'totals' => [
                'subtotal' => (string) $sale->subtotal, 'discount' => (string) $sale->discount_total,
                'tax' => (string) $sale->tax_total, 'total' => (string) $sale->total, 'change' => (string) $sale->change_due,
            ],
            'payments' => $sale->payments->where('status', 'confirmed')->map(fn ($p) => [
                'method' => $p->method, 'amount' => (string) $p->amount, 'tendered' => $p->tendered !== null ? (string) $p->tendered : null,
            ])->values(),
            'footer' => 'Thank you. Keep this receipt for returns. Support: '.$brand['support_email'],
            // For people: the country's tax number label, local time, money in the currency's decimals and the locale's format.
            'display' => [
                'tax_identification_label' => CountryProfile::of($organization->country)->get('tax.registration_label'),
                'completed_at' => $this->format->dateTime($sale->completed_at),
                'money' => collect(['subtotal' => $sale->subtotal, 'discount' => $sale->discount_total, 'tax' => $sale->tax_total, 'total' => $sale->total, 'change' => $sale->change_due])
                    ->map(fn ($v) => $this->format->money((string) $v, $sale->currency_code))->all(),
                'lines' => $sale->lines->map(fn ($l) => ['unit_price' => $this->format->money((string) $l->unit_price, $sale->currency_code),
                    'discount' => $this->format->money((string) $l->line_discount, $sale->currency_code), 'total' => $this->format->money((string) $l->line_total, $sale->currency_code)])->values()->all(),
                'payments' => $sale->payments->where('status', 'confirmed')->map(fn ($p) => $this->format->money((string) ($p->tendered ?? $p->amount), $sale->currency_code))->values()->all(),
                'expiry_dates' => $batches->map(fn ($b) => $b->expiry_date ? $this->format->date($b->expiry_date) : null)->all(),
            ],
        ];
    }
}
