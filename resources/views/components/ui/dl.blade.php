@props(['items' => [], 'cols' => 2])
{{-- Label/value pairs; null or empty values show a dash. --}}
<dl {{ $attributes->merge(['class' => 'grid gap-x-6 gap-y-3 text-sm '.($cols === 3 ? 'sm:grid-cols-3' : ($cols === 1 ? '' : 'sm:grid-cols-2'))]) }}>
    @foreach ($items as $label => $value)
        <div class="min-w-0">
            <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $label }}</dt>
            <dd class="mt-0.5 break-words text-slate-800">{{ ($value === null || $value === '') ? '—' : $value }}</dd>
        </div>
    @endforeach
</dl>
