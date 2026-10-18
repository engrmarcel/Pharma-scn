<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned trading parties (spec §10.4, §10.5, §12.1). A party may be
 * linked to a platform organization when the counterparty is itself on the
 * network; the link never grants access to that organization's data.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['suppliers', 'customers'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->ulid('id')->primary();
                $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
                $t->string('code', 32);
                $t->string('name');
                $t->string('type', 32);
                $t->foreignUlid('linked_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
                $t->string('tax_identification_number', 64)->nullable();
                $t->string('email')->nullable();
                $t->string('phone', 32)->nullable();
                $t->string('address_line1')->nullable();
                $t->string('city', 100)->nullable();
                $t->string('state_region', 100)->nullable();
                $t->char('country_code', 2)->nullable();
                $t->char('currency_code', 3);
                $t->unsignedSmallInteger('payment_terms_days')->default(0);
                $t->decimal('credit_limit', 19, 4)->nullable();
                if ($table === 'suppliers') {
                    $t->unsignedSmallInteger('lead_time_days')->nullable();
                    $t->decimal('minimum_order_value', 19, 4)->nullable();
                } else {
                    // Premises/practice licence as stated by the customer; verification is a separate concern.
                    $t->string('licence_reference', 64)->nullable();
                    $t->decimal('latitude', 10, 7)->nullable();
                    $t->decimal('longitude', 10, 7)->nullable();
                }
                // active | on_hold | inactive
                $t->string('status', 16)->default('active');
                $t->text('notes')->nullable();
                $t->timestamps();

                $t->unique(['organization_id', 'code']);
                $t->index(['organization_id', 'name']);
            });
        }

        Schema::create('party_contacts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('party_type', 16); // supplier | customer
            $t->ulid('party_id');
            $t->string('name');
            $t->string('job_title')->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 32)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->timestamps();

            $t->index(['organization_id', 'party_type', 'party_id']);
        });

        // Wire the Phase 1 placeholder column to its table now that it exists.
        Schema::table('organization_products', function (Blueprint $t) {
            $t->foreign('preferred_supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organization_products', function (Blueprint $t) {
            $t->dropForeign(['preferred_supplier_id']);
        });
        Schema::dropIfExists('party_contacts');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('suppliers');
    }
};
