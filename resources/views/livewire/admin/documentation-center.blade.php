<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="font-semibold text-navy-950">Documentation</h2>
            <p class="mt-1 text-sm text-slate-500">Setup, administration, user guide, application flows, API and external integration. Read here or download as PDF.</p>
        </div>
        <a href="{{ route('admin.documentation.pdf.all') }}" class="btn-primary" data-download-all>
            <x-heroicon-o-arrow-down-tray class="size-4"/> Download all (PDF)
        </a>
    </div>

    <div class="grid gap-6 lg:grid-cols-[17rem_1fr]">
        {{-- Document list --}}
        <nav class="space-y-2 lg:sticky lg:top-24 lg:self-start" aria-label="Documents">
            @foreach ($documents as $slug => [$file, $title, $audience, $summary])
                <div class="rounded-xl border {{ $current['slug'] === $slug ? 'border-[var(--brand-primary)] bg-navy-50' : 'border-slate-200 bg-white' }} p-3 transition">
                    <button type="button" wire:click="show('{{ $slug }}')" class="block w-full text-left" @if ($current['slug'] === $slug) aria-current="page" @endif>
                        <span class="block text-xs font-semibold text-gold-600">{{ str_pad((string) ($loop->index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="block font-semibold text-navy-950">{{ $title }}</span>
                        <span class="mt-0.5 block text-xs text-slate-500">{{ $audience }}</span>
                    </button>
                    <a href="{{ route('admin.documentation.pdf', $slug) }}" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-[var(--brand-primary)] hover:underline">
                        <x-heroicon-o-document-arrow-down class="size-4"/> PDF
                    </a>
                </div>
            @endforeach
        </nav>

        {{-- Current document --}}
        <article class="card overflow-hidden" wire:key="doc-{{ $current['slug'] }}">
            <header class="border-b border-slate-100 px-6 py-5">
                <p class="eyebrow">For: {{ $current['audience'] }}</p>
                <h1 class="mt-1 font-display text-2xl font-bold text-navy-950">{{ $current['title'] }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $current['summary'] }}</p>
                @if (count($current['toc']) > 2)
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($current['toc'] as $t)
                            <a href="#{{ $t['id'] }}" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-700 hover:bg-navy-50 hover:text-[var(--brand-primary)]">{{ $t['text'] }}</a>
                        @endforeach
                    </div>
                @endif
            </header>
            <div class="doc-prose px-6 py-6">
                {!! $current['html'] !!}
            </div>
        </article>
    </div>
</div>
