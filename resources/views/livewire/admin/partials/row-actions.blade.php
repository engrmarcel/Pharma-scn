{{-- Move up / down / remove for a repeated item in the landing page editor. --}}
@php($min = $min ?? 1)
<div class="flex items-center gap-1">
    <button type="button" class="rounded p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-30" wire:click="move('{{ $list }}', {{ $i }}, -1)" @disabled($i === 0) aria-label="Move up">
        <x-heroicon-o-arrow-up class="size-4"/>
    </button>
    <button type="button" class="rounded p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-30" wire:click="move('{{ $list }}', {{ $i }}, 1)" @disabled($i === $count - 1) aria-label="Move down">
        <x-heroicon-o-arrow-down class="size-4"/>
    </button>
    <button type="button" class="rounded p-2 text-rose-600 hover:bg-rose-50 disabled:opacity-30" wire:click="remove('{{ $list }}', {{ $i }})" @disabled($count <= $min) aria-label="Remove">
        <x-heroicon-o-trash class="size-4"/>
    </button>
</div>
