<div class="space-y-6">
    @if ($invited)
        <div class="card border-emerald-200 bg-emerald-50/60 p-4 text-sm" role="status">
            <p class="font-semibold text-emerald-800">Invitation created for {{ $invited['email'] }}</p>
            <p class="mt-1 text-emerald-800/80">The invitation email carries a one-time link. It expires {{ fmt()->dateTime($invited['expires_at']) }}. Roles: {{ implode(', ', $invited['roles']) }}.</p>
        </div>
    @endif

    <x-ui.card title="Members" icon="users" :padded="false">
        <x-slot:actions>
            @if ($canManage)<button type="button" class="btn-primary btn-sm" wire:click="startInvite"><x-heroicon-o-envelope class="size-4"/>Invite member</button>@endif
        </x-slot:actions>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Roles</th><th>Locations</th><th>Joined</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($members as $m)
                        <tr wire:key="m-{{ $m['id'] }}">
                            <td>
                                <span class="block font-medium text-navy-900">{{ $m['user']['name'] ?? '—' }}</span>
                                <span class="muted">{{ $m['user']['email'] ?? '' }}</span>
                            </td>
                            <td><div class="flex flex-wrap gap-1">@foreach ($m['roles'] ?? [] as $r)<x-ui.badge tone="blue">{{ $r['name'] }}</x-ui.badge>@endforeach</div></td>
                            <td>{{ $m['all_locations'] ? 'All locations' : 'Selected locations' }}</td>
                            <td>{{ $m['joined_at'] ? fmt()->date($m['joined_at']) : '—' }}</td>
                            <td><x-ui.badge :status="$m['status']"/></td>
                            <td class="text-right">@if ($canManage)<button type="button" class="link text-xs" wire:click="edit(@js($m))">Manage</button>@endif</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="users" title="No members"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </x-ui.card>

    <x-ui.modal wire:model="invite" title="Invite a member" size="md">
        @if ($invite)
            <form wire:submit="sendInvite" id="invite-form" class="space-y-4">
                <x-ui.field label="Email" for="invite.email"><input id="invite.email" type="email" wire:model="invite.email" class="form-input" autocomplete="off"></x-ui.field>
                <x-ui.field label="Roles" error="invite.roles">
                    <div class="grid max-h-72 gap-1 overflow-y-auto rounded-lg border border-slate-200 p-2 sm:grid-cols-2">
                        @foreach ($roles as $r)
                            <label class="flex items-start gap-2 rounded p-1.5 text-sm hover:bg-slate-50">
                                <input type="checkbox" value="{{ $r['code'] }}" wire:model="invite.roles" class="form-check mt-0.5">
                                <span><span class="font-medium text-navy-900">{{ $r['name'] }}</span>@if ($r['description'] ?? null)<span class="block text-xs text-slate-500">{{ $r['description'] }}</span>@endif</span>
                            </label>
                        @endforeach
                    </div>
                </x-ui.field>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="invite-form" class="btn-primary" wire:loading.attr="disabled">Send invitation</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="member" :title="'Manage '.($member['name'] ?? 'member')" size="lg">
        @if ($member)
            <div class="space-y-6">
                <section>
                    <h3 class="mb-2 text-sm font-semibold text-navy-950">Roles</h3>
                    <div class="grid max-h-60 gap-1 overflow-y-auto rounded-lg border border-slate-200 p-2 sm:grid-cols-2">
                        @foreach ($roles as $r)
                            <label class="flex items-center gap-2 rounded p-1.5 text-sm hover:bg-slate-50">
                                <input type="checkbox" value="{{ $r['code'] }}" wire:model="member.roles" class="form-check">{{ $r['name'] }}
                            </label>
                        @endforeach
                    </div>
                    @error('member.roles') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    <button type="button" class="btn-primary btn-sm mt-2" wire:click="saveRoles">Save roles</button>
                </section>

                <section>
                    <h3 class="mb-2 text-sm font-semibold text-navy-950">Locations</h3>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="member.all_locations" class="form-check">Works in all branches and warehouses</label>
                    @unless ($member['all_locations'])
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="mb-1 text-xs font-semibold uppercase text-slate-400">Branches</p>
                                @foreach ($branches as $b)
                                    <label class="flex items-center gap-2 py-0.5 text-sm"><input type="checkbox" value="{{ $b['id'] }}" wire:model="member.branch_ids" class="form-check">{{ $b['name'] }}</label>
                                @endforeach
                            </div>
                            <div>
                                <p class="mb-1 text-xs font-semibold uppercase text-slate-400">Warehouses</p>
                                @foreach ($warehouses as $w)
                                    <label class="flex items-center gap-2 py-0.5 text-sm"><input type="checkbox" value="{{ $w['id'] }}" wire:model="member.warehouse_ids" class="form-check">{{ $w['name'] }}</label>
                                @endforeach
                            </div>
                        </div>
                    @endunless
                    <button type="button" class="btn-primary btn-sm mt-3" wire:click="saveLocations">Save locations</button>
                </section>

                <section class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                    <span class="mr-2 text-sm text-slate-600">Status: <x-ui.badge :status="$member['status']"/></span>
                    @if ($member['status'] !== 'active')<button type="button" class="btn-light btn-sm" wire:click="setStatus('active')">Reactivate</button>@endif
                    @if ($member['status'] === 'active')<button type="button" class="btn-light btn-sm" wire:click="setStatus('suspended')" wire:confirm="Suspend this member? They lose access until reactivated.">Suspend</button>@endif
                    @if ($member['status'] !== 'revoked')<button type="button" class="btn-danger btn-sm" wire:click="setStatus('revoked')" wire:confirm="Remove this member from the organization?">Remove</button>@endif
                </section>
            </div>
        @endif
    </x-ui.modal>
</div>
