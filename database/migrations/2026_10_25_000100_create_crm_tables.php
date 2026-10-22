<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6a, CRM foundations (spec §17).
 *
 *   customers (+tier, visit_frequency_days, potential_value)     account classification and call frequency
 *   party_contacts (+professional profile, consent)              doctors, pharmacists and buyers at a customer
 *   customer_segments, customer_segment_members                  manual and rule-based segmentation
 *   crm_interactions                                             calls, e-mails, meetings outside visits
 *   customer_feedback                                            structured feedback and complaints
 *   detailing_messages, detailing_records                        approved key messages and what was detailed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->char('tier', 1)->nullable()->after('assigned_representative_id');          // A | B | C
            $t->unsignedSmallInteger('visit_frequency_days')->nullable()->after('tier');  // target days between visits
            $t->decimal('potential_value', 19, 4)->nullable()->after('visit_frequency_days'); // estimated monthly potential
        });

        Schema::table('party_contacts', function (Blueprint $t) {
            // doctor | pharmacist | nurse | procurement | owner | manager | other
            $t->string('professional_type', 24)->nullable()->after('job_title');
            $t->string('specialty', 100)->nullable()->after('professional_type');
            $t->string('professional_licence', 64)->nullable()->after('specialty');
            $t->string('preferred_contact_method', 16)->nullable()->after('professional_licence'); // visit | phone | email | none
            $t->string('contact_consent', 16)->default('unknown')->after('preferred_contact_method'); // granted | withdrawn | unknown
            $t->dateTime('consent_recorded_at')->nullable()->after('contact_consent');
            $t->string('consent_source', 100)->nullable()->after('consent_recorded_at');
            $t->dateTime('anonymized_at')->nullable()->after('consent_source');
        });

        Schema::create('customer_segments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 32);
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('type', 8); // manual | rule
            $t->json('criteria')->nullable();
            $t->string('status', 16)->default('active'); // active | archived
            $t->dateTime('last_refreshed_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['organization_id', 'code']);
        });

        Schema::create('customer_segment_members', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('segment_id')->constrained('customer_segments')->cascadeOnDelete();
            $t->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $t->string('source', 8); // manual | rule
            $t->timestamps();

            $t->unique(['segment_id', 'customer_id']);
            $t->index(['organization_id', 'customer_id']);
        });

        Schema::create('crm_interactions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('client_reference', 64)->nullable();
            $t->foreignUlid('customer_id')->constrained();
            $t->foreignUlid('contact_id')->nullable()->constrained('party_contacts')->nullOnDelete();
            $t->foreignId('representative_id')->constrained('users');
            $t->foreignUlid('field_visit_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel', 16);   // call | email | meeting | message | event | other
            $t->string('direction', 8);  // outbound | inbound
            $t->string('subject');
            $t->text('summary')->nullable();
            $t->dateTime('occurred_at');
            $t->timestamps();

            $t->unique(['organization_id', 'client_reference']);
            $t->index(['organization_id', 'customer_id', 'occurred_at']);
            $t->index(['organization_id', 'representative_id', 'occurred_at'], 'crm_interactions_rep_occurred_index');
        });

        Schema::create('customer_feedback', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('client_reference', 64)->nullable();
            $t->foreignUlid('customer_id')->constrained();
            $t->foreignUlid('contact_id')->nullable()->constrained('party_contacts')->nullOnDelete();
            $t->foreignUlid('field_visit_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('product_id')->nullable()->constrained();
            // complaint | product_quality | service | delivery | pricing | praise | suggestion | other
            $t->string('category', 24);
            $t->unsignedTinyInteger('rating')->nullable(); // 1..5
            $t->text('description');
            // The reporter believes a patient may have been harmed: handled by pharmacovigilance, outside the CRM.
            $t->boolean('possible_adverse_event')->default(false);
            $t->string('status', 16)->default('open'); // open | in_review | resolved
            $t->foreignId('recorded_by')->constrained('users');
            $t->text('resolution')->nullable();
            $t->string('pharmacovigilance_reference', 64)->nullable(); // the safety case it was reported under
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('resolved_at')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'client_reference']);
            $t->index(['organization_id', 'status', 'category']);
            $t->index(['organization_id', 'customer_id']);
        });

        Schema::create('detailing_messages', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('product_id')->constrained();
            $t->string('title');
            $t->text('key_message');
            $t->string('approval_reference', 64)->nullable(); // the medical/regulatory approval of the material
            $t->string('status', 16)->default('draft');       // draft | approved | retired
            $t->date('valid_until')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('approved_at')->nullable();
            $t->timestamps();

            $t->index(['organization_id', 'product_id', 'status']);
        });

        Schema::create('detailing_records', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('client_reference', 64)->nullable();
            $t->foreignUlid('field_visit_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('customer_id')->constrained();
            $t->foreignUlid('contact_id')->nullable()->constrained('party_contacts')->nullOnDelete();
            $t->foreignId('representative_id')->constrained('users');
            $t->foreignUlid('product_id')->constrained();
            $t->json('message_ids');                     // approved messages delivered
            $t->decimal('samples_quantity', 18, 4)->nullable(); // recorded only; no stock movement
            $t->string('sample_batch_number', 64)->nullable();
            $t->string('reception', 16)->default('not_assessed'); // positive | neutral | negative | not_assessed
            $t->text('notes')->nullable();
            $t->dateTime('recorded_at');
            $t->timestamps();

            $t->unique(['organization_id', 'client_reference']);
            $t->index(['organization_id', 'product_id', 'recorded_at']);
            $t->index(['organization_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detailing_records');
        Schema::dropIfExists('detailing_messages');
        Schema::dropIfExists('customer_feedback');
        Schema::dropIfExists('crm_interactions');
        Schema::dropIfExists('customer_segment_members');
        Schema::dropIfExists('customer_segments');
        Schema::table('party_contacts', fn (Blueprint $t) => $t->dropColumn(['professional_type', 'specialty', 'professional_licence', 'preferred_contact_method',
            'contact_consent', 'consent_recorded_at', 'consent_source', 'anonymized_at']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['tier', 'visit_frequency_days', 'potential_value']));
    }
};
