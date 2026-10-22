<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9c: country-aware reporting and regional deployment readiness.
 *
 *   exchange_rates               effective-dated rates recorded by platform staff, with their source; used only
 *                                to present platform-wide totals in one reporting currency (never in tenant books)
 *   organizations.data_region    where the organization's data is hosted; set from the country's residency region
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->char('base_currency', 3);
            $t->char('quote_currency', 3);
            $t->decimal('rate', 20, 8);            // 1 base = rate quote
            $t->date('effective_date');
            $t->string('source');
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['base_currency', 'quote_currency', 'effective_date'], 'exchange_rates_unique');
        });

        Schema::table('organizations', function (Blueprint $t) {
            $t->string('data_region', 40)->default('default')->after('country_code');
            $t->index('data_region');
        });
        DB::table('organizations')->update(['data_region' => 'default']);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->dropIndex(['data_region']);
            $t->dropColumn('data_region');
        });
        Schema::dropIfExists('exchange_rates');
    }
};
