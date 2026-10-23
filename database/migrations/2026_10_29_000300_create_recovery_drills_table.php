<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 10c: history of restore drills (what was restored, how long it took, how old the data was, what was checked). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_drills', function (Blueprint $t) {
            $t->id();
            $t->string('backup_file', 255);
            $t->dateTime('backup_created_at')->nullable();
            $t->dateTime('started_at');
            $t->dateTime('finished_at')->nullable();
            $t->unsignedInteger('restore_seconds')->nullable(); // measured recovery time (import and checks)
            $t->unsignedInteger('data_age_seconds')->nullable(); // how old the restored data was (RPO for this backup)
            $t->string('status', 16); // passed | failed
            $t->json('checks')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index(['status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_drills');
    }
};
