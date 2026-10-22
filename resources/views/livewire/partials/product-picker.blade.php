{{-- Product search for one line or field. Needs: $target, $selected (chosen product's name or null). Uses the PicksProducts trait. --}}
<div class="relative min-w-48">
    @if (($pickerTarget ?? null) === $target)
        <input type="search" wire:model.live.debounce.300ms="productSearch" x-init="$el.focus()" @keydown.escape="$wire.closePicker()"
               class="form-input py-2" placeholder="Name, SKU or GTIN…" aria-label="Search products">
        <ul class="absolute inset-x-0 top-full z-30 mt-1 max-h-72 overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-xl">
            @forelse ($productOptions ?? [] as $o)
                <li>
                    <button type="button" wire:click="pick('{{ $o['product_id'] }}')" class="block w-full px-3 py-2 text-left text-sm hover:bg-navy-50">
                        <span class="block font-medium text-navy-900">{{ $o['name'] }}</span>
                        <span class="text-xs text-slate-500">{{ $o['sku'] }}@if ($o['price']) · {{ $o['currency'] }} {{ $o['price'] }}@endif</span>
                    </button>
                </li>
            @empty
                <li class="px-3 py-2 text-xs text-slate-500">{{ mb_strlen(trim($productSearch ?? '')) >= 2 ? 'No matching products.' : 'Type at least two characters.' }}</li>
            @endforelse
            <li class="border-t border-slate-100"><button type="button" wire:click="closePicker" class="w-full px-3 py-1.5 text-left text-xs text-slate-500 hover:bg-slate-50">Cancel</button></li>
        </ul>
    @else
        <button type="button" wire:click="openPicker('{{ $target }}')"
                class="form-input flex items-center justify-between gap-2 py-2 text-left {{ $selected ? 'text-slate-900' : 'text-slate-400' }}">
            <span class="truncate">{{ $selected ?: 'Choose a product…' }}</span>
            <x-heroicon-m-chevron-up-down class="size-4 shrink-0 text-slate-400"/>
        </button>
    @endif
</div>
