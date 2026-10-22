<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7a: traceability as a shared platform service (spec §16; docs/TRACEABILITY_DESIGN.md).
 *
 *   trace_events               append-only chain-of-custody events, one per batch movement leg, recorded in
 *                              the same transaction as the stock posting; hash-chained per recording organization
 *   trace_chain_heads          the last hash of each organization's chain (locked while appending)
 *   trace_event_verifications  evidence about an event added later (corroborated, independently verified,
 *                              disputed); the event itself is never edited
 *   trace_identifiers          QR tokens, serials and logistic units (SSCC) with aggregation and status
 *   trace_scans                scan history (public and authenticated); no personal data
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trace_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete(); // the recording organization
            $t->unsignedBigInteger('sequence');                                   // position in that organization's chain
            $t->string('event_type', 32);
            $t->foreignUlid('product_id')->constrained();
            $t->string('batch_number', 64);   // the manufacturer lot, the cross-organization key of a lot
            $t->foreignUlid('batch_id')->nullable()->constrained()->nullOnDelete(); // the recorder's batch record
            $t->string('serial', 64)->nullable();
            $t->decimal('quantity', 18, 4)->nullable(); // base units
            $t->foreignUlid('source_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $t->foreignUlid('destination_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $t->string('source_location', 100)->nullable();
            $t->string('destination_location', 100)->nullable();
            $t->string('counterparty_name')->nullable(); // a supplier or customer that is not on the platform
            $t->string('related_type', 40)->nullable();
            $t->string('related_id', 64)->nullable();
            $t->ulid('posting_id')->nullable();
            $t->dateTime('event_time');
            $t->dateTime('recorded_at');
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('source_system', 64)->default('platform'); // platform | integration:<connector> | device
            $t->char('country_code', 2)->nullable();
            $t->ulid('corrects_event_id')->nullable();
            $t->ulid('reverses_event_id')->nullable();
            $t->text('correction_reason')->nullable();
            $t->json('details')->nullable();
            $t->char('prev_hash', 64)->nullable();
            $t->char('hash', 64);
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['organization_id', 'sequence']);
            $t->index(['product_id', 'batch_number', 'event_time'], 'trace_events_lot_index');
            $t->index(['organization_id', 'event_time']);
            $t->index(['source_organization_id']);
            $t->index(['destination_organization_id']);
            $t->index(['related_type', 'related_id']);
        });

        Schema::create('trace_chain_heads', function (Blueprint $t) {
            $t->foreignUlid('organization_id')->primary()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('sequence')->default(0);
            $t->char('hash', 64)->nullable();
            $t->timestamps();
        });

        Schema::create('trace_event_verifications', function (Blueprint $t) {
            $t->id();
            $t->ulid('trace_event_id')->index();
            // corroborated (the counterparty recorded the matching movement) | independently_verified | disputed
            $t->string('level', 24);
            $t->string('method', 40);         // counterparty_record | document_review | physical_inspection | regulator_confirmation
            $t->text('evidence')->nullable();
            $t->ulid('corroborating_event_id')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('verified_by_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('trace_identifiers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete(); // issuer
            $t->string('kind', 12);             // batch | product | serial | sscc
            $t->string('token', 32)->unique();  // opaque, unguessable; the QR content is a URL ending in it
            $t->foreignUlid('product_id')->nullable()->constrained();
            $t->string('gtin', 14)->nullable();
            $t->string('batch_number', 64)->nullable();
            $t->date('expiry_date')->nullable();
            $t->string('serial', 64)->nullable();
            $t->ulid('parent_id')->nullable();  // aggregation: serial → case/pallet (SSCC)
            $t->string('status', 16)->default('active'); // active | dispensed | decommissioned | revoked
            $t->string('status_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['gtin', 'serial'], 'trace_identifiers_gtin_serial_unique'); // duplicate serial detection
            $t->index(['product_id', 'batch_number']);
            $t->index(['organization_id', 'kind']);
            $t->index('parent_id');
        });

        Schema::create('trace_scans', function (Blueprint $t) {
            $t->id();
            $t->ulid('identifier_id')->nullable()->index();
            $t->foreignUlid('product_id')->nullable()->constrained()->nullOnDelete();
            $t->string('batch_number', 64)->nullable();
            $t->string('serial', 64)->nullable();
            $t->string('code_format', 16);       // token | gs1 | digital_link
            $t->string('result', 24);            // ok | expired | recalled | revoked | dispensed | unknown
            $t->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete(); // when an authenticated member scanned
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->char('client_hash', 64)->nullable(); // salted hash of the IP, for abuse and duplicate-scan analysis only
            $t->timestamp('scanned_at')->useCurrent();

            $t->index(['product_id', 'batch_number']);
        });
    }

    public function down(): void
    {
        foreach (['trace_scans', 'trace_identifiers', 'trace_event_verifications', 'trace_chain_heads', 'trace_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
