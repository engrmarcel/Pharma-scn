<div class="mx-auto max-w-3xl space-y-6">
    <x-ui.card title="Scan or type a code" icon="qr-code">
        <form wire:submit="check" class="flex flex-wrap gap-2">
            <x-ui.field for="scan.code" class="min-w-0 flex-1">
                <input id="scan.code" wire:model="code" autofocus class="form-input font-mono" placeholder="(01)06151234567893(17)271231(10)B123 or a QR link" aria-label="Pack code">
            </x-ui.field>
            <button class="btn-primary" wire:loading.attr="disabled"><x-heroicon-o-magnifying-glass class="size-4"/>Check</button>
        </form>
        <p class="mt-2 text-xs text-slate-500">A barcode scanner types the code and presses Enter. GS1 element strings, the platform's QR links and tokens are accepted.</p>
    </x-ui.card>

    @if ($result)
        @php
            $tone = match ($result['result']) { 'ok' => 'green', 'unknown', 'unregistered' => 'amber', default => 'red' };
            $panel = ['green' => 'border-emerald-200 bg-emerald-50 text-emerald-900', 'amber' => 'border-amber-200 bg-amber-50 text-amber-900', 'red' => 'border-rose-200 bg-rose-50 text-rose-900'][$tone];
        @endphp
        <div class="rounded-2xl border p-5 {{ $panel }}" role="status">
            <div class="flex items-center gap-3">
                @if ($tone === 'green')<x-heroicon-s-check-circle class="size-8"/>@elseif ($tone === 'amber')<x-heroicon-s-question-mark-circle class="size-8"/>@else<x-heroicon-s-exclamation-triangle class="size-8"/>@endif
                <div>
                    <p class="text-lg font-semibold">{{ ucfirst(str_replace('_', ' ', $result['result'])) }}</p>
                    <p class="text-sm">{{ ($result['message'] ?? null) }}</p>
                </div>
            </div>
        </div>

        <x-ui.card title="What the code says" icon="document-magnifying-glass">
            <x-ui.dl :items="[
                'Product' => ($result['product'] ?? null) ? trim(($result['product'] ?? null)['display_name'].' · '.(($result['product'] ?? null)['dosage_form'] ?? '')) : null,
                'Batch' => ($result['batch_number'] ?? null), 'Expiry' => ($result['expiry_date'] ?? null) ? fmt()->date(($result['expiry_date'] ?? null)) : null, 'Serial' => ($result['serial'] ?? null),
                'Identifier' => ($result['identifier'] ?? null) ? ($result['identifier'] ?? null)['kind'].' · '.($result['identifier'] ?? null)['status'].(($result['identifier'] ?? null)['issued_by_you'] ? ' · issued by you' : '') : null,
            ]"/>
            @if (! empty($result['warnings']))
                <ul class="mt-4 list-disc space-y-1 pl-5 text-sm text-amber-800">@foreach (($result['warnings'] ?? null) as $w)<li>{{ is_array($w) ? ($w['message'] ?? json_encode($w)) : $w }}</li>@endforeach</ul>
            @endif
            @if (! empty($result['your_stock']))
                <p class="mb-1 mt-4 text-xs font-semibold uppercase text-slate-400">Your stock of this lot</p>
                <ul class="text-sm">@foreach (($result['your_stock'] ?? null) as $st)<li>{{ $st['warehouse_code'] }} · {{ $st['stock_state'] }} · {{ fmt()->number($st['quantity'], 2) }}</li>@endforeach</ul>
            @endif
            <p class="mt-4 text-xs text-slate-500">{{ ($result['disclaimer'] ?? null) }}</p>
            @if ($canDispense && ($result['serial'] ?? null) && $result['result'] === 'ok')
                <div class="mt-4 flex justify-end"><button type="button" class="btn-primary" wire:click="dispense" wire:confirm="Record this serialised pack as dispensed?">Record as dispensed</button></div>
            @endif
        </x-ui.card>
    @endif
</div>
