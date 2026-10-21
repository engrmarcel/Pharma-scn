<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5c: reliable outbound events (spec §15.3 integration outbox).
 *
 *   integration_outbox      events written in the same transaction as the business change, so an
 *                           event exists exactly when the change does; append-only
 *   webhook_subscriptions   where an organization's events are delivered (HTTPS endpoint, event
 *                           types, encrypted signing secret)
 *   webhook_deliveries      one row per event and subscription: attempts, backoff, outcome, dead letter
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->ulid('event_id')->unique();     // stable id consumers use to discard duplicates
            $t->string('event_type', 64);
            $t->string('aggregate_type', 48)->nullable();
            $t->string('aggregate_id', 64)->nullable();
            $t->json('payload');
            $t->dateTime('occurred_at');
            $t->string('correlation_id', 64)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['organization_id', 'event_type', 'occurred_at']);
        });

        Schema::create('webhook_subscriptions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            // Optional: deliveries then carry this connector's external product keys.
            $t->foreignUlid('connector_id')->nullable()->constrained('integration_connectors')->nullOnDelete();
            $t->string('name', 100);
            $t->string('url', 2048);
            $t->json('event_types');
            $t->text('secret');                 // encrypted
            $t->string('status', 16)->default('active'); // active | paused | disabled
            $t->string('status_reason', 255)->nullable();
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->dateTime('last_delivered_at')->nullable();
            $t->dateTime('last_failure_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['organization_id', 'status']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $t->foreignId('outbox_id')->constrained('integration_outbox')->cascadeOnDelete();
            // pending | delivering | delivered | failed (will retry) | dead (gave up; redeliver manually)
            $t->string('status', 16)->default('pending');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->dateTime('next_attempt_at')->nullable();
            $t->dateTime('last_attempt_at')->nullable();
            $t->unsignedSmallInteger('response_status')->nullable();
            $t->string('response_excerpt', 300)->nullable();
            $t->string('error', 255)->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->timestamps();

            $t->unique(['subscription_id', 'outbox_id']);
            $t->index(['status', 'next_attempt_at']);
            $t->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
        Schema::dropIfExists('integration_outbox');
    }
};
