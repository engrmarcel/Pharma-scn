{{-- "Request to trade" dialog (RequestsTradingRelationships). --}}
<x-ui.modal wire:model="requesting" :title="'Request to trade with '.($requesting['name'] ?? '')" size="sm">
    @if ($requesting)
        <form wire:submit="sendRequest" id="rq-form" class="space-y-2">
            <x-ui.field label="Message (optional)" for="requesting.message"><textarea id="requesting.message" wire:model="requesting.message" rows="3" class="form-input" placeholder="Who you are and what you would like to buy"></textarea></x-ui.field>
            <p class="text-xs text-slate-500">Once the supplier approves, you can order from them; they set your payment terms.</p>
        </form>
    @endif
    <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="rq-form" class="btn-primary">Send request</button></x-slot:footer>
</x-ui.modal>
