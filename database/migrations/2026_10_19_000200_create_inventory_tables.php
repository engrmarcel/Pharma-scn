<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory core (spec §10.2, §10.3). inventory_movements is the
 * authoritative, append-only ledger; inventory_balances is a projection
 * maintained only by InventoryLedger under row locks. All quantities are in
 * the product's base unit (package level 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            $t->string('batch_number', 64);
            $t->date('manufacturing_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->foreignUlid('manufacturer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('supplier_id')->nullable()->constrained()->nullOnDelete();
            // released | quarantined | rejected
            $t->string('quality_status', 16)->default('released');
            // none | recalled (driven by recall workflows in Phase 7)
            $t->string('recall_status', 16)->default('none');
            $t->decimal('unit_cost', 19, 4)->nullable(); // per base unit
            $t->char('currency_code', 3)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'product_id', 'batch_number']);
            $t->index(['organization_id', 'expiry_date']);
        });

        Schema::create('bin_locations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('warehouse_id')->constrained()->cascadeOnDelete();
            $t->string('code', 32);
            $t->string('name')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();

            $t->unique(['warehouse_id', 'code']);
        });

        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            // Lines posted together share a posting id (e.g. both legs of a transfer).
            $t->ulid('posting_id')->index();
            $t->foreignUlid('product_id')->constrained();
            $t->foreignUlid('batch_id')->constrained();
            $t->foreignUlid('warehouse_id')->constrained();
            $t->foreignUlid('bin_location_id')->nullable()->constrained()->nullOnDelete();
            // available | quarantined | damaged | in_transit
            $t->string('stock_state', 16);
            $t->decimal('quantity', 18, 4); // signed, base units
            // receipt | adjustment | transfer_out | transfer_in | state_change | sale | sale_return | count | write_off
            $t->string('movement_type', 24);
            $t->string('reason_code', 32)->nullable();
            $t->string('reference_type', 40)->nullable();
            $t->ulid('reference_id')->nullable();
            $t->decimal('unit_cost', 19, 4)->nullable();
            $t->decimal('balance_after', 18, 4);
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('posted_at');
            $t->string('correlation_id', 64)->nullable();

            $t->index(['organization_id', 'product_id', 'posted_at']);
            $t->index(['organization_id', 'batch_id']);
            $t->index(['reference_type', 'reference_id']);
        });

        Schema::create('inventory_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('warehouse_id')->constrained();
            $t->foreignUlid('product_id')->constrained();
            $t->foreignUlid('batch_id')->constrained();
            $t->string('stock_state', 16);
            $t->decimal('quantity', 18, 4)->default(0);
            // Only meaningful for the available state.
            $t->decimal('reserved_quantity', 18, 4)->default(0);
            $t->timestamps();

            $t->unique(['warehouse_id', 'batch_id', 'stock_state'], 'inventory_balance_key');
            $t->index(['organization_id', 'product_id', 'stock_state']);
        });

        Schema::create('stock_reservations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('warehouse_id')->constrained();
            $t->foreignUlid('product_id')->constrained();
            $t->foreignUlid('batch_id')->constrained();
            $t->decimal('quantity', 18, 4);
            $t->string('reference_type', 40);
            $t->ulid('reference_id');
            // active | consumed | released
            $t->string('status', 16)->default('active');
            $t->dateTime('expires_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['reference_type', 'reference_id']);
            $t->index(['organization_id', 'status', 'expires_at']);
        });

        Schema::create('stock_adjustments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('warehouse_id')->constrained();
            // damage | expiry | theft | found | count_correction | write_off | quality | other
            $t->string('reason_code', 32);
            $t->text('notes')->nullable();
            $t->ulid('posting_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('posted_at')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('batch_id')->constrained();
            $t->string('stock_state', 16);
            $t->decimal('quantity_delta', 18, 4);
        });

        Schema::create('stock_transfers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('from_warehouse_id')->constrained('warehouses');
            $t->foreignUlid('to_warehouse_id')->constrained('warehouses');
            // draft | dispatched | received | partially_received | cancelled
            $t->string('status', 20)->default('draft');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('dispatched_at')->nullable();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('received_at')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('stock_transfer_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            // NULL until dispatch, when FEFO chooses (unless the user specified one).
            $t->foreignUlid('batch_id')->nullable()->constrained();
            $t->decimal('quantity', 18, 4);
            $t->decimal('received_quantity', 18, 4)->default(0);
        });

        Schema::create('stock_counts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('warehouse_id')->constrained();
            // open | posted | cancelled
            $t->string('status', 16)->default('open');
            $t->text('notes')->nullable();
            $t->ulid('posting_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('posted_at')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
        });

        Schema::create('stock_count_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('stock_count_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('batch_id')->constrained();
            $t->decimal('counted_quantity', 18, 4);
            // Recorded at posting time, from the locked balance.
            $t->decimal('system_quantity', 18, 4)->nullable();

            $t->unique(['stock_count_id', 'batch_id']);
        });

        // Per-organization document numbering (ADJ-000001, TRF-000001 …).
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('prefix', 8);
            $t->unsignedBigInteger('next_value')->default(1);

            $t->primary(['organization_id', 'prefix']);
        });
    }

    public function down(): void
    {
        foreach (['document_sequences', 'stock_count_lines', 'stock_counts', 'stock_transfer_lines', 'stock_transfers',
            'stock_adjustment_lines', 'stock_adjustments', 'stock_reservations', 'inventory_balances', 'inventory_movements',
            'bin_locations', 'batches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
