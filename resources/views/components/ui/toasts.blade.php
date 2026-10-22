{{-- Toasts raised by screens ($this->dispatch('notify', type:, message:)). --}}
<div x-data="{
        items: [],
        push(e) { const id = Date.now() + Math.random(); this.items.push({ id, type: e.type || 'success', message: e.message }); setTimeout(() => this.remove(id), e.type === 'error' ? 9000 : 4500); },
        remove(id) { this.items = this.items.filter(i => i.id !== id); },
     }"
     @notify.window="push($event.detail)"
     class="no-print pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-end gap-2 sm:left-auto sm:w-96" aria-live="polite">
    <template x-for="t in items" :key="t.id">
        <div role="status" data-toast
             class="pointer-events-auto flex w-full items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-lg animate-fade-up"
             :class="t.type === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : (t.type === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-800')">
            <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="t.type === 'error' ? 'bg-rose-500' : (t.type === 'warning' ? 'bg-amber-500' : 'bg-emerald-500')"></span>
            <p class="flex-1" x-text="t.message"></p>
            <button type="button" class="opacity-60 hover:opacity-100" @click="remove(t.id)" aria-label="Dismiss">&times;</button>
        </div>
    </template>
</div>
