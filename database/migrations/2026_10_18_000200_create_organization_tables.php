<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('type', 40)->index();
            $table->char('country_code', 2);
            $table->string('business_registration_number', 64)->nullable();
            $table->string('tax_identification_number', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website_url')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state_region', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            // Internal platform verification only — never a regulator approval.
            $table->string('verification_status', 24)->default('unverified')->index();
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('suspended_at')->nullable();
            $table->json('branding')->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('country_code')->references('code')->on('countries');
        });

        Schema::create('organization_verifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            // submitted | approved | rejected | changes_requested | revoked
            $table->string('action', 24);
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->text('notes')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('organization_modules', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('module', 32);
            $table->boolean('enabled')->default(true);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->primary(['organization_id', 'module']);
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            // When false, access is limited to the branches/warehouses in membership_location_scopes.
            $table->boolean('all_locations')->default(true);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->json('role_codes');
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'email']);
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            // head_office | branch | retail_outlet | department
            $table->string('type', 24)->default('branch');
            $table->string('address_line1')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state_region', 100)->nullable();
            $table->string('phone', 32)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            // general | cold_room | quarantine | bonded | retail_store
            $table->string('type', 24)->default('general');
            $table->string('address_line1')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('membership_location_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('membership_id')->constrained('organization_memberships')->cascadeOnDelete();
            $table->string('location_type', 16); // branch | warehouse
            $table->ulid('location_id');

            $table->unique(['membership_id', 'location_type', 'location_id'], 'membership_location_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_location_scopes');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organization_modules');
        Schema::dropIfExists('organization_verifications');
        Schema::dropIfExists('organizations');
    }
};
