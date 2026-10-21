<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace trading (spec §14.2, Phase 4b): RFQs with invitations, supplier
 * quotations, and B2B orders that link the buyer's purchase order and the
 * supplier's sales order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('buyer_organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('number', 32);
            $t->string('title');
            $t->string('status', 16)->default('draft'); // draft | open | closed | awarded | cancelled
            $t->dateTime('response_deadline');
            $t->foreignUlid('delivery_warehouse_id')->constrained('warehouses');
            $t->char('currency_code', 3);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('opened_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->timestamps();

            $t->unique(['buyer_organization_id', 'number']);
        });

        Schema::create('rfq_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('rfq_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            $t->string('package_level_code', 32);
            $t->decimal('quantity', 18, 4);
            $t->string('notes')->nullable();
        });

        Schema::create('rfq_invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('rfq_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('supplier_organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('status', 16)->default('invited'); // invited | quoted | declined
            $t->text('decline_reason')->nullable();
            $t->dateTime('responded_at')->nullable();
            $t->timestamps();

            $t->unique(['rfq_id', 'supplier_organization_id']);
            $t->index(['supplier_organization_id', 'status']);
        });

        Schema::create('marketplace_quotes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 32);
            $t->foreignUlid('rfq_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('buyer_organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUlid('supplier_organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('status', 16)->default('submitted'); // submitted | withdrawn | accepted | rejected | expired
            $t->date('valid_until');
            $t->char('currency_code', 3);
            $t->decimal('subtotal', 19, 4)->default(0); // before tax; the supplier's ERP adds tax on the sales order
            $t->text('notes')->nullable();
            $t->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('decided_at')->nullable();
            $t->text('decision_notes')->nullable();
            $t->timestamps();

            $t->unique(['supplier_organization_id', 'number']);
            $t->index(['buyer_organization_id', 'status']);
        });

        Schema::create('marketplace_quote_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('marketplace_quote_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('rfq_line_id')->nullable();
            $t->foreignUlid('product_id')->constrained();
            $t->string('package_level_code', 32);
            $t->decimal('quantity', 18, 4);
            $t->decimal('unit_price', 19, 4);
            $t->decimal('line_total', 19, 4);
            $t->unsignedSmallInteger('lead_time_days')->nullable();
        });

        Schema::create('marketplace_orders', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 32);
            $t->foreignUlid('buyer_organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUlid('supplier_organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUlid('trading_relationship_id')->constrained();
            $t->foreignUlid('marketplace_quote_id')->nullable()->constrained()->nullOnDelete();
            // draft | pending_approval | submitted | accepted | rejected | cancelled
            // | partially_shipped | shipped | partially_received | received | completed (4c)
            $t->string('status', 24)->default('draft');
            $t->char('currency_code', 3);
            $t->decimal('subtotal', 19, 4)->default(0);
            $t->foreignUlid('delivery_warehouse_id')->constrained('warehouses');
            $t->date('requested_date')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('submitted_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('decided_at')->nullable();
            $t->text('decision_notes')->nullable();
            $t->text('cancel_reason')->nullable();
            // Each side's ERP document.
            $t->foreignUlid('buyer_purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $t->foreignUlid('supplier_sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $t->timestamps();

            $t->unique(['buyer_organization_id', 'number']);
            $t->index(['supplier_organization_id', 'status']);
        });

        Schema::create('marketplace_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('marketplace_order_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('marketplace_listing_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('marketplace_quote_line_id')->nullable();
            $t->foreignUlid('product_id')->constrained();
            $t->string('package_level_code', 32);
            $t->decimal('quantity', 18, 4);
            $t->decimal('unit_price', 19, 4);
            $t->decimal('line_total', 19, 4);
            $t->decimal('shipped_quantity', 18, 4)->default(0);
            $t->decimal('received_quantity', 18, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_lines');
        Schema::dropIfExists('marketplace_orders');
        Schema::dropIfExists('marketplace_quote_lines');
        Schema::dropIfExists('marketplace_quotes');
        Schema::dropIfExists('rfq_invitations');
        Schema::dropIfExists('rfq_lines');
        Schema::dropIfExists('rfqs');
    }
};
