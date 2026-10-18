<?php

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issued invoices are never edited; corrections are credit notes.
 */
class SalesInvoice extends CommercialDocument
{
    protected function casts(): array
    {
        return parent::casts() + ['invoice_date' => 'date', 'due_date' => 'date', 'amount_paid' => 'decimal:4', 'amount_credited' => 'decimal:4'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class);
    }

    /** unpaid | partial | paid, counting both payments and credit notes. */
    public function settlementStatus(): string
    {
        $settled = bcadd((string) $this->amount_paid, (string) $this->amount_credited, 4);

        return match (true) {
            bccomp($settled, (string) $this->total, 4) >= 0 => 'paid',
            bccomp((string) $this->amount_paid, '0', 4) > 0 => 'partial',
            default => 'unpaid',
        };
    }

    public function openBalance(): string
    {
        return bcsub(bcsub((string) $this->total, (string) $this->amount_paid, 4), (string) $this->amount_credited, 4);
    }
}
