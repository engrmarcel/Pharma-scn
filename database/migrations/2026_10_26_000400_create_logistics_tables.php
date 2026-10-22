<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7c: logistics and delivery (spec §18), the execution layer over the stock documents.
 *
 *   vehicles                 the shipper's or carrier's vehicles (refrigerated or not)
 *   shipments                one physical transport: source document, origin, destination, carrier, driver,
 *                            vehicle, cold-chain range, status
 *   shipment_items           what travels, by batch, with delivered and returned quantities
 *   shipment_events          append-only tracking history
 *   proofs_of_delivery       who received, when, where, in what condition (signature and photo as attachments)
 *   temperature_readings     logger or manual readings during transport
 *   temperature_excursions   periods outside the required range, with their assessment
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('registration', 32);
            $t->string('description')->nullable();
            $t->boolean('refrigerated')->default(false);
            $t->string('status', 16)->default('active'); // active | inactive
            $t->timestamps();

            $t->unique(['organization_id', 'registration']);
        });

        Schema::create('shipments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete(); // the shipper
            $t->string('number', 32);
            $t->string('source_type', 24);        // sales_delivery | stock_transfer | marketplace_shipment | manual
            $t->string('source_id', 64)->nullable();
            $t->foreignUlid('origin_warehouse_id')->constrained('warehouses');
            $t->foreignUlid('destination_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $t->foreignUlid('destination_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $t->string('destination_name');
            $t->string('destination_address')->nullable();
            $t->foreignUlid('carrier_organization_id')->nullable()->constrained('organizations')->nullOnDelete(); // a logistics provider on the platform
            $t->string('carrier_name')->nullable();
            $t->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('driver_name')->nullable();
            $t->string('driver_phone', 32)->nullable();
            $t->foreignUlid('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $t->string('vehicle_registration', 32)->nullable();
            $t->string('temperature_logger_id', 64)->nullable();
            $t->boolean('requires_cold_chain')->default(false);
            $t->decimal('temperature_min_c', 5, 2)->nullable();
            $t->decimal('temperature_max_c', 5, 2)->nullable();
            // planned | dispatched | in_transit | delivered | partially_delivered | failed | returned_to_origin | cancelled
            $t->string('status', 24)->default('planned');
            $t->string('tracking_reference', 64)->nullable();
            $t->dateTime('planned_delivery_at')->nullable();
            $t->dateTime('dispatched_at')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            $t->index(['organization_id', 'status']);
            $t->index(['carrier_organization_id', 'status']);
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('shipment_items', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('shipment_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            $t->string('batch_number', 64);
            $t->date('expiry_date')->nullable();
            $t->decimal('quantity', 18, 4);              // base units shipped
            $t->decimal('delivered_quantity', 18, 4)->nullable();
            $t->decimal('returned_quantity', 18, 4)->default(0);
            $t->string('discrepancy_note')->nullable();
        });

        Schema::create('shipment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('shipment_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete(); // who recorded it (shipper or carrier)
            // created | dispatched | location_update | arrived | delivery_attempted | delivered | partially_delivered
            // | failed | returned_to_origin | temperature_excursion | note | cancelled
            $t->string('event_type', 32);
            $t->dateTime('occurred_at');
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->string('location_text')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['shipment_id', 'occurred_at']);
        });

        Schema::create('proofs_of_delivery', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('shipment_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('received_by_name');
            $t->string('received_by_role', 100)->nullable();
            $t->dateTime('received_at');
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->string('condition', 24);            // good | damaged | temperature_concern
            $t->ulid('signature_attachment_id')->nullable();
            $t->ulid('photo_attachment_id')->nullable();
            $t->boolean('cold_chain_unresolved')->default(false); // an excursion was still unassessed at delivery
            $t->text('notes')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('temperature_readings', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('shipment_id')->constrained()->cascadeOnDelete();
            $t->dateTime('recorded_at');
            $t->decimal('temperature_c', 5, 2);
            $t->string('source', 12);               // logger | manual | iot
            $t->string('device_id', 64)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['shipment_id', 'recorded_at', 'device_id'], 'temperature_readings_unique');
        });

        Schema::create('temperature_excursions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('shipment_id')->constrained()->cascadeOnDelete();
            $t->dateTime('started_at');
            $t->dateTime('ended_at')->nullable();
            $t->decimal('lowest_c', 5, 2);
            $t->decimal('highest_c', 5, 2);
            $t->unsignedInteger('readings')->default(1);
            $t->string('status', 16)->default('open'); // open | assessed
            $t->string('assessment', 24)->nullable();  // product_usable | quarantine | destroy
            $t->text('assessment_notes')->nullable();
            $t->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('assessed_at')->nullable();
            $t->timestamps();

            $t->index(['shipment_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['temperature_excursions', 'temperature_readings', 'proofs_of_delivery', 'shipment_events', 'shipment_items', 'shipments', 'vehicles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
