<div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
    <div class="space-y-4">
        @forelse ($conversation as $c)
            <div class="flex justify-end"><p class="max-w-[85%] rounded-2xl rounded-br-sm bg-navy-900 px-4 py-2 text-sm text-white">{{ $c['question'] }}</p></div>
            <div class="card max-w-[95%] p-4">
                <p class="whitespace-pre-line text-sm text-slate-800">{{ $c['answer'] }}</p>
                @if (! empty($c['caveats']))<ul class="mt-2 list-disc pl-5 text-xs text-slate-500">@foreach ($c['caveats'] as $cv)<li>{{ $cv }}</li>@endforeach</ul>@endif
                <p class="mt-2 text-[11px] text-slate-400">{{ $c['label'] }}{{ $c['reports'] ? ' · reports used: '.implode(', ', $c['reports']) : '' }}{{ $c['planner'] ? ' · planned by '.$c['planner'] : '' }}</p>
            </div>
        @empty
            <x-ui.card><x-ui.empty icon="chat-bubble-bottom-center-text" title="Ask about your data">Try "What were our top selling products last month?" or "Which batches expire in the next 60 days?"</x-ui.empty></x-ui.card>
        @endforelse

        <form wire:submit="ask" class="card flex gap-2 p-3">
            <x-ui.field for="ask.question" class="flex-1"><input wire:model="question" class="form-input" placeholder="Ask a question…" maxlength="500" aria-label="Question"></x-ui.field>
            <button class="btn-primary" wire:loading.attr="disabled" wire:target="ask"><span wire:loading.remove wire:target="ask">Ask</span><span wire:loading wire:target="ask">Working…</span></button>
        </form>
    </div>

    <aside class="space-y-4">
        <x-ui.card title="How answers are made" icon="information-circle">
            <p class="text-sm text-slate-600">The question is turned into a plan of read-only reports. The reports run with your permissions and locations, and the answer is written from their figures.</p>
            @if ($settings)
                <p class="mt-3 text-sm">External language model: <x-ui.badge :tone="$settings['external_processing'] ? 'green' : 'slate'">{{ $settings['external_processing'] ? 'on' : 'off' }}</x-ui.badge></p>
                <p class="mt-1 text-xs text-slate-500">{{ $settings['what_is_sent'] }}</p>
                @if ($settings['available'])
                    @can('organization.manage')
                        @if (! $settings['external_processing'])
                            <label class="mt-3 flex items-start gap-2 text-xs"><input type="checkbox" wire:model="acknowledge" class="form-check mt-0.5">I agree on behalf of the organization that questions may be processed by {{ $settings['provider'] }}.</label>
                            <button type="button" class="btn-light btn-sm mt-2" wire:click="setExternal(true)">Turn on</button>
                        @else
                            <button type="button" class="btn-light btn-sm mt-3" wire:click="setExternal(false)">Turn off</button>
                        @endif
                    @endcan
                @else
                    <p class="mt-1 text-xs text-slate-500">Not configured on this platform; questions are planned by built-in rules.</p>
                @endif
            @endif
        </x-ui.card>
    </aside>
</div>
