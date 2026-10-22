@php
    $input = 'form-input';
    $err = fn ($key) => $errors->first('content.'.$key);
@endphp

<div class="space-y-8" x-data>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="font-semibold text-navy-950">Landing page</h2>
            <p class="mt-1 text-sm text-slate-500">The public page at <a href="{{ url('/') }}" target="_blank" class="font-medium text-[var(--brand-primary)] hover:underline">{{ url('/') }}</a>. Changes go live when you publish.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ url('/') }}" target="_blank" class="btn-light"><x-heroicon-o-arrow-top-right-on-square class="size-4"/> View page</a>
            <button type="button" class="btn-light" wire:click="resetToDefaults" wire:confirm="Replace all landing page content with the defaults?">Reset to defaults</button>
            <button type="button" class="btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save,video,poster,shots">
                <span wire:loading.remove wire:target="save">Publish</span><span wire:loading wire:target="save">Publishing…</span>
            </button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">Some fields need attention: {{ $errors->count() }} issue(s). Nothing was published.</div>
    @endif

    {{-- Hero --}}
    <section class="card">
        <header class="card-header"><h3 class="card-title"><x-heroicon-o-sparkles class="size-4"/> Hero</h3></header>
        <div class="grid gap-4 p-5 md:grid-cols-2">
            @foreach (['eyebrow' => 'Eyebrow', 'title' => 'Headline', 'highlight' => 'Highlighted line (optional)', 'primary_label' => 'Main button label', 'primary_url' => 'Main button link',
                'secondary_label' => 'Second button label (optional)', 'secondary_url' => 'Second button link'] as $key => $label)
                <label class="block"><span class="form-label">{{ $label }}</span>
                    <input type="text" class="{{ $input }}" wire:model="content.hero.{{ $key }}">
                    @if ($err("hero.$key"))<span class="mt-1 block text-xs text-rose-700">{{ $err("hero.$key") }}</span>@endif
                </label>
            @endforeach
            <label class="block md:col-span-2"><span class="form-label">Introduction</span>
                <textarea rows="3" class="{{ $input }}" wire:model="content.hero.subtitle"></textarea>
                @if ($err('hero.subtitle'))<span class="mt-1 block text-xs text-rose-700">{{ $err('hero.subtitle') }}</span>@endif
            </label>
            <p class="text-xs text-slate-500 md:col-span-2">Links: a page on this site (<code>/login</code>), a section (<code>#network</code>) or a full <code>https://</code> address.</p>
        </div>
    </section>

    {{-- Background --}}
    <section class="card">
        <header class="card-header"><h3 class="card-title"><x-heroicon-o-film class="size-4"/> Hero background</h3></header>
        <div class="grid gap-5 p-5 md:grid-cols-2">
            <fieldset class="md:col-span-2">
                <legend class="form-label">Background</legend>
                <div class="flex flex-wrap gap-3">
                    @foreach (['particles' => 'Flowing particles (animated network)', 'video' => 'Full background video'] as $mode => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-4 py-2.5 text-sm {{ $content['background']['mode'] === $mode ? 'border-[var(--brand-primary)] bg-navy-50' : 'border-slate-300' }}">
                            <input type="radio" value="{{ $mode }}" wire:model.live="content.background.mode"> {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            @if ($content['background']['mode'] === 'video')
                <label class="block"><span class="form-label">Video link (mp4 or webm)</span>
                    <input type="text" class="{{ $input }}" wire:model="content.background.video_url" placeholder="https://… or upload below">
                    @if ($err('background.video_url'))<span class="mt-1 block text-xs text-rose-700">{{ $err('background.video_url') }}</span>@endif
                </label>
                <label class="block"><span class="form-label">Or upload a video (up to 10 MB)</span>
                    <input type="file" accept="video/mp4,video/webm" wire:model="video" class="block w-full text-sm">
                    @error('video')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror
                </label>
                <label class="block"><span class="form-label">Poster image link (shown while the video loads)</span>
                    <input type="text" class="{{ $input }}" wire:model="content.background.poster_url">
                </label>
                <label class="block"><span class="form-label">Or upload a poster image</span>
                    <input type="file" accept="image/*" wire:model="poster" class="block w-full text-sm">
                    @error('poster')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror
                </label>
            @else
                <label class="block"><span class="form-label">Particle density ({{ $content['background']['particle_density'] }})</span>
                    <input type="range" min="20" max="160" step="10" class="w-full accent-[var(--brand-primary)]" wire:model.live="content.background.particle_density">
                </label>
            @endif
            <label class="block"><span class="form-label">Overlay darkness behind the text ({{ $content['background']['overlay_opacity'] }}%)</span>
                <input type="range" min="20" max="95" step="5" class="w-full accent-[var(--brand-primary)]" wire:model.live="content.background.overlay_opacity">
            </label>
        </div>
    </section>

    {{-- Stats --}}
    <section class="card">
        <header class="card-header"><h3 class="card-title"><x-heroicon-o-chart-bar class="size-4"/> Headline figures</h3>
            @if (count($content['stats']) < 6)<button type="button" class="btn-light" wire:click="add('stats')">Add figure</button>@endif</header>
        <div class="space-y-3 p-5">
            @foreach ($content['stats'] as $i => $s)
                <div class="grid items-start gap-3 sm:grid-cols-[8rem_5rem_1fr_auto]" wire:key="stat-{{ $i }}">
                    <input type="number" min="0" class="{{ $input }}" wire:model="content.stats.{{ $i }}.value" aria-label="Value">
                    <input type="text" class="{{ $input }}" wire:model="content.stats.{{ $i }}.suffix" placeholder="%, h…" aria-label="Suffix">
                    <input type="text" class="{{ $input }}" wire:model="content.stats.{{ $i }}.label" placeholder="Label" aria-label="Label">
                    @include('livewire.admin.partials.row-actions', ['list' => 'stats', 'i' => $i, 'count' => count($content['stats'])])
                    @foreach (['value', 'label'] as $f)@if ($err("stats.$i.$f"))<span class="text-xs text-rose-700 sm:col-span-4">{{ $err("stats.$i.$f") }}</span>@endif @endforeach
                </div>
            @endforeach
        </div>
    </section>

    {{-- Network --}}
    <section class="card">
        <header class="card-header"><h3 class="card-title"><x-heroicon-o-share class="size-4"/> Network infographic</h3></header>
        <div class="space-y-6 p-5">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block"><span class="form-label">Eyebrow</span><input type="text" class="{{ $input }}" wire:model="content.network.eyebrow"></label>
                <label class="block"><span class="form-label">Title</span><input type="text" class="{{ $input }}" wire:model="content.network.title"></label>
                <label class="block md:col-span-2"><span class="form-label">Introduction</span><textarea rows="2" class="{{ $input }}" wire:model="content.network.intro"></textarea></label>
            </div>
            @foreach (['network.nodes' => ['Links in the chain (left to right)', 3, 8], 'network.services' => ['Shared services around the platform', 1, 6]] as $list => [$heading, $min, $max])
                <div>
                    <div class="mb-3 flex items-center justify-between">
                        <h4 class="text-sm font-semibold text-navy-900">{{ $heading }}</h4>
                        @if (count(data_get($content, $list)) < $max)<button type="button" class="btn-light" wire:click="add('{{ $list }}')">Add</button>@endif
                    </div>
                    <div class="space-y-3">
                        @foreach (data_get($content, $list) as $i => $n)
                            <div class="grid items-start gap-3 rounded-lg border border-slate-200 p-3 md:grid-cols-[10rem_12rem_1fr_auto]" wire:key="{{ $list }}-{{ $i }}">
                                <select class="{{ $input }}" wire:model="content.{{ $list }}.{{ $i }}.icon" aria-label="Icon">
                                    @foreach ($icons as $icon)<option value="{{ $icon }}">{{ str_replace('-', ' ', $icon) }}</option>@endforeach
                                </select>
                                <input type="text" class="{{ $input }}" wire:model="content.{{ $list }}.{{ $i }}.label" placeholder="Label" aria-label="Label">
                                <input type="text" class="{{ $input }}" wire:model="content.{{ $list }}.{{ $i }}.description" placeholder="Description" aria-label="Description">
                                @include('livewire.admin.partials.row-actions', ['list' => $list, 'i' => $i, 'count' => count(data_get($content, $list)), 'min' => $min])
                                @foreach (['label', 'description'] as $f)@if ($err("$list.$i.$f"))<span class="text-xs text-rose-700 md:col-span-4">{{ $err("$list.$i.$f") }}</span>@endif @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Journey --}}
    <section class="card">
        <header class="card-header"><h3 class="card-title"><x-heroicon-o-arrow-long-right class="size-4"/> Batch journey</h3>
            @if (count($content['journey']['steps']) < 6)<button type="button" class="btn-light" wire:click="add('journey.steps')">Add step</button>@endif</header>
        <div class="space-y-3 p-5">
            <label class="block"><span class="form-label">Title</span><input type="text" class="{{ $input }}" wire:model="content.journey.title"></label>
            @foreach ($content['journey']['steps'] as $i => $step)
                <div class="grid items-start gap-3 md:grid-cols-[12rem_1fr_auto]" wire:key="step-{{ $i }}">
                    <input type="text" class="{{ $input }}" wire:model="content.journey.steps.{{ $i }}.title" placeholder="Step" aria-label="Step">
                    <input type="text" class="{{ $input }}" wire:model="content.journey.steps.{{ $i }}.description" placeholder="Description" aria-label="Description">
                    @include('livewire.admin.partials.row-actions', ['list' => 'journey.steps', 'i' => $i, 'count' => count($content['journey']['steps']), 'min' => 2])
                </div>
            @endforeach
        </div>
    </section>

    {{-- Features --}}
    <section class="card">
        <header class="card-header"><h3 class="card-title"><x-heroicon-o-squares-2x2 class="size-4"/> Platform features</h3>
            @if (count($content['features']['items']) < 12)<button type="button" class="btn-light" wire:click="add('features.items')">Add feature</button>@endif</header>
        <div class="space-y-3 p-5">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block"><span class="form-label">Title</span><input type="text" class="{{ $input }}" wire:model="content.features.title"></label>
                <label class="block"><span class="form-label">Introduction</span><input type="text" class="{{ $input }}" wire:model="content.features.intro"></label>
            </div>
            @foreach ($content['features']['items'] as $i => $f)
                <div class="grid items-start gap-3 rounded-lg border border-slate-200 p-3 md:grid-cols-[10rem_14rem_1fr_auto]" wire:key="feature-{{ $i }}">
                    <select class="{{ $input }}" wire:model="content.features.items.{{ $i }}.icon" aria-label="Icon">
                        @foreach ($icons as $icon)<option value="{{ $icon }}">{{ str_replace('-', ' ', $icon) }}</option>@endforeach
                    </select>
                    <input type="text" class="{{ $input }}" wire:model="content.features.items.{{ $i }}.title" placeholder="Title" aria-label="Title">
                    <input type="text" class="{{ $input }}" wire:model="content.features.items.{{ $i }}.description" placeholder="Description" aria-label="Description">
                    @include('livewire.admin.partials.row-actions', ['list' => 'features.items', 'i' => $i, 'count' => count($content['features']['items'])])
                </div>
            @endforeach
        </div>
    </section>

    {{-- Field force app --}}
    <section class="card">
        <header class="card-header"><h3 class="card-title"><x-heroicon-o-device-phone-mobile class="size-4"/> Field force app</h3></header>
        <div class="space-y-5 p-5">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block"><span class="form-label">Eyebrow</span><input type="text" class="{{ $input }}" wire:model="content.app.eyebrow"></label>
                <label class="block"><span class="form-label">Title</span><input type="text" class="{{ $input }}" wire:model="content.app.title"></label>
                <label class="block md:col-span-2"><span class="form-label">Description</span><textarea rows="3" class="{{ $input }}" wire:model="content.app.description"></textarea></label>
                <label class="block md:col-span-2"><span class="form-label">Note under the list (optional)</span><input type="text" class="{{ $input }}" wire:model="content.app.note"></label>
            </div>
            <div>
                <div class="mb-3 flex items-center justify-between"><h4 class="text-sm font-semibold text-navy-900">Bullet points</h4>
                    @if (count($content['app']['bullets']) < 8)<button type="button" class="btn-light" wire:click="add('app.bullets')">Add</button>@endif</div>
                <div class="space-y-3">
                    @foreach ($content['app']['bullets'] as $i => $b)
                        <div class="grid items-start gap-3 md:grid-cols-[1fr_auto]" wire:key="bullet-{{ $i }}">
                            <input type="text" class="{{ $input }}" wire:model="content.app.bullets.{{ $i }}" aria-label="Bullet">
                            @include('livewire.admin.partials.row-actions', ['list' => 'app.bullets', 'i' => $i, 'count' => count($content['app']['bullets'])])
                        </div>
                    @endforeach
                </div>
            </div>
            <div>
                <div class="mb-3 flex items-center justify-between"><h4 class="text-sm font-semibold text-navy-900">Phone screens (up to 3; the first is shown in front)</h4>
                    @if (count($content['app']['screenshots']) < 3)<button type="button" class="btn-light" wire:click="add('app.screenshots')">Add screen</button>@endif</div>
                <div class="grid gap-4 md:grid-cols-3">
                    @foreach ($content['app']['screenshots'] as $i => $shot)
                        <div class="rounded-lg border border-slate-200 p-3" wire:key="shot-{{ $i }}">
                            <div class="mx-auto aspect-[9/19.5] w-32 overflow-hidden rounded-2xl border-4 border-navy-950 bg-slate-100">
                                @if (isset($shots[$i]) && $shots[$i])
                                    <img src="{{ $shots[$i]->temporaryUrl() }}" alt="" class="size-full object-cover object-top">
                                @else
                                    <img src="{{ $shot['url'] }}" alt="" class="size-full object-cover object-top">
                                @endif
                            </div>
                            <label class="mt-3 block"><span class="form-label">Caption</span><input type="text" class="{{ $input }}" wire:model="content.app.screenshots.{{ $i }}.caption"></label>
                            <label class="mt-2 block"><span class="form-label">Replace image</span><input type="file" accept="image/*" wire:model="shots.{{ $i }}" class="block w-full text-xs"></label>
                            @error("shots.$i")<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror
                            <label class="mt-2 block"><span class="form-label">Or image link</span><input type="text" class="{{ $input }}" wire:model="content.app.screenshots.{{ $i }}.url"></label>
                            <div class="mt-2 flex justify-end">@include('livewire.admin.partials.row-actions', ['list' => 'app.screenshots', 'i' => $i, 'count' => count($content['app']['screenshots'])])</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- Call to action and footer --}}
    <section class="card">
        <header class="card-header"><h3 class="card-title"><x-heroicon-o-megaphone class="size-4"/> Call to action and footer</h3></header>
        <div class="grid gap-4 p-5 md:grid-cols-2">
            <label class="block"><span class="form-label">Title</span><input type="text" class="{{ $input }}" wire:model="content.cta.title"></label>
            <label class="block"><span class="form-label">Text</span><input type="text" class="{{ $input }}" wire:model="content.cta.text"></label>
            <label class="block"><span class="form-label">Button label</span><input type="text" class="{{ $input }}" wire:model="content.cta.button_label"></label>
            <label class="block"><span class="form-label">Button link</span><input type="text" class="{{ $input }}" wire:model="content.cta.button_url">
                @if ($err('cta.button_url'))<span class="mt-1 block text-xs text-rose-700">{{ $err('cta.button_url') }}</span>@endif</label>
            <label class="block"><span class="form-label">Footer text</span><input type="text" class="{{ $input }}" wire:model="content.footer.text"></label>
            <label class="block"><span class="form-label">Contact e-mail (optional)</span><input type="email" class="{{ $input }}" wire:model="content.footer.contact_email">
                @if ($err('footer.contact_email'))<span class="mt-1 block text-xs text-rose-700">{{ $err('footer.contact_email') }}</span>@endif</label>
        </div>
    </section>

    <div class="flex justify-end">
        <button type="button" class="btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save,video,poster,shots">Publish</button>
    </div>
</div>
