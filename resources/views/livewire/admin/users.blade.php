<div>
    <x-ui.toolbar><x-ui.search placeholder="Name or email…"/></x-ui.toolbar>
    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Email</th><th class="num">Organizations</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $u)
                        <tr wire:key="u-{{ $u['id'] }}">
                            <td class="font-medium text-navy-900">{{ $u['name'] }}</td><td>{{ $u['email'] }}</td><td class="num">{{ $u['memberships'] }}</td>
                            <td>{{ $u['last_login_at'] ? fmt()->dateTime($u['last_login_at']) : 'Never' }}</td><td><x-ui.badge :status="$u['status']"/></td>
                            <td class="whitespace-nowrap text-right">
                                @if ($u['id'] !== auth()->id())
                                    @if ($u['status'] === 'active')<button type="button" class="link text-xs text-rose-700" wire:click="start({{ $u['id'] }}, @js($u['name']), 'suspend')">Suspend</button>
                                    @else<button type="button" class="link text-xs" wire:click="start({{ $u['id'] }}, @js($u['name']), 'reactivate')">Reactivate</button>@endif
                                    <button type="button" class="link ml-3 text-xs" wire:click="start({{ $u['id'] }}, @js($u['name']), 'mfa')">Reset two-factor</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="users" title="No users match"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>
    <x-ui.modal wire:model="acting" :title="['suspend' => 'Suspend', 'reactivate' => 'Reactivate', 'mfa' => 'Reset two-factor for'][$acting['action'] ?? 'suspend'].' '.($acting['name'] ?? '')" size="sm">
        @if ($acting)
            <form wire:submit="apply" id="u-form">
                <x-ui.field :label="$acting['action'] === 'reactivate' ? 'Note (optional)' : 'Reason'" for="acting.reason"><textarea wire:model="acting.reason" rows="2" class="form-input"></textarea></x-ui.field>
                @if ($acting['action'] === 'mfa')<p class="mt-2 text-xs text-slate-500">Check the person's identity another way first; this removes their second factor.</p>@endif
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="u-form" class="btn-primary">Confirm</button></x-slot:footer>
    </x-ui.modal>
</div>
