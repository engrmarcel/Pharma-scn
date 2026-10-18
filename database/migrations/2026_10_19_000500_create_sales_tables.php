<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales (spec §10.5): price lists, effective-dated tax rates, quotations,
 * sales orders (FEFO reservations), deliveries (COGS), invoices (immutable
 * once issued), credit notes (with optional returns). POS (Phase 2.5)
 * reuses these services.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Platform-shared tax configuration, effective-dated per country and category.
        Schema::create('tax_rates', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->char('country_code', 2);
            $t->string('category_code', 32);
            $t->string('name');
            $t->decimal('rate', 7, 4);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('source')->nullable();
            $t->timestamps();

            $t->index(['country_code', 'category_code', 'effective_from']);
            $t->foreign('country_code')->references('code')->on('countries');
        });

        Schema::create('price_lists', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 32);
            $t->string('name');
            $t->char('currency_code', 3);
            $t->foreignUlid('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('is_default')->default(false);
            $t->string('status', 16)->default('active');
            $t->timestamps();

            $t->unique(['organization_id', 'code']);
        });

        Schema::create('price_list_items', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('price_list_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            $t->string('package_level_code', 32);
            $t->decimal('unit_price', 19, 4);
            $t->decimal('minimum_quantity', 18, 4)->default(1);
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();

            $t->index(['price_list_id', 'product_id']);
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->foreignUlid('price_list_id')->nullable()->after('credit_limit')->constrained()->nullOnDelete();
        });

        $commercialLines = function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('product_id')->constrained();
            $t->string('package_level_code', 32);
            $t->decimal('quantity', 18, 4);
            $t->decimal('base_quantity', 18, 4);
            $t->decimal('unit_price', 19, 4);
            $t->decimal('list_price', 19, 4)->nullable();
            $t->decimal('discount_percent', 7, 4)->default(0);
            $t->string('tax_category_code', 32);
            $t->decimal('tax_rate', 7, 4)->default(0);
            $t->decimal('line_subtotal', 19, 4);  // quantity × unit price
            $t->decimal('line_discount', 19, 4);
            $t->decimal('line_tax', 19, 4);       // on subtotal − discount
            $t->decimal('line_total', 19, 4);
        };
        $commercialHeader = function (Blueprint $t, string $prefixStatusDefault) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('customer_id')->constrained();
            $t->foreignUlid('warehouse_id')->constrained();
            $t->string('status', 24)->default($prefixStatusDefault);
            $t->char('currency_code', 3);
            $t->decimal('subtotal', 19, 4)->default(0);
            $t->decimal('discount_total', 19, 4)->default(0);
            $t->decimal('tax_total', 19, 4)->default(0);
            $t->decimal('total', 19, 4)->default(0);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            $t->index(['organization_id', 'customer_id']);
        };

        Schema::create('quotations', function (Blueprint $t) use ($commercialHeader) {
            $commercialHeader($t, 'draft'); // draft | sent | accepted | expired | cancelled
            $t->date('valid_until')->nullable();
            $t->ulid('sales_order_id')->nullable();
        });
        Schema::create('quotation_lines', function (Blueprint $t) use ($commercialLines) {
            $commercialLines($t);
            $t->foreignUlid('quotation_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('sales_orders', function (Blueprint $t) use ($commercialHeader) {
            // draft | pending_approval | confirmed | partially_delivered | delivered | closed | cancelled
            $commercialHeader($t, 'draft');
            $t->foreignUlid('quotation_id')->nullable()->constrained()->nullOnDelete();
            $t->date('order_date')->nullable();
            $t->date('requested_date')->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(0);
            $t->json('approval_reasons')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('approved_at')->nullable();
            $t->text('status_reason')->nullable();
        });
        Schema::create('sales_order_lines', function (Blueprint $t) use ($commercialLines) {
            $commercialLines($t);
            $t->foreignUlid('sales_order_id')->constrained()->cascadeOnDelete();
            $t->decimal('delivered_base_quantity', 18, 4)->default(0);
            $t->decimal('invoiced_base_quantity', 18, 4)->default(0);
        });

        Schema::create('deliveries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('sales_order_id')->constrained();
            $t->foreignUlid('customer_id')->constrained();
            $t->foreignUlid('warehouse_id')->constrained();
            $t->ulid('posting_id')->nullable();
            $t->ulid('journal_entry_id')->nullable();
            $t->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('delivered_at');
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
        });
        Schema::create('delivery_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('delivery_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('sales_order_line_id')->constrained();
            $t->foreignUlid('batch_id')->constrained();
            $t->decimal('base_quantity', 18, 4);
            $t->decimal('unit_cost', 19, 4)->nullable();
        });

        Schema::create('sales_invoices', function (Blueprint $t) use ($commercialHeader) {
            $commercialHeader($t, 'issued'); // issued | void (void only via full credit note)
            $t->foreignUlid('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('source', 16)->default('sales_order'); // sales_order | pos
            $t->date('invoice_date');
            $t->date('due_date')->nullable();
            $t->decimal('amount_paid', 19, 4)->default(0);
            $t->decimal('amount_credited', 19, 4)->default(0);
            $t->string('payment_status', 16)->default('unpaid'); // unpaid | partial | paid
            $t->ulid('journal_entry_id')->nullable();
        });
        Schema::create('sales_invoice_lines', function (Blueprint $t) use ($commercialLines) {
            $commercialLines($t);
            $t->foreignUlid('sales_invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('sales_order_line_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('credited_base_quantity', 18, 4)->default(0);
        });

        Schema::create('credit_notes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('sales_invoice_id')->constrained();
            $t->foreignUlid('customer_id')->constrained();
            // return | price_adjustment | damaged_in_transit | other
            $t->string('reason_code', 32);
            $t->text('notes')->nullable();
            $t->date('credit_date');
            $t->char('currency_code', 3);
            $t->decimal('subtotal', 19, 4);
            $t->decimal('tax_total', 19, 4);
            $t->decimal('total', 19, 4);
            $t->foreignUlid('return_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $t->ulid('posting_id')->nullable();
            $t->ulid('journal_entry_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
        });
        Schema::create('credit_note_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('credit_note_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('sales_invoice_line_id')->constrained();
            $t->decimal('base_quantity', 18, 4);
            $t->decimal('line_subtotal', 19, 4);
            $t->decimal('line_tax', 19, 4);
            // Returned stock: batch and the state it is restocked into (quarantined unless explicitly released).
            $t->foreignUlid('batch_id')->nullable()->constrained();
            $t->string('restock_state', 16)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['credit_note_lines', 'credit_notes', 'sales_invoice_lines', 'sales_invoices', 'delivery_lines', 'deliveries',
            'sales_order_lines', 'sales_orders', 'quotation_lines', 'quotations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('customers', fn (Blueprint $t) => $t->dropConstrainedForeignId('price_list_id'));
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('tax_rates');
    }
};
