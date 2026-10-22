{{-- Product lines editor. Needs: $doc['lines'], $form (error prefix); optional $showPrice, $showTax (bool), $priceLabel, $rateField (tax_rate | discount_percent), $rateLabel. --}}
@php
    $showPrice ??= true;
    $showTax ??= true;
    $priceLabel ??= 'Unit price';
    $rateField ??= 'tax_rate';
    $rateLabel ??= 'Tax %';
    // Literal class names so Tailwind generates them.
    $cols = match (true) {
        $showPrice && $showTax => 'md:grid-cols-[minmax(0,1fr)_10rem_7rem_8rem_6rem_auto]',
        $showPrice => 'md:grid-cols-[minmax(0,1fr)_10rem_7rem_8rem_auto]',
        default => 'md:grid-cols-[minmax(0,1fr)_10rem_7rem_auto]',
    };
@endphp
<div class="space-y-2">
    <div class="hidden gap-2 px-1 text-xs font-medium uppercase tracking-wide text-slate-400 md:grid {{ $cols }}">
        <span>Product</span><span>Pack</span><span>Quantity</span>@if ($showPrice)<span>{{ $priceLabel }}</span>@endif @if ($showTax)<span>{{ $rateLabel }}</span>@endif<span></span>
    </div>
    @error($form.'.lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
    @foreach ($doc['lines'] as $i => $l)
        <div class="grid items-start gap-2 rounded-lg border border-slate-200 p-2 md:border-0 md:p-0 {{ $cols }}" wire:key="dl-{{ $i }}">
            <x-ui.field :for="$form.'.lines.'.$i.'.product_id'">@include('livewire.partials.product-picker', ['target' => 'line.'.$i, 'selected' => $l['name']])</x-ui.field>
            <x-ui.field :for="$form.'.lines.'.$i.'.package_level_code'">
                <select wire:model="doc.lines.{{ $i }}.package_level_code" class="form-select py-2" aria-label="Pack">@forelse ($l['levels'] as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@empty<option value="">Base unit</option>@endforelse</select>
            </x-ui.field>
            <x-ui.field :for="$form.'.lines.'.$i.'.quantity'"><input wire:model="doc.lines.{{ $i }}.quantity" inputmode="decimal" class="form-input py-2" aria-label="Quantity"></x-ui.field>
            @if ($showPrice)<x-ui.field :for="$form.'.lines.'.$i.'.unit_price'"><input wire:model="doc.lines.{{ $i }}.unit_price" inputmode="decimal" class="form-input py-2" placeholder="Default" aria-label="{{ $priceLabel }}"></x-ui.field>@endif
            @if ($showTax)<x-ui.field :for="$form.'.lines.'.$i.'.'.$rateField"><input wire:model="doc.lines.{{ $i }}.{{ $rateField }}" inputmode="decimal" class="form-input py-2" placeholder="0" aria-label="{{ $rateLabel }}"></x-ui.field>@endif
            <button type="button" class="justify-self-end p-2 text-slate-400 hover:text-rose-600" wire:click="removeLine({{ $i }})" aria-label="Remove line"><x-heroicon-o-trash class="size-4"/></button>
        </div>
    @endforeach
    <button type="button" class="link text-sm" wire:click="addLine">+ Add line</button>
</div>
