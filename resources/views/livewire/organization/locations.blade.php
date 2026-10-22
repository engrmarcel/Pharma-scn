<div class="space-y-6">
    <x-ui.card title="Branches" icon="building-storefront" :padded="false">
        <x-slot:actions>
            @if ($canManage)<button type="button" class="btn-primary btn-sm" wire:click="editBranch()"><x-heroicon-o-plus class="size-4"/>New branch</button>@endif
        </x-slot:actions>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>City</th><th>Phone</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($branches as $b)
                        <tr wire:key="b-{{ $b['id'] }}">
                            <td class="font-mono text-xs font-semibold">{{ $b['code'] }}</td>
                            <td class="font-medium text-navy-900">{{ $b['name'] }}</td>
                            <td>{{ str_replace('_', ' ', $b['type'] ?? '') }}</td>
                            <td>{{ collect([$b['city'] ?? null, $b['state_region'] ?? null])->filter()->implode(', ') ?: '—' }}</td>
                            <td>{{ $b['phone'] ?? '—' }}</td>
                            <td><x-ui.badge :status="$b['status']"/></td>
                            <td class="text-right">@if ($canManage)<button type="button" class="link text-xs" wire:click="editBranch(@js($b))">Edit</button>@endif</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="building-storefront" title="No branches yet">Branches are where you trade: head office, outlets, departments.</x-ui.empty>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.card title="Warehouses and stores" icon="cube" :padded="false">
        <x-slot:actions>
            @if ($canManage)<button type="button" class="btn-primary btn-sm" wire:click="editWarehouse()"><x-heroicon-o-plus class="size-4"/>New warehouse</button>@endif
        </x-slot:actions>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Branch</th><th>GLN</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($warehouses as $w)
                        <tr wire:key="w-{{ $w['id'] }}">
                            <td class="font-mono text-xs font-semibold">{{ $w['code'] }}</td>
                            <td class="font-medium text-navy-900">{{ $w['name'] }}</td>
                            <td>{{ str_replace('_', ' ', $w['type'] ?? '') }}</td>
                            <td>{{ $branchNames[$w['branch_id'] ?? ''] ?? '—' }}</td>
                            <td class="font-mono text-xs">{{ $w['gln'] ?? '—' }}</td>
                            <td><x-ui.badge :status="$w['status']"/></td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button" class="link text-xs" wire:click="showBins('{{ $w['id'] }}')">Bins</button>
                                @if ($canManage)<button type="button" class="link ml-3 text-xs" wire:click="editWarehouse(@js($w))">Edit</button>@endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="cube" title="No warehouses yet">Stock is held in warehouses: stores, cold rooms, quarantine areas.</x-ui.empty>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.modal wire:model="branch" :title="($branch['id'] ?? null) ? 'Edit branch' : 'New branch'" size="md">
        @if ($branch)
            <form wire:submit="saveBranch" id="branch-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Code" for="branch.code"><input id="branch.code" wire:model="branch.code" class="form-input font-mono uppercase" @disabled($branch['id'])></x-ui.field>
                <x-ui.field label="Name" for="branch.name"><input id="branch.name" wire:model="branch.name" class="form-input"></x-ui.field>
                <x-ui.field label="Type" for="branch.type">
                    <select id="branch.type" wire:model="branch.type" class="form-select">
                        @foreach (['head_office', 'branch', 'retail_outlet', 'department'] as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field label="Status" for="branch.status">
                    <select id="branch.status" wire:model="branch.status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                </x-ui.field>
                <x-ui.field label="Address" for="branch.address_line1" class="sm:col-span-2"><input id="branch.address_line1" wire:model="branch.address_line1" class="form-input"></x-ui.field>
                <x-ui.field label="City" for="branch.city"><input id="branch.city" wire:model="branch.city" class="form-input"></x-ui.field>
                <x-ui.field label="State / region" for="branch.state_region"><input id="branch.state_region" wire:model="branch.state_region" class="form-input"></x-ui.field>
                <x-ui.field label="Phone" for="branch.phone"><input id="branch.phone" wire:model="branch.phone" class="form-input"></x-ui.field>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="branch-form" class="btn-primary" wire:loading.attr="disabled">Save</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="warehouse" :title="($warehouse['id'] ?? null) ? 'Edit warehouse' : 'New warehouse'" size="md">
        @if ($warehouse)
            <form wire:submit="saveWarehouse" id="warehouse-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Code" for="warehouse.code"><input id="warehouse.code" wire:model="warehouse.code" class="form-input font-mono uppercase" @disabled($warehouse['id'])></x-ui.field>
                <x-ui.field label="Name" for="warehouse.name"><input id="warehouse.name" wire:model="warehouse.name" class="form-input"></x-ui.field>
                <x-ui.field label="Type" for="warehouse.type">
                    <select id="warehouse.type" wire:model="warehouse.type" class="form-select">
                        @foreach (['general', 'cold_room', 'quarantine', 'bonded', 'retail_store'] as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field label="Branch" for="warehouse.branch_id">
                    <select id="warehouse.branch_id" wire:model="warehouse.branch_id" class="form-select">
                        <option value="">None</option>
                        @foreach ($branches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field label="Address" for="warehouse.address_line1"><input id="warehouse.address_line1" wire:model="warehouse.address_line1" class="form-input"></x-ui.field>
                <x-ui.field label="City" for="warehouse.city"><input id="warehouse.city" wire:model="warehouse.city" class="form-input"></x-ui.field>
                <x-ui.field label="GLN" for="warehouse.gln" hint="GS1 Global Location Number, 13 digits."><input id="warehouse.gln" wire:model="warehouse.gln" class="form-input font-mono"></x-ui.field>
                <x-ui.field label="Status" for="warehouse.status">
                    <select id="warehouse.status" wire:model="warehouse.status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                </x-ui.field>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="warehouse-form" class="btn-primary" wire:loading.attr="disabled">Save</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="binsFor" title="Storage bins" size="md">
        @if ($binsFor)
            @if ($bins === null)
                <p class="text-sm text-slate-500">Bins are part of inventory, which is not available to you here.</p>
            @else
                <ul class="mb-4 divide-y divide-slate-100 rounded-lg border border-slate-200">
                    @forelse ($bins as $bn)
                        <li class="flex items-center justify-between px-3 py-2 text-sm"><span><span class="font-mono font-semibold">{{ $bn['code'] }}</span> {{ $bn['name'] }}</span><x-ui.badge :status="$bn['status']"/></li>
                    @empty
                        <li class="px-3 py-4 text-center text-sm text-slate-500">No bins yet.</li>
                    @endforelse
                </ul>
                @if ($canBins)
                    <form wire:submit="addBin" class="flex flex-wrap items-end gap-2">
                        <x-ui.field label="Code" for="bin.code" class="w-32"><input id="bin.code" wire:model="bin.code" class="form-input font-mono uppercase" placeholder="A-01"></x-ui.field>
                        <x-ui.field label="Name" for="bin.name" class="flex-1"><input id="bin.name" wire:model="bin.name" class="form-input" placeholder="Aisle A, shelf 1"></x-ui.field>
                        <button class="btn-primary">Add bin</button>
                    </form>
                @endif
            @endif
        @endif
    </x-ui.modal>
</div>
