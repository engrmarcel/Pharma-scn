<?php

namespace App\Livewire\Admin;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\VerificationStatus;
use App\Livewire\WebPage;
use App\Support\Web\ApiError;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Every organization on the platform: details, verification history and documents, modules, subscription and suspension. */
#[Title('Organizations')]
class Organizations extends WebPage
{
    public const ROUTE = 'admin.organizations';

    #[Url]
    public string $search = '';

    public string $verification = '';

    public string $status = '';

    public string $type = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    public string $tab = 'profile';

    public array $modules = [];

    public array $subscription = ['plan_code' => '', 'status' => ''];

    public array $override = ['key' => '', 'enabled' => true, 'limit' => '', 'reason' => ''];

    public string $reason = '';

    protected array $filters = ['search', 'verification', 'status', 'type'];

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->tab = 'profile';
        $this->reason = '';
        $this->modules = [];
    }

    public function saveModules(): void
    {
        $this->attempt(fn ($api) => $api->put("admin/organizations/{$this->openId}/modules", ['modules' => array_map(fn ($v) => (bool) $v, $this->modules)]), 'Modules updated.');
    }

    public function saveSubscription(): void
    {
        $this->submit('subscription', "admin/organizations/{$this->openId}/subscription", array_filter($this->subscription), 'Subscription changed.', 'put');
    }

    public function saveOverride(): void
    {
        $o = $this->override;
        if ($this->submit('override', "admin/organizations/{$this->openId}/entitlement-overrides", ['key' => $o['key'], 'enabled' => (bool) $o['enabled'],
            'limit' => $o['limit'] === '' ? null : (int) $o['limit'], 'reason' => $o['reason']], 'Override saved.', 'put') !== null) {
            $this->override = ['key' => '', 'enabled' => true, 'limit' => '', 'reason' => ''];
        }
    }

    public function suspend(): void
    {
        $this->submit('suspend', "admin/organizations/{$this->openId}/suspend", ['reason' => $this->reason], 'Organization suspended: its members cannot work in it until reactivated.');
    }

    public function reactivate(): void
    {
        $this->submit('suspend', "admin/organizations/{$this->openId}/reactivate", array_filter(['reason' => $this->reason]), 'Organization reactivated.');
    }

    public function download(string $attachmentId, string $name)
    {
        try {
            $content = $this->api()->download("admin/organizations/{$this->openId}/documents/{$attachmentId}");
        } catch (ApiError $e) {
            $this->notify($e->getMessage(), 'error');

            return null;
        }

        return response()->streamDownload(fn () => print ($content), $name);
    }

    public function render()
    {
        $result = $this->api()->get('admin/organizations', ['q' => trim($this->search), 'verification_status' => $this->verification, 'status' => $this->status, 'type' => $this->type, 'page' => $this->page]);
        $detail = $this->openId ? $this->read("admin/organizations/{$this->openId}", default: []) : [];
        if ($detail && $this->modules === []) {
            $this->modules = collect(config('platform.modules'))->mapWithKeys(fn ($l, $m) => [$m => in_array($m, $detail['data']['modules'] ?? [], true)])->all();
        }
        $sub = $detail && $this->tab === 'subscription' ? ($this->read("admin/organizations/{$this->openId}/subscription")['data'] ?? null) : null;
        if ($sub && $this->subscription['plan_code'] === '') {
            $this->subscription = ['plan_code' => $sub['plan']['code'] ?? '', 'status' => $sub['status'] ?? ''];
        }

        return view('livewire.admin.organizations', [
            'rows' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? [],
            'detail' => $detail,
            'sub' => $sub,
            'plans' => $this->tab === 'subscription' ? collect($this->read('admin/subscription-plans')['data'] ?? [])->where('is_active', true)->pluck('name', 'code') : collect(),
            'statuses' => VerificationStatus::cases(),
            'types' => OrganizationType::cases(),
            'moduleNames' => config('platform.modules'),
            'entitlementKeys' => config('entitlements.keys'),
            'can' => ['modules' => $this->can('platform.organizations.modules'), 'suspend' => $this->can('platform.organizations.suspend'),
                'billing' => $this->can('platform.billing.manage'), 'verify' => $this->can('platform.organizations.verify')],
        ]);
    }
}
