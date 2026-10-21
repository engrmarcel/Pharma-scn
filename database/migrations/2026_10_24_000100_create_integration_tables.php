<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise integration gateway (spec §15, Phase 5; docs/ENTERPRISE_INTEGRATIONS.md).
 *
 *   integration_connectors            the external-system registry: one row per connected ERP or feed, with
 *                                     its confirmed interface, a hashed inbound key and encrypted credentials
 *   integration_field_mappings        source field → canonical field, per connector and entity
 *   integration_external_references   external ids (material numbers, plant codes…) → our ids; never assumed equal
 *   integration_runs                  one intake call or file, with received/processed/quarantined counts
 *   integration_inbox                 every inbound record, stored before it is acknowledged, processed
 *                                     asynchronously and idempotently (unique per connector and message_id)
 *   integration_inventory_snapshots   stock positions reported by the external system, kept for
 *                                     reconciliation; never posted to the stock ledger
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connectors', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 32);
            $t->string('name', 100);
            // sap_s4hana | sap_ecc | sap_business_one | oracle_ebs | oracle_fusion | dynamics_365 | odoo | sage | custom | other
            $t->string('system_type', 32);
            $t->string('system_version', 64)->nullable();
            $t->string('deployment_model', 16)->nullable(); // cloud | on_premise | hybrid
            // rest_push | file_upload | sftp | sap_idoc
            $t->string('inbound_transport', 16);
            // What was confirmed with the customer: product, version, interface and authorization.
            $t->text('interface_notes')->nullable();
            $t->string('status', 16)->default('draft'); // draft | active | paused | disabled
            $t->json('settings')->nullable();          // non-secret options
            $t->text('credentials')->nullable();       // encrypted JSON; never returned by the API
            $t->string('inbound_key_prefix', 16)->nullable()->unique();
            $t->char('inbound_key_hash', 64)->nullable();
            $t->dateTime('inbound_key_rotated_at')->nullable();
            $t->text('signing_secret')->nullable();    // encrypted; when set, request bodies must be signed
            $t->unsignedSmallInteger('rate_limit_per_minute')->default(120);
            $t->dateTime('last_message_at')->nullable();
            $t->dateTime('last_processed_at')->nullable();
            $t->dateTime('last_failure_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['organization_id', 'code']);
            $t->index(['organization_id', 'status']);
        });

        Schema::create('integration_field_mappings', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('connector_id')->constrained('integration_connectors')->cascadeOnDelete();
            $t->string('entity_type', 32);
            $t->string('target_field', 64);
            $t->string('source_path', 191)->nullable();
            // trim | upper | lower | date | decimal | map | strip_leading_zeros
            $t->string('transform', 32)->nullable();
            $t->json('options')->nullable();
            $t->string('default_value', 255)->nullable();
            $t->timestamps();

            $t->unique(['connector_id', 'entity_type', 'target_field'], 'integration_mappings_target_unique');
        });

        Schema::create('integration_external_references', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('connector_id')->constrained('integration_connectors')->cascadeOnDelete();
            $t->string('entity_type', 32);   // product | warehouse | batch
            $t->string('external_id', 128);
            $t->string('internal_type', 48);
            $t->string('internal_id', 64);
            $t->string('source', 16)->default('matched'); // matched | manual
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['connector_id', 'entity_type', 'external_id'], 'integration_refs_external_unique');
            $t->index(['organization_id', 'internal_type', 'internal_id'], 'integration_refs_internal_index');
        });

        Schema::create('integration_runs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('connector_id')->constrained('integration_connectors')->cascadeOnDelete();
            $t->string('source', 16);  // api | file | sftp
            $t->string('file_name')->nullable();
            $t->char('file_sha256', 64)->nullable();
            $t->unsignedInteger('received_count')->default(0);
            $t->unsignedInteger('duplicate_count')->default(0);
            $t->unsignedInteger('rejected_count')->default(0); // refused at intake, never stored
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('started_at');
            $t->timestamps();

            $t->index(['organization_id', 'connector_id', 'started_at']);
        });

        Schema::create('integration_inbox', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('connector_id')->constrained('integration_connectors')->cascadeOnDelete();
            $t->foreignUlid('run_id')->nullable()->constrained('integration_runs')->nullOnDelete();
            $t->string('message_id', 128);
            $t->string('entity_type', 32);
            $t->unsignedSmallInteger('schema_version')->default(1);
            $t->char('payload_hash', 64);
            $t->json('payload');            // the record as the source sent it
            $t->json('canonical')->nullable(); // after field mapping
            // received | processing | processed | quarantined | failed | ignored
            $t->string('status', 16)->default('received');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->dateTime('next_attempt_at')->nullable();
            $t->string('error_code', 64)->nullable();
            $t->text('error_message')->nullable();
            $t->json('error_details')->nullable();
            $t->json('result')->nullable();
            $t->dateTime('received_at');
            $t->dateTime('processing_started_at')->nullable();
            $t->dateTime('processed_at')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('resolved_at')->nullable();
            $t->text('resolution_notes')->nullable();
            $t->timestamps();

            $t->unique(['connector_id', 'message_id']);
            $t->index(['organization_id', 'status', 'received_at']);
            $t->index(['connector_id', 'status']);
            $t->index(['status', 'next_attempt_at']);
        });

        Schema::create('integration_inventory_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('connector_id')->constrained('integration_connectors')->cascadeOnDelete();
            $t->unsignedBigInteger('inbox_id')->nullable();
            $t->foreignUlid('warehouse_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            $t->string('batch_number', 64)->default(''); // '' = not batch-specific
            $t->decimal('quantity', 18, 4);             // base units
            $t->dateTime('as_of');
            $t->timestamps();

            $t->unique(['connector_id', 'warehouse_id', 'product_id', 'batch_number'], 'integration_snapshots_position_unique');
            $t->index(['organization_id', 'warehouse_id'], 'integration_snapshots_warehouse_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_inventory_snapshots');
        Schema::dropIfExists('integration_inbox');
        Schema::dropIfExists('integration_runs');
        Schema::dropIfExists('integration_external_references');
        Schema::dropIfExists('integration_field_mappings');
        Schema::dropIfExists('integration_connectors');
    }
};
