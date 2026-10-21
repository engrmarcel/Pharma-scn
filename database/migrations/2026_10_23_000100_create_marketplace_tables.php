<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B marketplace foundations (spec §14, Phase 4a). Marketplace records are
 * shared between two organizations, so they carry explicit buyer/supplier
 * organization ids instead of a single tenant id; each side's ERP records
 * (suppliers, customers, purchase and sales orders) stay in its own tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_profiles', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $t->boolean('listed')->default(false);           // visible to buyers in discovery
            $t->string('headline')->nullable();
            $t->text('description')->nullable();
            $t->json('regions_served')->nullable();           // states/regions the supplier delivers to
            $t->json('delivery_options')->nullable();         // delivery | pickup | courier
            $t->decimal('minimum_order_value', 19, 4)->nullable();
            $t->char('currency_code', 3);
            $t->string('contact_email')->nullable();
            $t->string('contact_phone', 32)->nullable();
            $t->timestamps();
        });

        Schema::create('marketplace_listings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete(); // the supplier
            $t->foreignUlid('product_id')->constrained();
            $t->string('package_level_code', 32);
            $t->decimal('unit_price', 19, 4);
            $t->char('currency_code', 3);
            $t->decimal('minimum_order_quantity', 18, 4)->default(1);
            // live: computed from the supplier's ERP stock when read (never reserved by being shown)
            // declared: the supplier's own statement, shown with its date and flagged when stale
            $t->string('availability_mode', 16)->default('live');
            $t->string('declared_availability', 16)->nullable(); // in_stock | limited | out_of_stock | on_request
            $t->dateTime('declared_at')->nullable();
            $t->foreignUlid('warehouse_id')->nullable()->constrained()->nullOnDelete(); // live stock source; null = all
            $t->unsignedSmallInteger('lead_time_days')->nullable();
            $t->json('allowed_buyer_types')->nullable();         // narrows the default trading matrix
            $t->string('status', 16)->default('active');         // active | paused
            $t->timestamps();

            $t->unique(['organization_id', 'product_id', 'package_level_code'], 'marketplace_listing_unique');
            $t->index(['status', 'product_id']);
        });

        Schema::create('trading_relationships', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('buyer_organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUlid('supplier_organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('status', 16)->default('requested'); // requested | approved | rejected | suspended
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('request_message')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('decided_at')->nullable();
            $t->text('decision_notes')->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(0);
            // The ERP parties on each side, created by that side's own users.
            $t->foreignUlid('buyer_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $t->foreignUlid('supplier_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->timestamps();

            $t->unique(['buyer_organization_id', 'supplier_organization_id'], 'trading_relationship_unique');
            $t->index(['supplier_organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_relationships');
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('marketplace_profiles');
    }
};
