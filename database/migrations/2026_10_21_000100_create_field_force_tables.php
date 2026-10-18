<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field force / light CRM (spec §12). Location evidence is event-based
 * (check-in and check-out only, never continuous tracking) and records what
 * the device actually reported, including the absence of a location.
 * Device-generated client references make offline replays idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territories', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 32);
            $t->string('name');
            $t->foreignUlid('parent_id')->nullable()->constrained('territories')->nullOnDelete();
            // Default warehouse that fulfils mobile orders captured in this territory.
            $t->foreignUlid('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $t->text('description')->nullable();
            $t->string('status', 16)->default('active'); // active | inactive
            $t->timestamps();

            $t->unique(['organization_id', 'code']);
        });

        Schema::create('territory_members', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('territory_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 16); // representative | supervisor
            $t->timestamps();

            $t->unique(['territory_id', 'user_id', 'role']);
            $t->index(['organization_id', 'user_id']);
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->foreignUlid('territory_id')->nullable()->after('longitude')->constrained()->nullOnDelete();
            $t->foreignId('assigned_representative_id')->nullable()->after('territory_id')->constrained('users')->nullOnDelete();
            $t->index(['organization_id', 'assigned_representative_id']);
        });

        Schema::create('field_visits', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->string('client_reference', 64);
            $t->foreignId('representative_id')->constrained('users');
            $t->foreignUlid('customer_id')->constrained();
            $t->foreignUlid('territory_id')->nullable()->constrained()->nullOnDelete(); // snapshot at check-in
            // routine | detailing | order_taking | follow_up | complaint | collection | other
            $t->string('visit_type', 24);
            $t->string('status', 16)->default('in_progress'); // in_progress | completed | cancelled
            $t->text('objectives')->nullable();

            foreach (['check_in', 'check_out'] as $e) {
                $t->dateTime("{$e}_at")->nullable();            // device clock
                $t->dateTime("{$e}_received_at")->nullable();   // server clock
                // granted | denied | restricted | service_disabled | unavailable | timeout
                $t->string("{$e}_location_status", 20)->nullable();
                $t->decimal("{$e}_latitude", 10, 7)->nullable();
                $t->decimal("{$e}_longitude", 10, 7)->nullable();
                $t->decimal("{$e}_accuracy_m", 10, 2)->nullable();
                $t->boolean("{$e}_mock_location")->nullable();
                $t->unsignedInteger("{$e}_distance_m")->nullable(); // from the customer's recorded location
                // verified | outside_radius | low_accuracy | mock_location | no_location | customer_location_unknown
                $t->string("{$e}_verification", 32)->nullable();
                $t->json("{$e}_device")->nullable();
            }
            $t->dateTime('location_purged_at')->nullable(); // retention: coordinates removed, verification kept

            $t->text('discussion_notes')->nullable();
            $t->json('products_discussed')->nullable();
            $t->text('customer_feedback')->nullable();
            $t->text('competitor_information')->nullable();
            $t->boolean('order_opportunity')->default(false);
            // successful | partially_successful | unsuccessful | customer_unavailable | rescheduled
            $t->string('outcome', 24)->nullable();
            $t->text('next_action')->nullable();
            $t->date('follow_up_date')->nullable();
            $t->boolean('captured_offline')->default(false);
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            $t->unique(['organization_id', 'client_reference']);
            $t->index(['organization_id', 'representative_id', 'check_in_at']);
            $t->index(['organization_id', 'customer_id']);
        });

        Schema::create('field_visit_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('field_visit_id')->constrained()->cascadeOnDelete();
            // detailing | sample_request | order_request | complaint | stock_check | note
            $t->string('activity_type', 24);
            $t->foreignUlid('product_id')->nullable()->constrained();
            $t->text('notes')->nullable();
            $t->dateTime('recorded_at');
            $t->timestamps();
        });

        Schema::create('field_follow_ups', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('client_reference', 64)->nullable();
            $t->foreignUlid('customer_id')->constrained();
            $t->foreignUlid('field_visit_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('assigned_to')->constrained('users');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title');
            $t->text('notes')->nullable();
            $t->date('due_on');
            $t->string('status', 16)->default('open'); // open | done | cancelled
            $t->dateTime('completed_at')->nullable();
            $t->text('outcome')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'client_reference']);
            $t->index(['organization_id', 'assigned_to', 'status']);
        });

        Schema::create('field_orders', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->string('client_reference', 64);
            $t->foreignId('representative_id')->constrained('users');
            $t->foreignUlid('customer_id')->constrained();
            $t->foreignUlid('field_visit_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('warehouse_id')->nullable()->constrained()->nullOnDelete();
            // draft | submitted | accepted | rejected | cancelled
            // "accepted" means the ERP created a sales order from it; only then can stock be reserved.
            $t->string('status', 16)->default('draft');
            $t->dateTime('captured_at');      // device clock
            $t->boolean('captured_offline')->default(false);
            $t->dateTime('submitted_at')->nullable();
            $t->dateTime('validated_at')->nullable();
            $t->json('rejection_reasons')->nullable();
            $t->foreignUlid('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $t->date('requested_date')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            $t->unique(['organization_id', 'client_reference']);
            $t->index(['organization_id', 'representative_id', 'status']);
        });

        Schema::create('field_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('field_order_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            $t->string('package_level_code', 32)->nullable();
            $t->decimal('quantity', 18, 4);
            // What the device showed the customer (cached); the ERP re-prices on acceptance.
            $t->decimal('indicative_unit_price', 19, 4)->nullable();
            $t->string('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_order_lines');
        Schema::dropIfExists('field_orders');
        Schema::dropIfExists('field_follow_ups');
        Schema::dropIfExists('field_visit_activities');
        Schema::dropIfExists('field_visits');
        Schema::table('customers', function (Blueprint $t) {
            $t->dropIndex(['organization_id', 'assigned_representative_id']);
            $t->dropConstrainedForeignId('assigned_representative_id');
            $t->dropConstrainedForeignId('territory_id');
        });
        Schema::dropIfExists('territory_members');
        Schema::dropIfExists('territories');
    }
};
