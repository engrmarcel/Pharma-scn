<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8a: the analytical store (spec §19, "separate transactional operations from analytical workloads").
 *
 *   analytics_daily_demand   units leaving each warehouse to customers and patients per product and day (sales and
 *                            POS issues net of sales returns), rebuilt from the inventory ledger by analytics:refresh
 *   analytics_refreshes      what was rebuilt, for which organization and up to which day
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_daily_demand', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('warehouse_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            $t->date('day');
            $t->decimal('quantity_out', 18, 4)->default(0);       // base units issued by sale
            $t->decimal('quantity_returned', 18, 4)->default(0);  // base units back by sales return
            $t->unsignedInteger('movements')->default(0);

            $t->unique(['organization_id', 'warehouse_id', 'product_id', 'day'], 'analytics_demand_unique');
            $t->index(['organization_id', 'product_id', 'day'], 'analytics_demand_product_day');
        });

        Schema::create('analytics_refreshes', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('dataset', 32);
            $t->date('from_day');
            $t->date('to_day');
            $t->unsignedInteger('rows')->default(0);
            $t->unsignedInteger('duration_ms')->default(0);
            $t->timestamp('refreshed_at');

            $t->index(['organization_id', 'dataset', 'refreshed_at'], 'analytics_refreshes_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_refreshes');
        Schema::dropIfExists('analytics_daily_demand');
    }
};
