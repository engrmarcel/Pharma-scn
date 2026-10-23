<?php

namespace App\Livewire\Admin;

use App\Domain\Platform\Services\BrandingService;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Platform name, colours, logo, email sender and support contacts. */
#[Title('Branding')]
class Branding extends WebPage
{
    public const ROUTE = 'admin.branding';

    private const FIELDS = ['display_name', 'logo_url', 'favicon_url', 'primary_color', 'secondary_color', 'email_from_name', 'email_from_address', 'support_email', 'support_phone', 'website_url'];

    public array $brand = [];

    public function mount(BrandingService $branding): void
    {
        // The public GET hides the sender address, so the form starts from the service directly.
        $this->brand = collect($branding->platform())->only(self::FIELDS)->map(fn ($v) => $v ?? '')->all() + array_fill_keys(self::FIELDS, '');
    }

    public function save(): void
    {
        $payload = collect($this->brand)->only(self::FIELDS)->map(fn ($v) => $v === '' ? null : $v)
            ->reject(fn ($v, $k) => $v === null && ! in_array($k, ['logo_url', 'favicon_url', 'support_phone', 'website_url'], true))->all();
        $this->submit('brand', 'admin/branding', $payload, 'Branding saved.', 'put');
    }

    public function render()
    {
        return view('livewire.admin.branding');
    }
}
