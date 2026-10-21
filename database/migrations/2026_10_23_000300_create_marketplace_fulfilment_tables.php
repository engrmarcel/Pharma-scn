<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace fulfilment (spec §14.2, Phase 4c): shipments carrying batch
 * evidence from the supplier's delivery to the buyer's goods receipt,
 * invoices with payment status, the platform fee ledger, and disputes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_order_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('buyer_purchase_order_line_id')->nullable();
            $t->ulid('supplier_sales_order_line_id')->nullable();
        });

        Schema::create('marketplace_shipments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('marketplace_order_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->foreignUlid('supplier_delivery_id')->nullable()->constrained('deliveries')->nullOnDelete();
            $t->string('carrier', 100)->nullable();
            $t->string('tracking_reference', 100)->nullable();
            $t->string('status', 16)->default('in_transit'); // in_transit | received | received_short
            $t->dateTime('shipped_at');
            $t->dateTime('received_at')->nullable();
            $t->foreignUlid('buyer_goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('marketplace_shipment_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('marketplace_shipment_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('marketplace_order_line_id');
            $t->foreignUlid('product_id')->constrained();
            $t->string('package_level_code', 32);
            $t->decimal('quantity', 18, 4);
            $t->decimal('received_quantity', 18, 4)->default(0);
            $t->json('batches'); // [{batch_number, expiry_date, base_quantity}] as issued by the supplier
        });

        Schema::create('marketplace_invoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('marketplace_order_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('supplier_sales_invoice_id')->constrained('sales_invoices');
            $t->string('number', 32);              // the supplier's invoice number
            $t->char('currency_code', 3);
            $t->decimal('net_amount', 19, 4);       // after discount, before tax: the fee basis
            $t->decimal('tax_amount', 19, 4);
            $t->decimal('total', 19, 4);
            $t->date('invoice_date');
            $t->date('due_date')->nullable();
            $t->json('lines');                      // [{marketplace_order_line_id, product_id, package_level_code, quantity, unit_price, tax_rate}]
            $t->foreignUlid('buyer_supplier_invoice_id')->nullable()->constrained('supplier_invoices')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('marketplace_fees', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('marketplace_order_id')->constrained();
            $t->foreignUlid('marketplace_invoice_id')->unique()->constrained();
            $t->foreignUlid('supplier_organization_id')->constrained('organizations');
            $t->char('currency_code', 3);
            $t->decimal('basis_amount', 19, 4);
            $t->decimal('rate_percent', 7, 4);
            $t->decimal('amount', 19, 4);
            $t->string('status', 16)->default('accrued'); // accrued | waived
            $t->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('waived_at')->nullable();
            $t->text('waive_reason')->nullable();
            $t->timestamps();

            $t->index(['supplier_organization_id', 'created_at']);
        });

        Schema::create('marketplace_disputes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 32);
            $t->foreignUlid('marketplace_order_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('buyer_organization_id')->constrained('organizations');
            $t->foreignUlid('supplier_organization_id')->constrained('organizations');
            $t->string('raised_by_side', 8);        // buyer | supplier
            // damaged | short_delivery | wrong_item | quality | expiry | pricing | other
            $t->string('type', 24);
            $t->string('requested_resolution', 24); // credit | replacement | return_and_credit | other
            $t->text('description');
            $t->json('lines')->nullable();          // [{marketplace_order_line_id, quantity, batch_number?}]
            $t->string('status', 16)->default('open'); // open | resolved | escalated
            $t->string('resolution', 24)->nullable(); // credit_note | replacement | rejected | other
            $t->text('resolution_notes')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('resolved_at')->nullable();
            $t->dateTime('escalated_at')->nullable();
            $t->foreignUlid('supplier_credit_note_id')->nullable()->constrained('credit_notes')->nullOnDelete();
            $t->foreignUlid('buyer_supplier_return_id')->nullable()->constrained('supplier_returns')->nullOnDelete();
            $t->timestamps();

            $t->index(['buyer_organization_id', 'status']);
            $t->index(['supplier_organization_id', 'status']);
        });

        Schema::create('marketplace_dispute_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('marketplace_dispute_id')->constrained()->cascadeOnDelete();
            $t->string('author_side', 8);           // buyer | supplier | platform
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->text('body');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_dispute_messages');
        Schema::dropIfExists('marketplace_disputes');
        Schema::dropIfExists('marketplace_fees');
        Schema::dropIfExists('marketplace_invoices');
        Schema::dropIfExists('marketplace_shipment_lines');
        Schema::dropIfExists('marketplace_shipments');
        Schema::table('marketplace_order_lines', fn (Blueprint $t) => $t->dropColumn(['buyer_purchase_order_line_id', 'supplier_sales_order_line_id']));
    }
};
