<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">Verified organizations can propose new products and corrections. Platform catalogue reviewers approve or reject each proposal.</p>
        <x-slot:actions>
            <button type="button" class="btn-light" wire:click="proposeCorrection"><x-heroicon-o-pencil-square class="size-4"/>Propose a correction</button>
            <button type="button" class="btn-primary" wire:click="proposeNew"><x-heroicon-o-plus class="size-4"/>Propose a new product</button>
        </x-slot:actions>
    </x-ui.toolbar>

    @if ($productBase && ! $product)
        <div class="card mb-4 p-4">
            <p class="mb-2 text-sm font-medium text-navy-900">Which product needs correcting?</p>
            @include('livewire.partials.product-picker', ['target' => 'correction', 'selected' => null])
        </div>
    @endif

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Submitted</th><th>Type</th><th>Product</th><th>Status</th><th>Review notes</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="cr-{{ $r['id'] }}">
                            <td class="whitespace-nowrap">{{ fmt()->dateTime($r['created_at']) }}</td>
                            <td>{{ $r['type'] === 'create' ? 'New product' : 'Correction' }}</td>
                            <td class="font-medium text-navy-900">{{ trim(($r['payload']['generic_name'] ?? '').' '.($r['payload']['strength_text'] ?? '')) ?: ($r['payload']['brand_name'] ?? '—') }}</td>
                            <td><x-ui.badge :status="$r['status']"/></td>
                            <td class="max-w-xs text-xs text-slate-600">{{ $r['review_notes'] ?? '—' }}</td>
                            <td class="text-right"><button type="button" class="link text-xs" wire:click="show(@js($r))">View</button></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="pencil-square" title="No proposals yet"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="product" :title="($productBase['name'] ?? null) ? 'Correct '.$productBase['name'] : 'Propose a new product'" size="xl">
        @if ($product)
            <form wire:submit="send" id="product-form">
                @include('livewire.partials.product-form')
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="product-form" class="btn-primary" wire:loading.attr="disabled">Send for review</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="viewing" title="Proposal" size="lg">
        @if ($viewing)
            <x-ui.dl :items="['Type' => $viewing['type'], 'Status' => $viewing['status'], 'Submitted' => fmt()->dateTime($viewing['created_at']),
                'Reviewed' => $viewing['reviewed_at'] ? fmt()->dateTime($viewing['reviewed_at']) : null, 'Review notes' => $viewing['review_notes']]"/>
            <pre class="mt-4 max-h-96 overflow-auto rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($viewing['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        @endif
    </x-ui.modal>
</div>
