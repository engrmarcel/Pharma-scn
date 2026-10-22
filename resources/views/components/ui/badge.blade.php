@props(['status' => null, 'tone' => null])
@php
    $s = strtolower((string) $status);
    $tone ??= match (true) {
        in_array($s, ['active', 'approved', 'completed', 'posted', 'paid', 'delivered', 'received', 'verified', 'processed', 'released', 'accepted', 'won', 'open', 'available', 'ok', 'success', 'succeeded', 'resolved', 'done', 'confirmed', 'fulfilled', 'issued', 'commissioned', 'reconciled', 'enabled', 'healthy', 'invoiced', 'converted', 'awarded', 'reported', 'acknowledged'], true) => 'green',
        in_array($s, ['draft', 'pending', 'pending_review', 'submitted', 'requested', 'awaiting_approval', 'partially_received', 'partially_delivered', 'partially_paid', 'partially_invoiced', 'sent', 'quoted', 'in_progress', 'processing', 'planned', 'scheduled', 'held', 'queued', 'changes_requested', 'unverified', 'in_transit', 'dispatched', 'picked_up', 'assigned', 'counting', 'retrying', 'low', 'warning', 'degraded', 'paused', 'proposed', 'negotiation', 'qualified', 'new', 'under_review', 'awaiting_payment', 'ready'], true) => 'amber',
        in_array($s, ['cancelled', 'canceled', 'rejected', 'failed', 'suspended', 'revoked', 'expired', 'quarantined', 'quarantine', 'blocked', 'disputed', 'escalated', 'lost', 'overdue', 'recalled', 'damaged', 'returned', 'locked', 'void', 'voided', 'dead', 'out_of_stock', 'error', 'reversed', 'decommissioned', 'disabled', 'declined', 'unhealthy', 'critical', 'breach'], true) => 'red',
        in_array($s, ['closed', 'archived', 'ignored', 'inactive', 'superseded'], true) => 'slate',
        default => 'blue',
    };
    $classes = [
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'red' => 'bg-rose-50 text-rose-700 ring-rose-600/20',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'slate' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    ][$tone] ?? 'bg-slate-100 text-slate-700 ring-slate-500/20';
@endphp
<span {{ $attributes->merge(['class' => "badge whitespace-nowrap {$classes}"]) }}>{{ $slot->isEmpty() ? str_replace('_', ' ', (string) $status) : $slot }}</span>
