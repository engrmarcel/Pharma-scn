<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline sales carry the device's client reference (a UUID, 36 characters)
 * as their client transaction id. Online sales keep sending ULIDs (26).
 * Found by the live end-to-end test of the till app (Phase 3c).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $t) {
            $t->string('client_transaction_id', 64)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $t) {
            $t->char('client_transaction_id', 26)->change();
        });
    }
};
