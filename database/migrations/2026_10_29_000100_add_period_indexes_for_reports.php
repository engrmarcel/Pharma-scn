<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 load test: dashboards and reports filter movements and invoices by organization and
 * period, and EXPLAIN showed both reading every row of the organization (the existing indexes
 * lead with product or number). These serve the period range directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->index(['organization_id', 'posted_at'], 'inventory_movements_org_posted_at_index');
        });
        Schema::table('sales_invoices', function (Blueprint $t) {
            $t->index(['organization_id', 'invoice_date'], 'sales_invoices_org_invoice_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropIndex('inventory_movements_org_posted_at_index'));
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->dropIndex('sales_invoices_org_invoice_date_index'));
    }
};
