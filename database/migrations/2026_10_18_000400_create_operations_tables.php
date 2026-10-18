<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. The AuditLog model refuses updates and deletes.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('organization_id')->nullable()->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 16)->default('user'); // user | system | api_client
            $table->string('action', 100)->index();
            $table->string('auditable_type', 100)->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            // "user:<id>|org:<id or ->"; keys are only unique within an actor/tenant scope.
            $table->string('scope', 120);
            $table->string('key', 128);
            $table->string('method', 8);
            $table->string('path', 255);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();

            $table->unique(['scope', 'key']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->nullable()->index();
            $table->string('attachable_type', 100);
            $table->string('attachable_id', 64);
            $table->string('category', 64);
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            // pending | clean | infected | not_scanned  (scanner integration is a later phase)
            $table->string('scan_status', 16)->default('not_scanned');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_logs');
    }
};
