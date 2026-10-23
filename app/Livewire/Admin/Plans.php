<?php

namespace App\Livewire\Admin;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Subscription plans: price, trial, visibility and the entitlements (modules, features and limits) each one grants. */
#[Title('Subscription plans')]
class Plans extends WebPage
{
    public const ROUTE = 'admin.plans';

    public ?array $editing = null;

    public function edit(string $id): void
    {
        $plan = collect($this->read('admin/subscription-plans', [], ['data' => []])['data'])->firstWhere('id', $id);
        if (! $plan) {
            return;
        }
        $this->resetErrorBag();
        // Entitlement keys contain dots ("module.pos"), so they are held as a list rather than keyed by name.
        $rows = collect(config('entitlements.keys'))->map(fn ($def, $key) => [
            'key' => $key, 'label' => $def[1], 'type' => $def[0],
            'enabled' => (bool) ($plan['entitlements'][$key]['enabled'] ?? false),
            'limit' => $plan['entitlements'][$key]['limit'] ?? null,
        ])->values()->all();
        $this->editing = collect($plan)->only(['id', 'code', 'name', 'description', 'billing_period', 'price', 'currency_code', 'trial_days', 'is_public', 'is_active'])->all() + ['entitlements' => $rows];
    }

    public function save(): void
    {
        $e = $this->editing;
        $payload = collect($e)->only(['name', 'description', 'billing_period', 'price', 'currency_code', 'trial_days', 'is_public', 'is_active'])
            ->map(fn ($v) => $v === '' ? null : $v)->all();
        $payload['trial_days'] = (int) ($payload['trial_days'] ?? 0);
        $payload['entitlements'] = collect($e['entitlements'])->mapWithKeys(fn ($r) => [$r['key'] => [
            'enabled' => (bool) $r['enabled'],
            'limit' => $r['type'] === 'limit' && $r['limit'] !== null && $r['limit'] !== '' ? (int) $r['limit'] : null,
        ]])->all();
        if ($this->submit('editing', "admin/subscription-plans/{$e['id']}", $payload, 'Plan saved. Organizations on it get the new entitlements straight away.', 'patch') !== null) {
            $this->editing = null;
        }
    }

    public function render()
    {
        return view('livewire.admin.plans', [
            'plans' => $this->read('admin/subscription-plans', [], ['data' => []])['data'],
            'labels' => collect(config('entitlements.keys'))->map(fn ($d) => $d[1]),
            'types' => collect(config('entitlements.keys'))->map(fn ($d) => $d[0]),
        ]);
    }
}
