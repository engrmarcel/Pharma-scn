<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GS1 Global Location Numbers (NAFDAC Pharmaceutical Products (Traceability) Regulations 2024,
 * reg. 11(2)(b): supply chain stakeholders obtain a GLN to identify the organization and the
 * locations where items are manufactured, received, distributed and dispensed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->char('gln', 13)->nullable()->unique()->after('tax_identification_number'));
        Schema::table('warehouses', fn (Blueprint $t) => $t->char('gln', 13)->nullable()->after('code'));
    }

    public function down(): void
    {
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropColumn('gln'));
        Schema::table('organizations', function (Blueprint $t) {
            $t->dropUnique(['gln']);
            $t->dropColumn('gln');
        });
    }
};
