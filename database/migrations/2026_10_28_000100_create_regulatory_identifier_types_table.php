<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9a: the identifiers each regulator issues, as configuration (spec §20 regulatory identifiers).
 *
 *   regulatory_identifier_types   per authority: identifier type code, what it identifies (product,
 *                                 organization, manufacturer), display name, optional format pattern
 *                                 and example. Identifiers are only accepted for configured types.
 *   regulatory_incidents.report_due_at   nullable: the deadline comes from the country profile, and a
 *                                 country without a statutory deadline has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulatory_identifier_types', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('authority_id')->constrained('regulatory_authorities')->cascadeOnDelete();
            $t->string('identifier_type', 32);         // registration_number | listing_number | licence_number | …
            $t->json('subject_types');                 // ["product"], ["organization"], ["manufacturer"]
            $t->string('name');                        // e.g. "NAFDAC registration number"
            $t->string('format_pattern')->nullable();  // regular expression, null = character set only
            $t->string('format_example', 64)->nullable();
            $t->string('source')->nullable();          // where the format was confirmed
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['authority_id', 'identifier_type'], 'regulatory_identifier_types_unique');
        });

        Schema::table('regulatory_incidents', fn (Blueprint $t) => $t->dateTime('report_due_at')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('regulatory_incidents', fn (Blueprint $t) => $t->dateTime('report_due_at')->nullable(false)->change());
        Schema::dropIfExists('regulatory_identifier_types');
    }
};
