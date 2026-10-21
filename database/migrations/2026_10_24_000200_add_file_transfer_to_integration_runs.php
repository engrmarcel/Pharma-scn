<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5b: file and SFTP transfers, SAP IDoc intake and exports.
 *
 * A run is now either an intake (api, file, sftp, idoc) or an export, and
 * records why a file could not be read. Connectors keep their last transport
 * error (an SFTP login failure, for example) for the health view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_runs', function (Blueprint $t) {
            $t->string('direction', 8)->default('inbound')->after('source'); // inbound | outbound
            $t->string('dataset', 32)->nullable()->after('direction');       // entity of an import, dataset of an export
            $t->unsignedInteger('record_count')->nullable()->after('rejected_count'); // records written by an export
            $t->string('error_code', 64)->nullable()->after('record_count');
            $t->text('error_message')->nullable()->after('error_code');
        });

        Schema::table('integration_connectors', function (Blueprint $t) {
            $t->string('last_error', 255)->nullable()->after('last_failure_at');
            $t->dateTime('last_transfer_at')->nullable()->after('last_error'); // last SFTP poll or push
        });
    }

    public function down(): void
    {
        Schema::table('integration_connectors', fn (Blueprint $t) => $t->dropColumn(['last_error', 'last_transfer_at']));
        Schema::table('integration_runs', fn (Blueprint $t) => $t->dropColumn(['direction', 'dataset', 'record_count', 'error_code', 'error_message']));
    }
};
