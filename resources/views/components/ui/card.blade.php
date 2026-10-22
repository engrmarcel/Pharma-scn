@props(['title' => null, 'icon' => null, 'padded' => true])
<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($actions))
        <header class="card-header flex-wrap">
            <h2 class="card-title">
                @if ($icon)<x-dynamic-component :component="'heroicon-o-'.$icon" class="size-4 text-gold-500"/>@endif
                {{ $title }}
            </h2>
            @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div @class(['p-5' => $padded])>{{ $slot }}</div>
</section>
