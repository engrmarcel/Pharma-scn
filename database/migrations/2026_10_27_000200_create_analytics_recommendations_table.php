<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8b: machine-generated recommendations, predictions and anomalies (spec §19.2).
 * They never act by themselves: a person accepts or dismisses each one, and accepting
 * a reorder recommendation creates a draft requisition through the normal procurement flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_recommendations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            // reorder | overstock | expiry_risk | integration_anomaly | traceability_anomaly
            $t->string('type', 32);
            $t->string('kind', 16);                 // recommendation | prediction | anomaly
            $t->string('severity', 16);             // info | warning | critical
            $t->string('subject_key', 160);         // one open recommendation per subject (e.g. reorder:product)
            $t->foreignUlid('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $t->string('subject_type', 32)->nullable(); // connector | batch | identifier
            $t->string('subject_id', 64)->nullable();
            $t->string('title');
            $t->text('rationale');
            $t->json('figures');                    // the numbers behind it (suggested quantity, cover, forecast, error rates…)
            $t->json('assumptions');
            $t->string('generator', 48);            // provider/model and version
            $t->string('status', 16)->default('open'); // open | accepted | dismissed | superseded
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('decided_at')->nullable();
            $t->text('decision_notes')->nullable();
            $t->string('action_type', 40)->nullable();   // e.g. purchase_requisition
            $t->string('action_id', 64)->nullable();
            $t->dateTime('generated_at');
            $t->timestamps();

            $t->index(['organization_id', 'status', 'type'], 'analytics_recs_status_type');
            $t->index(['organization_id', 'subject_key', 'status'], 'analytics_recs_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_recommendations');
    }
};
