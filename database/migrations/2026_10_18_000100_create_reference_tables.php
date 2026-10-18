<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Country configuration and shared reference data. Countries here are the
 * platform's operating markets (FK target for organizations); other ISO
 * codes such as a product's country of origin are stored as plain codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->char('code', 2)->primary();
            $table->string('name');
            $table->char('currency_code', 3);
            $table->string('timezone', 64);
            $table->string('default_locale', 10);
            $table->string('phone_code', 8)->nullable();
            $table->boolean('is_active')->default(false);
            // Effective-dated rule sets (tax, invoicing) land in their own tables later;
            // this holds display/format config and onboarding document requirements.
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('regulatory_authorities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('country_code', 2);
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('website_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('country_code')->references('code')->on('countries');
        });

        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->string('code', 32)->primary();
            $table->string('name');
            // mass | volume | count | activity | other
            $table->string('dimension', 16);
        });

        Schema::create('dosage_forms', function (Blueprint $table) {
            $table->string('code', 32)->primary();
            $table->string('name');
        });

        Schema::create('administration_routes', function (Blueprint $table) {
            $table->string('code', 32)->primary();
            $table->string('name');
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('administration_routes');
        Schema::dropIfExists('dosage_forms');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('regulatory_authorities');
        Schema::dropIfExists('countries');
    }
};
