{{-- Product master form. Needs: $product (array), $reference (dosage_forms, administration_routes, units_of_measure). Field errors are under "product.*". --}}
<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.field label="Generic name" for="product.generic_name"><input id="product.generic_name" wire:model="product.generic_name" class="form-input"></x-ui.field>
        <x-ui.field label="Brand name" for="product.brand_name"><input id="product.brand_name" wire:model="product.brand_name" class="form-input"></x-ui.field>
        <x-ui.field label="Strength" for="product.strength_text" hint="As printed, e.g. 500 mg"><input id="product.strength_text" wire:model="product.strength_text" class="form-input"></x-ui.field>
        <x-ui.field label="Dosage form" for="product.dosage_form_code">
            <select id="product.dosage_form_code" wire:model="product.dosage_form_code" class="form-select">
                <option value="">Choose…</option>
                @foreach ($reference['dosage_forms'] ?? [] as $f)<option value="{{ $f['code'] }}">{{ $f['name'] }}</option>@endforeach
            </select>
        </x-ui.field>
        <x-ui.field label="Route" for="product.route_code">
            <select id="product.route_code" wire:model="product.route_code" class="form-select">
                <option value="">—</option>
                @foreach ($reference['administration_routes'] ?? [] as $r)<option value="{{ $r['code'] }}">{{ $r['name'] }}</option>@endforeach
            </select>
        </x-ui.field>
        <x-ui.field label="Country of origin" for="product.country_of_origin"><input id="product.country_of_origin" wire:model="product.country_of_origin" maxlength="2" class="form-input uppercase" placeholder="IN"></x-ui.field>
        <x-ui.field label="Marketing authorization holder" for="product.marketing_authorization_holder" class="sm:col-span-2"><input id="product.marketing_authorization_holder" wire:model="product.marketing_authorization_holder" class="form-input"></x-ui.field>
        <x-ui.field label="Storage conditions" for="product.storage_conditions"><input id="product.storage_conditions" wire:model="product.storage_conditions" class="form-input" placeholder="Store below 25 °C"></x-ui.field>
        <x-ui.field label="Min °C" for="product.temperature_min_c"><input id="product.temperature_min_c" type="number" step="0.1" wire:model="product.temperature_min_c" class="form-input"></x-ui.field>
        <x-ui.field label="Max °C" for="product.temperature_max_c"><input id="product.temperature_max_c" type="number" step="0.1" wire:model="product.temperature_max_c" class="form-input"></x-ui.field>
        <label class="flex items-center gap-2 self-end pb-3 text-sm"><input type="checkbox" wire:model="product.requires_cold_chain" class="form-check">Requires cold chain</label>
        <x-ui.field label="Description" for="product.description" class="sm:col-span-2 lg:col-span-3"><textarea id="product.description" wire:model="product.description" rows="2" class="form-input"></textarea></x-ui.field>
    </div>

    <section>
        <div class="mb-2 flex items-center justify-between"><h3 class="text-sm font-semibold text-navy-950">Active ingredients</h3><button type="button" class="link text-xs" wire:click="addProductRow('ingredients')">+ Add ingredient</button></div>
        @error('product.ingredients') <p class="mb-2 text-xs text-rose-600">{{ $message }}</p> @enderror
        <div class="space-y-2">
            @foreach ($product['ingredients'] as $i => $ing)
                <div class="grid grid-cols-[1fr_7rem_6rem_auto] items-start gap-2" wire:key="ing-{{ $i }}">
                    <x-ui.field :for="'product.ingredients.'.$i.'.name'"><input wire:model="product.ingredients.{{ $i }}.name" class="form-input py-2" placeholder="Ingredient"></x-ui.field>
                    <x-ui.field :for="'product.ingredients.'.$i.'.strength_value'"><input wire:model="product.ingredients.{{ $i }}.strength_value" class="form-input py-2" placeholder="500" inputmode="decimal"></x-ui.field>
                    <x-ui.field :for="'product.ingredients.'.$i.'.strength_unit'">
                        <select wire:model="product.ingredients.{{ $i }}.strength_unit" class="form-select py-2">@foreach ($reference['units_of_measure'] ?? [] as $u)<option value="{{ $u['code'] }}">{{ $u['code'] }}</option>@endforeach</select>
                    </x-ui.field>
                    <button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removeProductRow('ingredients', {{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>
                </div>
            @endforeach
        </div>
    </section>

    <section>
        <div class="mb-2 flex items-center justify-between"><h3 class="text-sm font-semibold text-navy-950">Pack levels <span class="font-normal text-slate-500">(smallest unit first)</span></h3><button type="button" class="link text-xs" wire:click="addProductRow('package_levels')">+ Add level</button></div>
        @error('product.package_levels') <p class="mb-2 text-xs text-rose-600">{{ $message }}</p> @enderror
        <div class="space-y-2">
            @foreach ($product['package_levels'] as $i => $lvl)
                <div class="grid grid-cols-[3.5rem_1fr_1.5fr_7rem_7rem_auto] items-start gap-2" wire:key="lvl-{{ $i }}">
                    <x-ui.field :for="'product.package_levels.'.$i.'.level'"><input wire:model="product.package_levels.{{ $i }}.level" class="form-input py-2" type="number" min="0" max="7"></x-ui.field>
                    <x-ui.field :for="'product.package_levels.'.$i.'.code'"><input wire:model="product.package_levels.{{ $i }}.code" class="form-input py-2" placeholder="pack"></x-ui.field>
                    <x-ui.field :for="'product.package_levels.'.$i.'.name'"><input wire:model="product.package_levels.{{ $i }}.name" class="form-input py-2" placeholder="Pack of 2 blisters"></x-ui.field>
                    <x-ui.field :for="'product.package_levels.'.$i.'.unit_code'">
                        <select wire:model="product.package_levels.{{ $i }}.unit_code" class="form-select py-2"><option value="">Unit</option>@foreach ($reference['units_of_measure'] ?? [] as $u)<option value="{{ $u['code'] }}">{{ $u['code'] }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field :for="'product.package_levels.'.$i.'.quantity_of_child'"><input wire:model="product.package_levels.{{ $i }}.quantity_of_child" class="form-input py-2" placeholder="{{ $i === 0 ? '—' : 'per level' }}" inputmode="decimal" @disabled($i === 0)></x-ui.field>
                    <button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removeProductRow('package_levels', {{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>
                </div>
            @endforeach
        </div>
        <p class="mt-1 text-xs text-slate-500">Quantity is how many of the level below make one of this level (e.g. 10 capsules in a blister).</p>
    </section>

    <section>
        <div class="mb-2 flex items-center justify-between"><h3 class="text-sm font-semibold text-navy-950">Identifiers</h3><button type="button" class="link text-xs" wire:click="addProductRow('identifiers')">+ Add identifier</button></div>
        <div class="space-y-2">
            @forelse ($product['identifiers'] as $i => $idf)
                <div class="grid grid-cols-[10rem_1fr_auto] items-start gap-2" wire:key="idf-{{ $i }}">
                    <select wire:model="product.identifiers.{{ $i }}.type" class="form-select py-2"><option value="gtin">GTIN</option><option value="manufacturer_code">Manufacturer code</option><option value="other">Other</option></select>
                    <x-ui.field :for="'product.identifiers.'.$i.'.value'"><input wire:model="product.identifiers.{{ $i }}.value" class="form-input py-2 font-mono"></x-ui.field>
                    <button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removeProductRow('identifiers', {{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>
                </div>
            @empty
                <p class="text-xs text-slate-500">No GTIN or codes yet.</p>
            @endforelse
        </div>
    </section>
</div>
