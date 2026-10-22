<?php

namespace App\Livewire\Parties;

use App\Domain\Catalogue\Models\Product;
use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use App\Support\Tenancy\TenantContext;
use Livewire\Attributes\Url;

/**
 * Customer and supplier accounts: list, create, edit, contacts, and the account's
 * statement (customers) or price list and delivery performance (suppliers).
 */
abstract class Directory extends WebPage
{
    use PicksProducts;

    /** customers | suppliers (API path and permission prefix). */
    protected const KIND = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public string $type = '';

    public ?array $party = null;

    /** Account open in the detail view. */
    public ?string $openId = null;

    public string $detailTab = 'overview';

    public array $contact = ['name' => '', 'job_title' => '', 'email' => '', 'phone' => '', 'is_primary' => false];

    /** Supplier price list being edited. */
    public ?array $priceLines = null;

    protected array $filters = ['search', 'status', 'type'];

    abstract protected function types(): array;

    abstract protected function extraFields(): array;

    public function create(): void
    {
        $this->resetErrorBag();
        $this->party = ['id' => null, 'code' => '', 'name' => '', 'type' => $this->types()[0], 'email' => '', 'phone' => '', 'tax_identification_number' => '',
            'address_line1' => '', 'city' => '', 'state_region' => '', 'country_code' => app(TenantContext::class)->organization()?->country_code ?? '',
            'payment_terms_days' => 30, 'credit_limit' => '', 'status' => 'active', 'notes' => ''] + array_fill_keys(array_keys($this->extraFields()), '');
    }

    public function edit(array $row): void
    {
        $this->resetErrorBag();
        $keys = ['code', 'name', 'type', 'email', 'phone', 'tax_identification_number', 'address_line1', 'city', 'state_region', 'country_code', 'payment_terms_days', 'credit_limit', 'status', 'notes', ...array_keys($this->extraFields())];
        $this->party = ['id' => $row['id']] + array_map(fn ($v) => $v ?? '', array_intersect_key($row + array_fill_keys($keys, ''), array_flip($keys)));
    }

    public function save(): void
    {
        $data = collect($this->party)->except('id')->map(fn ($v) => $v === '' ? null : $v)->all();
        $data = array_filter($data, fn ($v, $k) => $v !== null || in_array($k, ['email', 'phone', 'notes', 'credit_limit', 'address_line1', 'city', 'state_region'], true), ARRAY_FILTER_USE_BOTH);
        $kind = static::KIND;
        $result = $this->party['id']
            ? $this->submit('party', "{$kind}/{$this->party['id']}", $data, 'Account updated.', 'patch')
            : $this->submit('party', $kind, $data, 'Account created.');
        if ($result !== null) {
            $this->party = null;
            $this->openId ??= $result['data']['id'] ?? null;
        }
    }

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->detailTab = 'overview';
        $this->priceLines = null;
        $this->contact = ['name' => '', 'job_title' => '', 'email' => '', 'phone' => '', 'is_primary' => false];
    }

    public function addContact(): void
    {
        if ($this->submit('contact', static::KIND."/{$this->openId}/contacts", array_filter($this->contact, fn ($v) => $v !== ''), 'Contact added.') !== null) {
            $this->contact = ['name' => '', 'job_title' => '', 'email' => '', 'phone' => '', 'is_primary' => false];
        }
    }

    public function removeContact(string $contactId): void
    {
        $this->attempt(fn ($api) => $api->delete(static::KIND."/{$this->openId}/contacts/{$contactId}"), 'Contact removed.');
    }

    // ---- Supplier price list ---------------------------------------------------------

    public function editPriceList(array $items, array $names): void
    {
        $this->priceLines = collect($items)->map(fn ($i) => ['product_id' => $i['product_id'], 'name' => $names[$i['product_id']] ?? $i['product_id'],
            'levels' => $this->packageLevels($i['product_id']), 'package_level_code' => $i['package_level_code'], 'unit_price' => (string) $i['unit_price'],
            'minimum_quantity' => (string) $i['minimum_quantity'], 'valid_from' => $i['valid_from'] ? substr($i['valid_from'], 0, 10) : '', 'valid_to' => $i['valid_to'] ? substr($i['valid_to'], 0, 10) : ''])->all();
    }

    public function addPriceLine(): void
    {
        $this->priceLines[] = ['product_id' => '', 'name' => null, 'levels' => [], 'package_level_code' => '', 'unit_price' => '', 'minimum_quantity' => '1', 'valid_from' => '', 'valid_to' => ''];
    }

    public function removePriceLine(int $i): void
    {
        unset($this->priceLines[$i]);
        $this->priceLines = array_values($this->priceLines);
    }

    protected function productPicked(string $target, array $product): void
    {
        $i = (int) str_replace('price.', '', $target);
        $this->priceLines[$i] = array_merge($this->priceLines[$i], ['product_id' => $product['product_id'], 'name' => $product['name'], 'levels' => $product['levels'],
            'package_level_code' => array_key_first($product['levels']) ?? '', 'unit_price' => $this->priceLines[$i]['unit_price'] ?: (string) ($product['cost'] ?? '')]);
    }

    public function savePriceList(): void
    {
        $items = collect($this->priceLines)->map(fn ($l) => array_filter(collect($l)->only(['product_id', 'package_level_code', 'unit_price', 'minimum_quantity', 'valid_from', 'valid_to'])->all(), fn ($v) => $v !== ''))->values()->all();
        if ($this->submit('price', "suppliers/{$this->openId}/price-list", ['items' => $items], 'Price list saved.', 'put') !== null) {
            $this->priceLines = null;
        }
    }

    protected function detail(): ?array
    {
        if ($this->openId === null) {
            return null;
        }
        $party = $this->read(static::KIND."/{$this->openId}")['data'] ?? null;
        if ($party === null) {
            return null;
        }
        $extra = [];
        if (static::KIND === 'customers' && $this->detailTab === 'statement') {
            $extra['statement'] = $this->read("customers/{$this->openId}/statement", default: ['data' => null])['data'];
        }
        if (static::KIND === 'suppliers' && $this->detailTab === 'performance') {
            $extra['performance'] = $this->read("suppliers/{$this->openId}/performance", default: ['data' => null])['data'];
        }
        if (static::KIND === 'suppliers' && $this->detailTab === 'prices') {
            $items = $this->read("suppliers/{$this->openId}/price-list", default: ['data' => null])['data'];
            $extra['prices'] = $items;
            $extra['productNames'] = $items ? Product::query()->whereIn('id', collect($items)->pluck('product_id'))->pluck('display_name', 'id')->all() : [];
        }

        return $party + $extra;
    }

    public function render()
    {
        $result = $this->api()->get(static::KIND, ['q' => trim($this->search), 'status' => $this->status, 'type' => $this->type, 'page' => $this->page]);

        return view('livewire.parties.directory', [
            'kind' => static::KIND,
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'types' => $this->types(),
            'extraFields' => $this->extraFields(),
            'detail' => $this->detail(),
            'canManage' => $this->can(static::KIND.'.manage'),
            'canPriceList' => static::KIND === 'suppliers' && $this->can('procurement.order'),
            'singular' => static::KIND === 'customers' ? 'customer' : 'supplier',
        ]);
    }
}
