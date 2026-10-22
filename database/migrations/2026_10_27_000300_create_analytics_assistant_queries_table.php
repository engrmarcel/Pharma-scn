<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8c: log of reporting-assistant questions (redacted), which planner answered, which
 * reports ran and with what outcome. Kept for analytics.assistant.log_retention_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_assistant_queries', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('question', 1000);           // redacted
            $t->string('planner', 64);              // rules-v1 | anthropic:<model>
            $t->json('tools');
            $t->json('statuses');
            $t->json('rejected_tools')->nullable(); // plan entries that were not catalogue reports
            $t->unsignedInteger('duration_ms');
            $t->timestamp('created_at');

            $t->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_assistant_queries');
    }
};
