<div>
    <h1 class="mb-4 text-2xl font-semibold">Product catalogue</h1>

    <div class="mb-4 flex flex-wrap gap-3">
        <input type="search" wire:model.live.debounce.300ms="q" placeholder="Name, generic, SKU, GTIN or registration no."
               class="w-full max-w-md rounded border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="form" class="rounded border border-slate-300 px-3 py-2 text-sm">
            <option value="">All dosage forms</option>
            @foreach ($forms as $f)
                <option value="{{ $f->code }}">{{ $f->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="grid gap-6 lg:grid-cols-5">
        <div class="lg:col-span-3">
            <table class="w-full overflow-hidden rounded-lg border border-slate-200 bg-white text-sm shadow-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-600">
                    <tr><th class="px-3 py-2">Product</th><th class="px-3 py-2">Manufacturer</th><th class="px-3 py-2">Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr wire:key="{{ $product->id }}" wire:click="select('{{ $product->id }}')"
                            class="cursor-pointer border-t border-slate-100 hover:bg-slate-50 {{ $selected === $product->id ? 'bg-slate-50' : '' }}">
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ $product->display_name }}</div>
                                <div class="text-xs text-slate-500">{{ $product->generic_name }} · {{ $product->platform_sku }}</div>
                            </td>
                            <td class="px-3 py-2">{{ $product->manufacturer?->name ?? '—' }}</td>
                            <td class="px-3 py-2">{{ str_replace('_', ' ', $product->status->value) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-3 py-6 text-center text-slate-500">No products match.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-3">{{ $products->links() }}</div>
        </div>

        <aside class="lg:col-span-2">
            @if ($detail)
                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm shadow-sm">
                    <h2 class="text-lg font-semibold">{{ $detail->display_name }}</h2>
                    <p class="text-slate-600">{{ $detail->generic_name }} · {{ $detail->dosage_form_code }} · v{{ $detail->version }}</p>

                    <h3 class="mt-4 font-medium">Active ingredients</h3>
                    <ul class="list-inside list-disc text-slate-700">
                        @foreach ($detail->ingredients as $i)
                            <li>{{ $i->name }} {{ rtrim(rtrim($i->pivot->strength_value, '0'), '.') }} {{ $i->pivot->strength_unit }}@if ($i->pivot->per_unit) / {{ rtrim(rtrim($i->pivot->per_value, '0'), '.') }} {{ $i->pivot->per_unit }}@endif</li>
                        @endforeach
                    </ul>

                    <h3 class="mt-4 font-medium">Packaging</h3>
                    <ul class="text-slate-700">
                        @foreach ($detail->packageLevels as $l)
                            <li>L{{ $l->level }} {{ $l->name }} = {{ rtrim(rtrim($l->base_unit_quantity, '0'), '.') }} base units</li>
                        @endforeach
                    </ul>

                    <h3 class="mt-4 font-medium">Regulatory identifiers</h3>
                    @forelse ($detail->regulatoryIdentifiers as $r)
                        <div class="mt-1">
                            {{ $r->authority->code }} {{ str_replace('_', ' ', $r->identifier_type) }}: <span class="font-mono">{{ $r->value }}</span>
                            <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs">platform: {{ $r->effectiveStatus()->value }}</span>
                        </div>
                    @empty
                        <p class="text-slate-500">None recorded.</p>
                    @endforelse
                    <p class="mt-3 text-xs text-slate-500">Platform verification status is the platform's own check and is not a statement by the regulator.</p>
                </div>
            @else
                <p class="text-sm text-slate-500">Select a product to see details.</p>
            @endif
        </aside>
    </div>
</div>
