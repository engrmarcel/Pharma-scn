<div>
    <x-ui.tabs :current="$report" model="report" :tabs="['profit-and-loss' => 'Profit and loss', 'balance-sheet' => 'Balance sheet', 'trial-balance' => 'Trial balance', 'account-ledger' => 'Account ledger', 'payables-aging' => 'Payables aging']"/>

    <x-ui.toolbar>
        @if (in_array($report, ['profit-and-loss', 'account-ledger'], true))
            <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input w-44"></x-ui.field>
            <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input w-44"></x-ui.field>
        @else
            <x-ui.field label="As of" for="asOf"><input id="asOf" type="date" wire:model.live="asOf" class="form-input w-44"></x-ui.field>
        @endif
        @if ($report === 'account-ledger')
            <x-ui.field label="Account" for="account">
                <select id="account" wire:model.live="account" class="form-select w-72"><option value="">Choose an account…</option>@foreach ($accounts as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
            </x-ui.field>
        @endif
        <x-slot:actions><button type="button" class="btn-light" onclick="window.print()"><x-heroicon-o-printer class="size-4"/>Print</button></x-slot:actions>
    </x-ui.toolbar>

    @if ($data === null)
        <x-ui.card><x-ui.empty icon="document-chart-bar" title="{{ $report === 'account-ledger' ? 'Choose an account' : 'No figures' }}"/></x-ui.card>
    @elseif ($report === 'profit-and-loss')
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-ui.stat label="Revenue" :value="fmt()->money($data['revenue'], $currency)" tone="green"/>
            <x-ui.stat label="Expenses" :value="fmt()->money($data['expenses'], $currency)" tone="amber"/>
            <x-ui.stat label="Net income" :value="fmt()->money($data['net_income'], $currency)" :tone="(float) $data['net_income'] >= 0 ? 'green' : 'red'"/>
        </div>
        @include('livewire.finance.partials.account-table', ['rows' => $data['lines'], 'balanceKey' => null])
    @elseif ($report === 'balance-sheet')
        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <x-ui.stat label="Assets" :value="fmt()->money($data['assets'], $currency)"/>
            <x-ui.stat label="Liabilities" :value="fmt()->money($data['liabilities'], $currency)" tone="amber"/>
            <x-ui.stat label="Equity" :value="fmt()->money($data['equity'], $currency)" tone="gold" :hint="'incl. '.fmt()->money($data['current_period_earnings'], $currency).' current earnings'"/>
            <x-ui.stat label="Check" :value="$data['balanced'] ? 'Balanced' : 'Out of balance'" :tone="$data['balanced'] ? 'green' : 'red'"/>
        </div>
        @include('livewire.finance.partials.account-table', ['rows' => $data['accounts'], 'balanceKey' => 'balance'])
    @elseif ($report === 'trial-balance')
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-ui.stat label="Total debits" :value="fmt()->money($data['total_debit'], $currency)"/>
            <x-ui.stat label="Total credits" :value="fmt()->money($data['total_credit'], $currency)"/>
            <x-ui.stat label="Check" :value="$data['balanced'] ? 'Balanced' : 'Out of balance'" :tone="$data['balanced'] ? 'green' : 'red'"/>
        </div>
        @include('livewire.finance.partials.account-table', ['rows' => $data['accounts'], 'balanceKey' => 'balance'])
    @elseif ($report === 'account-ledger')
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Running (Dr)</th></tr></thead>
                    <tbody>
                        <tr class="bg-slate-50"><td colspan="6" class="font-medium">Opening</td><td class="num font-semibold">{{ fmt()->number($data['opening_net_debit'], 2) }}</td></tr>
                        @foreach ($data['lines'] as $l)
                            <tr><td>{{ fmt()->date($l['entry_date']) }}</td><td class="font-mono text-xs">{{ $l['number'] }}</td><td>{{ $l['description'] }}</td><td class="text-xs text-slate-500">{{ $l['memo'] }}</td>
                                <td class="num">{{ (float) $l['debit'] ? fmt()->number($l['debit'], 2) : '' }}</td><td class="num">{{ (float) $l['credit'] ? fmt()->number($l['credit'], 2) : '' }}</td><td class="num">{{ fmt()->number($l['running_net_debit'], 2) }}</td></tr>
                        @endforeach
                        <tr class="bg-slate-50"><td colspan="6" class="font-medium">Closing</td><td class="num font-semibold">{{ fmt()->number($data['closing_net_debit'], 2) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($report === 'payables-aging')
        <div class="mb-6 grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
            @foreach (['current' => ['Not yet due', 'green'], '1_30' => ['1–30 days', 'gold'], '31_60' => ['31–60 days', 'amber'], '61_90' => ['61–90 days', 'amber'], 'over_90' => ['Over 90 days', 'red']] as $key => [$label, $tone])
                <x-ui.stat :label="$label" :value="fmt()->money($data['buckets'][$key], $currency)" :tone="$tone"/>
            @endforeach
        </div>
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Invoice</th><th>Supplier</th><th>Due</th><th class="num">Days past due</th><th class="num">Open</th></tr></thead>
                    <tbody>
                        @forelse ($data['invoices'] as $i)
                            <tr><td class="font-mono text-xs">{{ $i['number'] }} <span class="text-slate-400">({{ $i['supplier_invoice_number'] }})</span></td><td>{{ $i['supplier_name'] }}</td><td>{{ $i['due_date'] ? fmt()->date($i['due_date']) : '—' }}</td>
                                <td class="num">{{ $i['days_past_due'] }}</td><td class="num font-semibold">{{ fmt()->money($i['open_amount'], $i['currency_code']) }}</td></tr>
                        @empty
                            <x-ui.empty colspan="5" icon="check-circle" title="Nothing owed to suppliers"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
    @if ($notice)<p class="mt-4 text-xs text-slate-500">{{ $notice }}</p>@endif
</div>
