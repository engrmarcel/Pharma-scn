<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance foundations (spec §10.6): per-organization chart of accounts,
 * financial periods, balanced append-only journal, payments and allocation.
 * This is an operational ledger; it is not certified for statutory
 * reporting or tax compliance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 16);
            $t->string('name');
            // asset | liability | equity | revenue | expense
            $t->string('type', 16);
            // Stable key for accounts the system posts to automatically (cash, inventory, accounts_payable …).
            $t->string('system_key', 40)->nullable();
            $t->foreignUlid('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->string('status', 16)->default('active');
            $t->timestamps();

            $t->unique(['organization_id', 'code']);
            $t->unique(['organization_id', 'system_key']);
        });

        Schema::create('financial_periods', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name', 32);
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 16)->default('open'); // open | closed
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('closed_at')->nullable();
            $t->timestamps();

            $t->unique(['organization_id', 'start_date']);
        });

        Schema::create('journal_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->date('entry_date');
            $t->foreignUlid('financial_period_id')->constrained();
            $t->char('currency_code', 3);
            $t->string('description');
            // Automatic postings reference the business document; manual entries have source_type 'manual'.
            $t->string('source_type', 40);
            $t->ulid('source_id')->nullable();
            $t->foreignUlid('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $t->decimal('total', 19, 4);
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('posted_at');
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            $t->index(['organization_id', 'entry_date']);
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('journal_entry_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('account_id')->constrained();
            $t->decimal('debit', 19, 4)->default(0);
            $t->decimal('credit', 19, 4)->default(0);
            $t->string('party_type', 16)->nullable(); // supplier | customer
            $t->ulid('party_id')->nullable();
            $t->string('memo')->nullable();

            $t->index(['account_id']);
            $t->index(['party_type', 'party_id']);
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 32);
            $t->string('direction', 8); // incoming | outgoing
            $t->string('party_type', 16); // supplier | customer
            $t->ulid('party_id');
            // cash | bank_transfer | card | mobile_money | cheque | other
            $t->string('method', 16);
            $t->decimal('amount', 19, 4);
            $t->decimal('allocated_amount', 19, 4)->default(0);
            $t->char('currency_code', 3);
            $t->date('payment_date');
            $t->string('reference', 64)->nullable();
            // confirmed | pending | failed | reversed
            $t->string('status', 16)->default('confirmed');
            $t->foreignUlid('account_id')->constrained();
            $t->ulid('journal_entry_id')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['organization_id', 'number']);
            $t->index(['organization_id', 'party_type', 'party_id']);
        });

        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $t->string('document_type', 32); // supplier_invoice | sales_invoice
            $t->ulid('document_id');
            $t->decimal('amount', 19, 4);
            $t->timestamps();

            $t->index(['document_type', 'document_id']);
        });

        Schema::table('supplier_invoices', function (Blueprint $t) {
            $t->decimal('amount_paid', 19, 4)->default(0)->after('total');
            // unpaid | partial | paid
            $t->string('payment_status', 16)->default('unpaid')->after('amount_paid');
            $t->ulid('journal_entry_id')->nullable()->after('approved_at');
        });

        Schema::table('goods_receipts', function (Blueprint $t) {
            $t->ulid('journal_entry_id')->nullable()->after('posting_id');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', fn (Blueprint $t) => $t->dropColumn('journal_entry_id'));
        Schema::table('supplier_invoices', fn (Blueprint $t) => $t->dropColumn(['amount_paid', 'payment_status', 'journal_entry_id']));
        foreach (['payment_allocations', 'payments', 'journal_lines', 'journal_entries', 'financial_periods', 'accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
