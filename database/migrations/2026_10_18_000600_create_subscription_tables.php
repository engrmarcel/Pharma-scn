<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription and entitlement foundations (spec §21). Invoicing, renewal
 * and payment collection build on these tables in a later phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('billing_period', 16)->default('monthly'); // monthly | annual | custom
            // NULL = priced by agreement. Prices are commercial configuration, not code.
            $table->decimal('price', 19, 4)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->foreignUlid('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('key', 64);
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('limit_value')->nullable(); // NULL = unlimited
            $table->timestamps();

            $table->primary(['plan_id', 'key']);
        });

        Schema::create('organization_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('plan_id')->constrained('subscription_plans');
            // trialing | active | past_due | grace | suspended | cancelled | expired
            $table->string('status', 16);
            $table->dateTime('starts_at');
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('current_period_ends_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            // Set when superseded by a plan change; history is kept.
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'ended_at']);
        });

        // Negotiated exceptions for custom enterprise agreements.
        Schema::create('organization_entitlement_overrides', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->primary(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_entitlement_overrides');
        Schema::dropIfExists('organization_subscriptions');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('subscription_plans');
    }
};
