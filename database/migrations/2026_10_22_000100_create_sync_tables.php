<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline synchronization (spec §13, Phase 3).
 *
 *   devices         registered phones/tablets; revocable; their API tokens are bound to them
 *   sync_inbox      every envelope a device sends, stored before it is processed and
 *                   acknowledged per client_txn_id (idempotent: a replay returns the stored ack)
 *   sync_issues     what replay found that a person must review (stock shortfalls, price
 *                   variances, blocked batches sold offline, late sales on closed shifts…)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('platform', 32);          // android | ios | other
            $t->string('app_version', 32)->nullable();
            $t->string('role', 16);              // field | pos
            $t->foreignUlid('pos_terminal_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 16)->default('active'); // active | revoked
            $t->boolean('wipe_requested')->default(false);
            $t->dateTime('revoke_notified_at')->nullable();
            $t->dateTime('last_seen_at')->nullable();
            $t->dateTime('last_sync_at')->nullable();
            $t->dateTime('revoked_at')->nullable();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('revoke_reason')->nullable();
            $t->timestamps();

            $t->index(['organization_id', 'status']);
            $t->index(['organization_id', 'user_id']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $t) {
            $t->ulid('device_id')->nullable()->index();
        });

        Schema::create('sync_inbox', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->string('client_txn_id', 64);
            $t->string('type', 48);
            $t->unsignedSmallInteger('schema_version');
            $t->dateTime('client_created_at');
            $t->dateTime('received_at');
            $t->unsignedSmallInteger('attempts')->default(0);   // processing attempts on the server
            $t->unsignedSmallInteger('client_attempt')->nullable(); // the device's own attempt counter
            $t->json('payload');
            // received (stored, not yet processed) | synced | conflict | rejected
            $t->string('status', 16)->default('received');
            $t->json('result')->nullable();
            $t->json('warnings')->nullable();
            $t->string('error_code', 64)->nullable();
            $t->text('error_message')->nullable();
            $t->json('error_details')->nullable();
            $t->dateTime('processed_at')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('resolved_at')->nullable();
            $t->text('resolution_notes')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'client_txn_id']);
            $t->index(['organization_id', 'device_id', 'status']);
            $t->index(['organization_id', 'status', 'received_at']);
        });

        Schema::create('sync_issues', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('sync_inbox_id')->nullable()->index();
            // stock_shortfall | blocked_batch_sold | price_variance | discount_over_limit | prescription_control
            // | payment_shortfall | late_offline_sale
            $t->string('type', 32);
            $t->string('severity', 8);          // low | medium | high
            $t->string('subject_type', 48)->nullable();
            $t->string('subject_id', 64)->nullable();
            $t->foreignUlid('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('product_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('quantity', 18, 4)->nullable(); // base units, for stock issues
            $t->json('details')->nullable();
            $t->string('status', 16)->default('open'); // open | resolved
            $t->string('resolution', 32)->nullable(); // acknowledged | found_stock_recorded
            $t->text('resolution_notes')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('resolved_at')->nullable();
            $t->timestamps();

            $t->index(['organization_id', 'status', 'type']);
        });

        Schema::table('pos_sales', function (Blueprint $t) {
            $t->boolean('captured_offline')->default(false);
            $t->dateTime('client_completed_at')->nullable();
            $t->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('pos_sale_lines', function (Blueprint $t) {
            // Sold offline but not issued from stock: the shortfall waits in sync_issues.
            $t->decimal('unallocated_base_quantity', 18, 4)->default(0);
        });
        Schema::table('pos_payments', function (Blueprint $t) {
            // Cash taken while offline: in the drawer, but only reconciled when the shift is counted.
            $t->boolean('recorded_offline')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', fn (Blueprint $t) => $t->dropColumn('recorded_offline'));
        Schema::table('pos_sale_lines', fn (Blueprint $t) => $t->dropColumn('unallocated_base_quantity'));
        Schema::table('pos_sales', function (Blueprint $t) {
            $t->dropConstrainedForeignId('device_id');
            $t->dropColumn(['captured_offline', 'client_completed_at']);
        });
        Schema::dropIfExists('sync_issues');
        Schema::dropIfExists('sync_inbox');
        Schema::table('personal_access_tokens', function (Blueprint $t) {
            $t->dropIndex(['device_id']);
            $t->dropColumn('device_id');
        });
        Schema::dropIfExists('devices');
    }
};
