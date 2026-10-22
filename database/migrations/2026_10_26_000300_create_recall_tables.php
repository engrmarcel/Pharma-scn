<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7b: recalls and regulatory incidents (spec §16.5; NAFDAC traceability regulations 12, 14).
 *
 *   recalls                platform-wide recall of lots of a product: draft → active → closed (or cancelled)
 *   recall_batches         the lots covered
 *   recall_notices         one per organization that holds or held a lot: notified, acknowledged, completed;
 *                          what it held when notified (recovery figures are derived from its stock movements)
 *   regulatory_incidents   identifier errors, unscannable or missing identifiers, stolen or lost products,
 *                          suspected falsified products, with the reporting deadline
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recalls', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 32)->unique();
            $t->foreignUlid('issuer_organization_id')->nullable()->constrained('organizations')->nullOnDelete(); // null = the platform
            $t->foreignUlid('product_id')->constrained();
            $t->string('title');
            $t->text('reason');
            $t->string('classification', 12);          // class_i | class_ii | class_iii
            $t->string('depth', 16);                   // wholesale | retail | patient
            $t->string('authority', 32)->nullable();   // e.g. NAFDAC
            $t->string('regulatory_reference', 100)->nullable();
            $t->text('quarantine_instructions');
            $t->string('status', 16)->default('draft'); // draft | active | closed | cancelled
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('activated_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('closed_at')->nullable();
            $t->text('closure_notes')->nullable();
            $t->text('closure_override_reason')->nullable(); // closed although notices were not all acknowledged
            $t->text('cancellation_reason')->nullable();
            $t->timestamps();

            $t->index(['status', 'product_id']);
        });

        Schema::create('recall_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('recall_id')->constrained()->cascadeOnDelete();
            $t->string('batch_number', 64);
            $t->unique(['recall_id', 'batch_number']);
        });

        Schema::create('recall_notices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('recall_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('status', 16)->default('pending'); // pending | acknowledged | completed
            $t->dateTime('notified_at');
            $t->decimal('held_at_notice', 18, 4)->default(0);        // base units, all stock states
            $t->decimal('quarantined_at_notice', 18, 4)->default(0); // available stock moved to quarantine by the recall
            // Position of the organization's trace chain when notified: events up to it happened before the recall.
            $t->unsignedBigInteger('chain_sequence_at_notice')->default(0);
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('acknowledged_at')->nullable();
            $t->text('acknowledgement_notes')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('completed_at')->nullable();
            $t->text('completion_notes')->nullable();
            $t->timestamps();

            $t->unique(['recall_id', 'organization_id']);
            $t->index(['organization_id', 'status']);
        });

        Schema::create('regulatory_incidents', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            // identifier_data_error | unscannable_carrier | missing_identifier | stolen | lost | suspected_falsified
            $t->string('type', 32);
            $t->foreignUlid('product_id')->nullable()->constrained()->nullOnDelete();
            $t->string('batch_number', 64)->nullable();
            $t->json('serials')->nullable();
            $t->decimal('quantity', 18, 4)->nullable();
            $t->text('description');
            $t->dateTime('detected_at');
            $t->dateTime('report_due_at');
            $t->string('status', 16)->default('open'); // open | reported | closed
            $t->string('authority', 32)->default('NAFDAC');
            $t->string('authority_reference', 100)->nullable();
            $t->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('reported_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('closing_notes')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            $t->index(['organization_id', 'status', 'report_due_at']);
        });
    }

    public function down(): void
    {
        foreach (['regulatory_incidents', 'recall_notices', 'recall_batches', 'recalls'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
