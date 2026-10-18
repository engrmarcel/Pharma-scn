<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement (spec §10.4): requisition → approval → purchase order →
 * goods receipt (batch capture, stock via InventoryLedger) → supplier
 * invoice (three-way match) and supplier returns. Money is decimal(19,4);
 * quantities are stored both as entered (package level) and in base units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_price_list_items', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('supplier_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            $t->string('package_level_code', 32);
            $t->decimal('unit_price', 19, 4);
            $t->char('currency_code', 3);
            $t->decimal('minimum_quantity', 18, 4)->default(1);
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->timestamps();

            $t->index(['supplier_id', 'product_id']);
        });

        Schema::create('purchase_requisitions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('warehouse_id')->constrained();
            // draft | submitted | approved | rejected | cancelled | converted
            $t->string('status', 16)->default('draft');
            $t->date('needed_by')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('decided_at')->nullable();
            $t->text('decision_notes')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            $t->index(['organization_id', 'status']);
        });

        Schema::create('purchase_requisition_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('purchase_requisition_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            $t->string('package_level_code', 32);
            $t->decimal('quantity', 18, 4);
            $t->decimal('base_quantity', 18, 4);
            $t->foreignUlid('suggested_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $t->string('notes')->nullable();
        });

        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('supplier_id')->constrained();
            $t->foreignUlid('warehouse_id')->constrained();
            $t->foreignUlid('purchase_requisition_id')->nullable()->constrained()->nullOnDelete();
            // draft | pending_approval | approved | sent | partially_received | received | closed | cancelled | rejected
            $t->string('status', 20)->default('draft');
            $t->char('currency_code', 3);
            $t->date('order_date')->nullable();
            $t->date('expected_date')->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(0);
            $t->decimal('subtotal', 19, 4)->default(0);
            $t->decimal('tax_total', 19, 4)->default(0);
            $t->decimal('total', 19, 4)->default(0);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('sent_at')->nullable();
            $t->text('status_reason')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            $t->index(['organization_id', 'status']);
            $t->index(['organization_id', 'supplier_id']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            $t->string('package_level_code', 32);
            $t->decimal('quantity', 18, 4);       // in package units
            $t->decimal('base_quantity', 18, 4);  // in base units
            $t->decimal('unit_price', 19, 4);     // per package unit, excluding tax
            $t->decimal('tax_rate', 7, 4)->default(0); // percent
            $t->decimal('line_subtotal', 19, 4);
            $t->decimal('line_tax', 19, 4);
            $t->decimal('received_base_quantity', 18, 4)->default(0);
            $t->decimal('invoiced_base_quantity', 18, 4)->default(0);
        });

        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('purchase_order_id')->constrained();
            $t->foreignUlid('supplier_id')->constrained();
            $t->foreignUlid('warehouse_id')->constrained();
            $t->string('supplier_delivery_note', 64)->nullable();
            $t->ulid('posting_id')->nullable();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('received_at');
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('goods_receipt_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('purchase_order_line_id');
            $t->foreignUlid('product_id')->constrained();
            $t->foreignUlid('batch_id')->constrained();
            $t->decimal('quantity', 18, 4);
            $t->decimal('base_quantity', 18, 4);
            $t->string('quality_status', 16);

            $t->foreign('purchase_order_line_id')->references('id')->on('purchase_order_lines');
        });

        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->string('supplier_invoice_number', 64);
            $t->foreignUlid('supplier_id')->constrained();
            $t->foreignUlid('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $t->date('invoice_date');
            $t->date('due_date')->nullable();
            $t->char('currency_code', 3);
            $t->decimal('subtotal', 19, 4);
            $t->decimal('tax_total', 19, 4);
            $t->decimal('total', 19, 4);
            // draft | approved | cancelled  (payment status arrives with finance)
            $t->string('status', 16)->default('draft');
            // matched | variance | unmatched
            $t->string('match_status', 16)->default('unmatched');
            $t->json('match_details')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('approved_at')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            // The same supplier document cannot be entered twice.
            $t->unique(['organization_id', 'supplier_id', 'supplier_invoice_number'], 'supplier_invoice_document_unique');
        });

        Schema::create('supplier_invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('purchase_order_line_id')->nullable();
            $t->string('description');
            $t->decimal('quantity', 18, 4);
            $t->decimal('base_quantity', 18, 4)->nullable();
            $t->decimal('unit_price', 19, 4);
            $t->decimal('tax_rate', 7, 4)->default(0);
            $t->decimal('line_subtotal', 19, 4);
            $t->decimal('line_tax', 19, 4);

            $t->foreign('purchase_order_line_id')->references('id')->on('purchase_order_lines');
        });

        Schema::create('supplier_returns', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('supplier_id')->constrained();
            $t->foreignUlid('warehouse_id')->constrained();
            $t->foreignUlid('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            // damaged | expired | wrong_item | quality | recall | overstock | other
            $t->string('reason_code', 32);
            $t->text('notes')->nullable();
            $t->ulid('posting_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('posted_at')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
        });

        Schema::create('supplier_return_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('supplier_return_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('batch_id')->constrained();
            $t->string('stock_state', 16);
            $t->decimal('base_quantity', 18, 4);
        });
    }

    public function down(): void
    {
        foreach (['supplier_return_lines', 'supplier_returns', 'supplier_invoice_lines', 'supplier_invoices', 'goods_receipt_lines',
            'goods_receipts', 'purchase_order_lines', 'purchase_orders', 'purchase_requisition_lines', 'purchase_requisitions',
            'supplier_price_list_items'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
