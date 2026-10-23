<?php

namespace App\Livewire\Admin;

use App\Domain\Catalogue\Enums\ProductStatus;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Models\RegulatoryAuthority;
use App\Livewire\Concerns\EditsProducts;
use App\Livewire\WebPage;
use App\Support\Web\ApiError;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** The shared product master: create and correct products, record and verify regulatory identifiers, review organizations' proposals. */
#[Title('Product master administration')]
class Catalogue extends WebPage
{
    use EditsProducts;

    public const ROUTE = 'admin.catalogue';

    #[Url]
    public string $tab = 'products';

    #[Url]
    public string $search = '';

    public string $requestStatus = 'pending';

    public ?string $openId = null;

    /** Regulatory identifier being added to the open product, or verified. */
    public ?array $identifier = null;

    public ?array $verifying = null;

    public string $notes = '';

    protected array $filters = ['tab', 'search', 'requestStatus'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->productBase = null;
        $this->product = $this->blankProduct() + ['status' => 'active'];
    }

    public function edit(): void
    {
        $p = $this->api()->get("products/{$this->openId}")['data'];
        $this->resetErrorBag();
        $this->productBase = ['product_id' => $p['id'], 'version' => $p['version'], 'name' => $p['display_name']];
        $this->product = $this->productFromApi($p) + ['status' => $p['status']];
    }

    public function save(): void
    {
        $payload = $this->productPayload();
        if (isset($this->product['status'])) {
            $payload['status'] = $this->product['status'];
        }
        $result = $this->productBase
            ? $this->submit('product', "admin/products/{$this->productBase['product_id']}", $payload + ['version' => $this->productBase['version']], 'Product updated.', 'patch')
            : $this->submit('product', 'admin/products', $payload, 'Product created.');
        if ($result !== null) {
            $this->product = null;
            $this->openId = $result['data']['id'] ?? $this->openId;
        }
    }

    public function addIdentifier(): void
    {
        $this->resetErrorBag();
        $this->identifier = ['authority_code' => 'NAFDAC', 'identifier_type' => 'registration_number', 'value' => '', 'declared_status' => 'registered', 'expiry_date' => '', 'holder_name' => ''];
    }

    public function saveIdentifier(): void
    {
        if ($this->submit('identifier', "admin/products/{$this->openId}/regulatory-identifiers", array_filter($this->identifier), 'Identifier recorded.') !== null) {
            $this->identifier = null;
        }
    }

    public function startVerify(string $identifierId): void
    {
        $this->resetErrorBag();
        $this->verifying = ['id' => $identifierId, 'method' => 'official_portal_lookup', 'result' => 'verified', 'source' => '', 'notes' => ''];
    }

    public function verify(): void
    {
        $v = $this->verifying;
        if ($this->submit('verifying', "admin/regulatory-identifiers/{$v['id']}/verifications", array_filter(collect($v)->except('id')->all()), 'Verification recorded.') !== null) {
            $this->verifying = null;
        }
    }

    public function decide(string $requestId, string $decision): void
    {
        if ($this->submit('decision', "admin/product-change-requests/{$requestId}/{$decision}", array_filter(['notes' => $this->notes]), $decision === 'approve' ? 'Proposal approved and published.' : 'Proposal rejected.') !== null) {
            $this->notes = '';
        }
    }

    public function render()
    {
        $products = $this->tab === 'products' ? $this->api()->get('products', ['q' => trim($this->search), 'page' => $this->page, 'per_page' => 25]) : [];
        $requests = $this->tab === 'requests' ? $this->read('admin/product-change-requests', ['status' => $this->requestStatus, 'page' => $this->page]) : [];
        try {
            $detail = $this->openId ? $this->api()->get("products/{$this->openId}")['data'] : null;
        } catch (ApiError) {
            $detail = null;
        }

        return view('livewire.admin.catalogue', [
            'products' => $products['data'] ?? [],
            'requests' => $requests['data'] ?? [],
            'meta' => $this->tab === 'products' ? ($products['meta'] ?? []) : ($requests['meta'] ?? []),
            'detail' => $detail,
            'orgs' => Organization::query()->whereIn('id', collect($requests['data'] ?? [])->pluck('organization_id'))->pluck('name', 'id'),
            'reference' => $this->product ? $this->catalogueReference() : [],
            'authorities' => RegulatoryAuthority::query()->where('is_active', true)->orderBy('code')->pluck('name', 'code'),
            'statuses' => array_map(fn ($c) => $c->value, ProductStatus::cases()),
            'can' => ['manage' => $this->can('catalogue.manage'), 'review' => $this->can('catalogue.review'), 'verify' => $this->can('regulatory.verify')],
        ]);
    }
}
