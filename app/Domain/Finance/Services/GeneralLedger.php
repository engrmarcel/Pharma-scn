<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\FinancialPeriod;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Balanced double-entry posting (spec §10.6). Every entry:
 *  - has lines with exactly one non-negative side each,
 *  - balances to the cent-fraction (debits == credits at 4 decimals),
 *  - lands in an open financial period (monthly periods are opened on demand),
 *  - is immutable afterwards; corrections are reversing entries.
 */
class GeneralLedger
{
    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly DocumentNumberer $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{account?: string, account_id?: string, debit?: string, credit?: string, party_type?: ?string, party_id?: ?string, memo?: ?string}>  $lines
     *                                                                                                                                                                account = system key; account_id = explicit account
     */
    public function post(string $entryDate, string $sourceType, ?string $sourceId, string $description, array $lines, ?string $currency = null, ?string $reversalOfId = null): JournalEntry
    {
        return DB::transaction(function () use ($entryDate, $sourceType, $sourceId, $description, $lines, $currency, $reversalOfId) {
            $prepared = [];
            $debits = '0';
            $credits = '0';

            foreach ($lines as $i => $line) {
                $debit = Money::round((string) ($line['debit'] ?? '0'));
                $credit = Money::round((string) ($line['credit'] ?? '0'));
                if (bccomp($debit, '0', 4) === 0 && bccomp($credit, '0', 4) === 0) {
                    continue; // zero lines (e.g. no tax) are dropped
                }
                if (bccomp($debit, '0', 4) < 0 || bccomp($credit, '0', 4) < 0 || (bccomp($debit, '0', 4) > 0 && bccomp($credit, '0', 4) > 0)) {
                    throw new BusinessRuleViolation('journal_line_invalid', 'Each journal line must be a positive debit or a positive credit.', 422, ['line' => $i]);
                }

                $accountId = isset($line['account']) ? $this->chart->id($line['account']) : ($line['account_id'] ?? null);
                if ($accountId === null || ! Account::query()->whereKey($accountId)->where('status', 'active')->exists()) {
                    throw new BusinessRuleViolation('account_invalid', 'Unknown or inactive account.', 422, ['line' => $i]);
                }

                $prepared[] = ['account_id' => $accountId, 'debit' => $debit, 'credit' => $credit,
                    'party_type' => $line['party_type'] ?? null, 'party_id' => $line['party_id'] ?? null, 'memo' => $line['memo'] ?? null];
                $debits = bcadd($debits, $debit, 4);
                $credits = bcadd($credits, $credit, 4);
            }

            if ($prepared === [] || bccomp($debits, $credits, 4) !== 0) {
                throw new BusinessRuleViolation('journal_unbalanced', 'Debits must equal credits.', 422, ['debits' => $debits, 'credits' => $credits]);
            }

            $period = $this->openPeriodFor($entryDate);
            $entry = JournalEntry::query()->create([
                'number' => $this->numbers->next('JE'),
                'entry_date' => $entryDate,
                'financial_period_id' => $period->id,
                'currency_code' => $currency ?? $this->tenant->organization()->country->currency_code,
                'description' => $description,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reversal_of_id' => $reversalOfId,
                'total' => $debits,
                'posted_by' => Auth::id(),
                'posted_at' => now(),
            ]);
            $entry->lines()->createMany($prepared);

            return $entry->load('lines');
        });
    }

    public function reverse(JournalEntry $entry, string $reason, ?string $date = null): JournalEntry
    {
        if (JournalEntry::query()->where('reversal_of_id', $entry->id)->exists()) {
            throw new BusinessRuleViolation('journal_already_reversed', 'This entry has already been reversed.', 409);
        }

        $reversal = $this->post($date ?? today()->toDateString(), $entry->source_type, $entry->source_id, "Reversal of {$entry->number}: {$reason}",
            $entry->lines()->get()->map(fn ($l) => [
                'account_id' => $l->account_id, 'debit' => (string) $l->credit, 'credit' => (string) $l->debit,
                'party_type' => $l->party_type, 'party_id' => $l->party_id, 'memo' => $l->memo,
            ])->all(), $entry->currency_code, $entry->id);

        $this->audit->record('journal_entry.reversed', $entry, new: ['reversal' => $reversal->number, 'reason' => $reason]);

        return $reversal;
    }

    public function openPeriodFor(string $date): FinancialPeriod
    {
        $day = Carbon::parse($date)->toDateString();
        $period = FinancialPeriod::query()->where('start_date', '<=', $day)->where('end_date', '>=', $day)->first();

        if ($period === null) {
            $start = Carbon::parse($day)->startOfMonth();
            DB::table('financial_periods')->insertOrIgnore([
                'id' => strtolower((string) Str::ulid()),
                'organization_id' => $this->tenant->id(),
                'name' => $start->format('Y-m'),
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->endOfMonth()->toDateString(),
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $period = FinancialPeriod::query()->where('start_date', $start->toDateString())->firstOrFail();
        }

        if ($period->status !== 'open') {
            throw new BusinessRuleViolation('period_closed', "Financial period {$period->name} is closed.", 409);
        }

        return $period;
    }
}
