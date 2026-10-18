<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted JSON list of SHA-256 hashes; each code is single-use.
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_enabled_at');
            // Last accepted TOTP time step: a code cannot be replayed within its window.
            $table->unsignedBigInteger('mfa_last_used_timestep')->nullable()->after('mfa_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_recovery_codes', 'mfa_last_used_timestep']);
        });
    }
};
