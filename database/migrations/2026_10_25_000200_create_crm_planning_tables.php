<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6b, CRM planning and selling (spec §17).
 *
 *   visit_plans, visit_plan_items   a representative's planned calls for a period, approved by a supervisor;
 *                                   items are linked to the visit that fulfilled them
 *   sales_targets                   per representative or territory, per period and metric
 *   crm_opportunities               leads and opportunities, converted to orders (and leads to customers)
 *   field_follow_ups (+source, automation_key, escalated_at)   automated follow-ups, created once per cause
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_plans', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('representative_id')->constrained('users');
            $t->date('period_start');
            $t->date('period_end');
            $t->string('status', 16)->default('draft'); // draft | submitted | approved | rejected | cancelled
            $t->text('notes')->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('decided_at')->nullable();
            $t->text('decision_notes')->nullable();
            $t->timestamps();

            $t->index(['organization_id', 'representative_id', 'period_start']);
            $t->index(['organization_id', 'status']);
        });

        Schema::create('visit_plan_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('visit_plan_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('customer_id')->constrained();
            $t->date('planned_date');
            $t->unsignedSmallInteger('sequence')->default(0); // order of calls on the day (route)
            $t->string('objective')->nullable();
            $t->string('status', 16)->default('planned'); // planned | visited | missed | cancelled
            $t->foreignUlid('field_visit_id')->nullable()->constrained()->nullOnDelete();
            $t->string('cancel_reason')->nullable();
            $t->timestamps();

            $t->index(['organization_id', 'customer_id', 'planned_date']);
            $t->index(['visit_plan_id', 'planned_date', 'sequence']);
        });

        Schema::create('sales_targets', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('scope_type', 16); // representative | territory
            $t->foreignId('representative_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('territory_id')->nullable()->constrained()->nullOnDelete();
            // invoiced_revenue | field_order_value | visits_completed | product_units
            $t->string('metric', 24);
            $t->foreignUlid('product_id')->nullable()->constrained();
            $t->date('period_start');
            $t->date('period_end');
            $t->decimal('target_value', 19, 4);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['organization_id', 'period_start', 'period_end']);
            $t->index(['organization_id', 'representative_id']);
        });

        Schema::create('crm_opportunities', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->string('client_reference', 64)->nullable();
            $t->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            // A lead is a prospect that is not a customer yet: business name and one work contact.
            $t->string('lead_name')->nullable();
            $t->string('lead_contact_name')->nullable();
            $t->string('lead_phone', 32)->nullable();
            $t->string('lead_email')->nullable();
            $t->string('lead_city', 100)->nullable();
            $t->foreignUlid('territory_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->string('stage', 16)->default('lead'); // lead | qualified | proposal | negotiation | won | lost
            $t->foreignId('owner_id')->constrained('users');
            $t->decimal('expected_value', 19, 4)->nullable();
            $t->char('currency_code', 3);
            $t->unsignedTinyInteger('probability')->default(10); // percent
            $t->date('expected_close_date')->nullable();
            $t->string('next_step')->nullable();
            $t->date('next_step_date')->nullable();
            $t->json('product_ids')->nullable();
            $t->text('notes')->nullable();
            $t->string('lost_reason')->nullable();
            $t->foreignUlid('field_order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('closed_at')->nullable();
            $t->dateTime('last_activity_at');
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            $t->unique(['organization_id', 'client_reference']);
            $t->index(['organization_id', 'owner_id', 'stage']);
            $t->index(['organization_id', 'customer_id']);
        });

        Schema::table('field_follow_ups', function (Blueprint $t) {
            $t->string('source', 16)->default('manual')->after('created_by'); // manual | visit | automation
            $t->string('automation_key', 128)->nullable()->after('source');
            $t->dateTime('escalated_at')->nullable()->after('completed_at');
            $t->unique(['organization_id', 'automation_key']);
        });
    }

    public function down(): void
    {
        Schema::table('field_follow_ups', function (Blueprint $t) {
            $t->dropUnique(['organization_id', 'automation_key']);
            $t->dropColumn(['source', 'automation_key', 'escalated_at']);
        });
        Schema::dropIfExists('crm_opportunities');
        Schema::dropIfExists('sales_targets');
        Schema::dropIfExists('visit_plan_items');
        Schema::dropIfExists('visit_plans');
    }
};
