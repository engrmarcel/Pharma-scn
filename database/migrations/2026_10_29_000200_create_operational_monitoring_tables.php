<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10b: operational monitoring.
 *   operational_heartbeats  last beat of the scheduler and of each queue's workers
 *   operational_alerts      one row per problem episode: opened when a check crosses its
 *                           threshold, resolved when it recovers (platform-level, no tenant data)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_heartbeats', function (Blueprint $t) {
            $t->string('name', 64)->primary();
            $t->dateTime('beat_at');
        });

        Schema::create('operational_alerts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('check', 64);
            $t->string('subject', 64)->default(''); // e.g. the queue name
            $t->string('severity', 16);             // warning | critical
            $t->string('status', 16)->default('open'); // open | resolved
            $t->string('message', 500);
            $t->decimal('value', 18, 2)->nullable();
            $t->decimal('peak_value', 18, 2)->nullable();
            $t->dateTime('opened_at');
            $t->dateTime('last_seen_at');
            $t->dateTime('resolved_at')->nullable();
            $t->dateTime('notified_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'check', 'subject']);
            $t->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_alerts');
        Schema::dropIfExists('operational_heartbeats');
    }
};
