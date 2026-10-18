<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmaceutical Product Master. Everything here except
 * organization_products and product_change_requests is platform-shared
 * data governed by catalogue.manage / catalogue.review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->char('country_code', 2)->nullable(); // ISO 3166-1 alpha-2, any country
            // Set when the manufacturer is itself a platform participant.
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('website_url')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['name', 'country_code']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('manufacturer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['manufacturer_id', 'name']);
            $table->index('name');
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('inn_name')->nullable();
            $table->string('atc_code', 16)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('platform_sku', 32)->unique();
            $table->string('generic_name');
            $table->foreignUlid('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('display_name');
            $table->string('strength_text', 100)->nullable();
            $table->string('dosage_form_code', 32);
            $table->string('route_code', 32)->nullable();
            $table->foreignUlid('manufacturer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('marketing_authorization_holder')->nullable();
            $table->char('country_of_origin', 2)->nullable();
            $table->foreignUlid('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->text('description')->nullable();
            $table->text('storage_conditions')->nullable();
            $table->decimal('temperature_min_c', 5, 2)->nullable();
            $table->decimal('temperature_max_c', 5, 2)->nullable();
            $table->boolean('requires_cold_chain')->default(false);
            // active | inactive | discontinued | under_review
            $table->string('status', 20)->default('under_review')->index();
            $table->ulid('submitted_by_organization_id')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // Optimistic concurrency for governed edits.
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('dosage_form_code')->references('code')->on('dosage_forms');
            $table->foreign('route_code')->references('code')->on('administration_routes');
            $table->index('generic_name');
            $table->index('display_name');
        });

        Schema::create('product_ingredients', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('ingredient_id')->constrained();
            $table->decimal('strength_value', 18, 6);
            $table->string('strength_unit', 32);
            // e.g. 250 mg per 5 ml -> per_value 5, per_unit ml
            $table->decimal('per_value', 18, 6)->nullable();
            $table->string('per_unit', 32)->nullable();
            $table->unsignedTinyInteger('sequence')->default(1);

            $table->primary(['product_id', 'ingredient_id']);
            $table->foreign('strength_unit')->references('code')->on('units_of_measure');
            $table->foreign('per_unit')->references('code')->on('units_of_measure');
        });

        Schema::create('product_package_levels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level'); // 0 = smallest unit
            $table->string('code', 32);           // unit | blister | retail_pack | carton | shipping_case | ...
            $table->string('name');
            $table->string('unit_code', 32);
            // How many of the level below this level contains (NULL for level 0).
            $table->decimal('quantity_of_child', 18, 4)->nullable();
            // Derived: how many level-0 units this level contains.
            $table->decimal('base_unit_quantity', 18, 4);
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_orderable')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'level']);
            $table->unique(['product_id', 'code']);
            $table->foreign('unit_code')->references('code')->on('units_of_measure');
        });

        Schema::create('product_identifiers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('package_level_id')->nullable()->constrained('product_package_levels')->nullOnDelete();
            $table->string('type', 24); // gtin | manufacturer_code | other
            $table->string('value', 64);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['type', 'value']);
        });

        Schema::create('product_classifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->char('country_code', 2);
            // otc | pharmacy_only | prescription_only | controlled
            $table->string('classification', 24);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'country_code']);
            $table->foreign('country_code')->references('code')->on('countries');
        });

        // Jurisdiction-aware and polymorphic: products, manufacturers and organizations
        // can all carry identifiers issued by a regulator (e.g. NAFDAC Registration No.).
        Schema::create('regulatory_identifiers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('subject_type', 32); // product | manufacturer | organization
            $table->ulid('subject_id');
            $table->foreignUlid('authority_id')->constrained('regulatory_authorities');
            $table->char('country_code', 2);
            $table->string('identifier_type', 32); // registration_number | listing_number | licence_number
            $table->string('value', 64);
            // As declared by the submitter. Not evidence of validity.
            $table->string('declared_status', 20)->default('unknown');
            $table->date('registration_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('holder_name')->nullable();
            // unverified | pending | verified | failed | expired | revoked
            $table->string('verification_status', 20)->default('unverified')->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->ulid('last_verification_id')->nullable();
            $table->ulid('submitted_by_organization_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['authority_id', 'identifier_type', 'value']);
            $table->unique(['subject_type', 'subject_id', 'authority_id', 'identifier_type', 'value'], 'regulatory_identifier_subject_unique');
            $table->foreign('country_code')->references('code')->on('countries');
        });

        // Append-only verification history.
        Schema::create('regulatory_verifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('regulatory_identifier_id')->constrained()->cascadeOnDelete();
            // manual_document_review | official_portal_lookup | regulator_api | other
            $table->string('method', 32);
            $table->string('source')->nullable();
            // verified | failed | inconclusive | expired | revoked
            $table->string('result', 20);
            $table->text('notes')->nullable();
            $table->ulid('evidence_attachment_id')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['regulatory_identifier_id', 'verified_at'], 'regulatory_verifications_identifier_time_idx');
        });

        Schema::create('product_change_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16); // create | update
            $table->json('payload');
            $table->unsignedInteger('base_version')->nullable();
            // pending | approved | rejected | withdrawn
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });

        // Tenant-owned view of a canonical product.
        Schema::create('organization_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained();
            $table->string('sku', 64);
            $table->string('local_name')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('default_purchase_cost', 19, 4)->nullable();
            $table->decimal('default_selling_price', 19, 4)->nullable();
            $table->string('tax_category_code', 32)->nullable();
            // FK to suppliers is added when the procurement module lands.
            $table->ulid('preferred_supplier_id')->nullable();
            $table->decimal('reorder_level', 18, 4)->nullable();
            $table->decimal('reorder_quantity', 18, 4)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'product_id']);
            $table->unique(['organization_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_products');
        Schema::dropIfExists('product_change_requests');
        Schema::dropIfExists('regulatory_verifications');
        Schema::dropIfExists('regulatory_identifiers');
        Schema::dropIfExists('product_classifications');
        Schema::dropIfExists('product_identifiers');
        Schema::dropIfExists('product_package_levels');
        Schema::dropIfExists('product_ingredients');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('manufacturers');
    }
};
