<?php

namespace App\Livewire\Marketplace;

use App\Domain\Marketplace\Models\MarketplaceProfile;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** How the organization appears to buyers, and whether it is listed at all. */
#[Title('Marketplace profile')]
class Profile extends WebPage
{
    public const ROUTE = 'marketplace.profile';

    public array $form = [];

    public function mount(): void
    {
        $p = $this->api()->get('marketplace/profile')['data'] ?? [];
        $this->form = ['listed' => (bool) ($p['listed'] ?? false), 'headline' => $p['headline'] ?? '', 'description' => $p['description'] ?? '',
            'regions_served' => implode(', ', (array) ($p['regions_served'] ?? [])), 'delivery_options' => (array) ($p['delivery_options'] ?? []),
            'minimum_order_value' => (string) ($p['minimum_order_value'] ?? ''), 'contact_email' => $p['contact_email'] ?? '', 'contact_phone' => $p['contact_phone'] ?? ''];
    }

    public function save(): void
    {
        $f = $this->form;
        $this->submit('form', 'marketplace/profile', [
            'listed' => (bool) $f['listed'], 'headline' => $f['headline'] ?: null, 'description' => $f['description'] ?: null,
            'regions_served' => array_values(array_filter(array_map('trim', explode(',', $f['regions_served'])))) ?: null,
            'delivery_options' => array_values($f['delivery_options']) ?: null, 'minimum_order_value' => $f['minimum_order_value'] ?: null,
            'contact_email' => $f['contact_email'] ?: null, 'contact_phone' => $f['contact_phone'] ?: null,
        ], 'Marketplace profile saved.', 'put');
    }

    public function render()
    {
        return view('livewire.marketplace.profile', ['options' => MarketplaceProfile::DELIVERY_OPTIONS]);
    }
}
