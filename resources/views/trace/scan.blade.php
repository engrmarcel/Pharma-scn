@php
    $tone = match ($result['result']) {
        'ok' => ['bg-emerald-50 text-emerald-800 ring-emerald-200', 'check-badge'],
        'unregistered', 'unknown' => ['bg-amber-50 text-amber-800 ring-amber-200', 'question-mark-circle'],
        default => ['bg-rose-50 text-rose-800 ring-rose-200', 'exclamation-triangle'],
    };
    $labels = ['ok' => 'No problem recorded', 'expired' => 'Expired', 'recalled' => 'Recalled', 'revoked' => 'Code withdrawn',
        'dispensed' => 'Already dispensed', 'unregistered' => 'Serial not registered', 'unknown' => 'Unknown product'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Pack check · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<main class="mx-auto max-w-md px-4 py-10">
    <p class="eyebrow text-slate-500">Pack check</p>
    <div class="mt-3 rounded-2xl p-5 ring-1 {{ $tone[0] }}">
        <div class="flex items-center gap-3">
            <x-dynamic-component :component="'heroicon-o-'.$tone[1]" class="size-8 shrink-0"/>
            <div>
                <p class="text-lg font-bold">{{ $labels[$result['result']] ?? $result['result'] }}</p>
                <p class="text-sm">{{ $result['message'] }}</p>
            </div>
        </div>
    </div>

    @if (! empty($result['product']))
        <dl class="card mt-5 grid grid-cols-3 gap-y-2 p-5 text-sm">
            <dt class="text-slate-500">Product</dt><dd class="col-span-2 font-medium">{{ $result['product']['display_name'] }}</dd>
            <dt class="text-slate-500">Generic name</dt><dd class="col-span-2">{{ $result['product']['generic_name'] }} {{ $result['product']['strength_text'] }}</dd>
            @if (! empty($result['batch_number']))<dt class="text-slate-500">Batch</dt><dd class="col-span-2">{{ $result['batch_number'] }}</dd>@endif
            @if (! empty($result['expiry_date']))<dt class="text-slate-500">Expiry</dt><dd class="col-span-2">{{ $result['expiry_date'] }}</dd>@endif
            @if (! empty($result['serial']))<dt class="text-slate-500">Serial</dt><dd class="col-span-2">{{ $result['serial'] }}</dd>@endif
        </dl>
    @endif

    @foreach ($result['warnings'] ?? [] as $warning)
        <p class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $warning }}</p>
    @endforeach

    <p class="mt-6 text-xs leading-relaxed text-slate-500">{{ $result['disclaimer'] }}</p>
</main>
</body>
</html>
