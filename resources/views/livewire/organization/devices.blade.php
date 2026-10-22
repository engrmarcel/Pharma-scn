<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-44"><option value="">All</option><option value="active">Active</option><option value="revoked">Revoked</option></select>
        </x-ui.field>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Device</th><th>User</th><th>Role</th><th>App</th><th>Last contact</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $d)
                        <tr wire:key="d-{{ $d['id'] }}">
                            <td><span class="font-medium text-navy-900">{{ $d['name'] }}</span><span class="muted block">{{ $d['platform'] }}</span></td>
                            <td>{{ $d['user']['name'] ?? '—' }}<span class="muted block">{{ $d['user']['email'] ?? '' }}</span></td>
                            <td>{{ str_replace('_', ' ', $d['role'] ?? '') }}</td>
                            <td class="font-mono text-xs">{{ $d['app_version'] ?? '—' }}</td>
                            <td>{{ ($d['last_seen_at'] ?? null) ? fmt()->dateTime($d['last_seen_at']) : '—' }}</td>
                            <td><x-ui.badge :status="$d['status']"/>@if ($d['wipe_requested'] ?? false)<x-ui.badge tone="red" class="ml-1">wipe</x-ui.badge>@endif</td>
                            <td class="text-right">@if ($d['status'] === 'active')<button type="button" class="link text-xs text-rose-700" wire:click="startRevoke('{{ $d['id'] }}', @js($d['name']))">Revoke</button>@endif</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="device-phone-mobile" title="No devices registered">Devices register themselves when someone signs in to the field or POS app.</x-ui.empty>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="revoking" title="Revoke device" size="sm">
        @if ($revoking)
            <form wire:submit="revoke" id="revoke-form" class="space-y-4">
                <p class="text-sm text-slate-600"><strong>{{ $revoking['name'] }}</strong> will be signed out at its next contact.</p>
                <x-ui.field label="Reason" for="revoking.reason"><input id="revoking.reason" wire:model="revoking.reason" class="form-input" placeholder="Lost phone"></x-ui.field>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="revoking.wipe" class="form-check mt-0.5"><span>Also erase the app's local data on the device<span class="muted block">Unsynced work on the device is lost.</span></span></label>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="revoke-form" class="btn-danger">Revoke</button>
        </x-slot:footer>
    </x-ui.modal>
</div>
