<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS (spec §11). A POS sale is a front end over the ERP: completion
 * creates a sales invoice (source "pos"), stock movements, journal entries
 * and finance payments through the same services as back-office sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_terminals', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('warehouse_id')->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->string('status', 16)->default('active'); // active | disabled
            $t->string('device_identifier', 128)->nullable();
            $t->dateTime('last_seen_at')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'code']);
        });

        Schema::create('pos_shifts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('pos_terminal_id')->constrained();
            $t->foreignId('cashier_id')->constrained('users');
            // open | closed | reviewed
            $t->string('status', 16)->default('open');
            $t->dateTime('opened_at');
            $t->decimal('opening_float', 19, 4);
            $t->dateTime('closed_at')->nullable();
            $t->decimal('expected_cash', 19, 4)->nullable();
            $t->decimal('counted_cash', 19, 4)->nullable();
            $t->decimal('variance', 19, 4)->nullable();
            $t->text('closing_notes')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('reviewed_at')->nullable();
            $t->text('review_notes')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            $t->index(['pos_terminal_id', 'status']);
            $t->index(['cashier_id', 'status']);
        });

        Schema::create('pos_cash_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('pos_shift_id')->constrained()->cascadeOnDelete();
            // opening_float | sale | change | cash_in | cash_out | refund
            $t->string('type', 16);
            $t->decimal('amount', 19, 4); // signed: + into the drawer, − out of it
            $t->string('reason')->nullable();
            $t->ulid('pos_sale_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('pos_sales', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            // Client-generated id, unique per organization: duplicates from retries/offline sync are detected (Phase 3).
            $t->ulid('client_transaction_id');
            $t->foreignUlid('pos_shift_id')->constrained();
            $t->foreignUlid('pos_terminal_id')->constrained();
            $t->foreignId('cashier_id')->constrained('users');
            $t->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('warehouse_id')->constrained();
            // open | held | awaiting_payment | completed | cancelled
            $t->string('status', 20)->default('open');
            $t->char('currency_code', 3);
            $t->decimal('subtotal', 19, 4)->default(0);
            $t->decimal('discount_total', 19, 4)->default(0);
            $t->decimal('tax_total', 19, 4)->default(0);
            $t->decimal('total', 19, 4)->default(0);
            $t->decimal('change_due', 19, 4)->default(0);
            $t->foreignUlid('sales_invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('completed_at')->nullable();
            $t->text('notes')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            $t->unique(['organization_id', 'client_transaction_id']);
            $t->index(['organization_id', 'status', 'completed_at']);
        });

        Schema::create('pos_sale_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('pos_sale_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            $t->string('package_level_code', 32);
            $t->decimal('quantity', 18, 4);
            $t->decimal('base_quantity', 18, 4);
            $t->decimal('unit_price', 19, 4);
            $t->decimal('list_price', 19, 4)->nullable();
            $t->decimal('discount_percent', 7, 4)->default(0);
            $t->string('tax_category_code', 32);
            $t->decimal('tax_rate', 7, 4);
            $t->decimal('line_subtotal', 19, 4);
            $t->decimal('line_discount', 19, 4);
            $t->decimal('line_tax', 19, 4);
            $t->decimal('line_total', 19, 4);
            // Scanned batch (validated as sellable); otherwise FEFO decides at checkout.
            $t->foreignUlid('requested_batch_id')->nullable()->constrained('batches')->nullOnDelete();
            // Prescription evidence for prescription-only / controlled products.
            $t->json('prescription')->nullable();
            // Set at completion: the invoice line this POS line became (refunds credit exactly this line).
            $t->foreignUlid('sales_invoice_line_id')->nullable()->constrained()->nullOnDelete();
        });

        // Batch evidence: which batches each line actually consumed (traceability, recalls).
        Schema::create('pos_sale_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('pos_sale_line_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('batch_id')->constrained();
            $t->decimal('base_quantity', 18, 4);
            $t->decimal('unit_cost', 19, 4)->nullable();
        });

        Schema::create('pos_payments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('pos_sale_id')->constrained()->cascadeOnDelete();
            // cash | card | bank_transfer | mobile_money
            $t->string('method', 16);
            // cash | manual_terminal | <provider code> (e.g. fake for development)
            $t->string('channel', 32);
            $t->decimal('amount', 19, 4);
            $t->decimal('tendered', 19, 4)->nullable(); // cash handed over (≥ amount)
            // pending | confirmed | failed | cancelled
            $t->string('status', 16);
            $t->string('provider_reference', 128)->nullable();
            $t->string('external_approval_code', 64)->nullable();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('confirmed_at')->nullable();
            $t->ulid('finance_payment_id')->nullable();
            $t->timestamps();

            $t->unique(['channel', 'provider_reference']);
        });

        // Every provider callback is stored once; duplicates are acknowledged without effect.
        Schema::create('payment_provider_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 32);
            $t->string('event_id', 128);
            $t->string('reference', 128)->nullable();
            $t->string('event_type', 64);
            $t->json('payload');
            $t->string('outcome', 32)->nullable();
            $t->timestamp('received_at')->useCurrent();

            $t->unique(['provider', 'event_id']);
        });

        Schema::create('pos_refunds', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('pos_sale_id')->constrained();
            $t->foreignUlid('pos_shift_id')->nullable()->constrained()->nullOnDelete();
            // pending_approval | approved | rejected
            $t->string('status', 20)->default('pending_approval');
            $t->string('reason');
            $t->string('refund_method', 16); // cash | original_method
            $t->json('lines'); // [{pos_sale_line_id, quantity, restock_state}]
            $t->decimal('amount', 19, 4)->nullable();
            $t->foreignUlid('credit_note_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('decided_at')->nullable();
            $t->text('decision_notes')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
        });
    }

    public function down(): void
    {
        foreach (['pos_refunds', 'payment_provider_events', 'pos_payments', 'pos_sale_allocations', 'pos_sale_lines', 'pos_sales',
            'pos_cash_movements', 'pos_shifts', 'pos_terminals'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
