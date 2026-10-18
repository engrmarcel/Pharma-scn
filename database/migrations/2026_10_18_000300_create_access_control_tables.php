<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('scope', 16); // platform | organization
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // NULL for system roles (templates shared by every organization) and platform roles.
            $table->foreignUlid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 16);
            $table->string('code', 64);
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'scope', 'code']);
            $table->index(['scope', 'code']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('membership_role', function (Blueprint $table) {
            $table->foreignUlid('membership_id')->constrained('organization_memberships')->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['membership_id', 'role_id']);
        });

        Schema::create('platform_role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_role_user');
        Schema::dropIfExists('membership_role');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
