@props(['label' => null, 'for' => null, 'error' => null, 'hint' => null])
@php $error ??= $for; @endphp
<div {{ $attributes }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="form-label">{{ $label }}</label>
    @endif
    {{ $slot }}
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @if ($error)
        @error($error) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
    @endif
</div>
