<?php

namespace App\Livewire;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Inventory\Models\Batch;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\ApiError;
use App\Support\Web\InternalApi;
use App\Support\Web\Navigation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Base for the module screens (ADR-0024).
 *
 * - ROUTE names the page in the navigation registry; its permissions are checked on
 *   every request (first load and each Livewire update).
 * - Reads and writes go through the in-process API (`$this->api()`), so the screen
 *   carries no business rules of its own. Field errors land in the error bag;
 *   other refusals become an error toast with the API's message.
 */
#[Layout('layouts.app')]
abstract class WebPage extends Component
{
    /** Route name in Navigation::SECTIONS. */
    public const ROUTE = '';

    /** Properties that reset the list to page 1 when changed. */
    protected array $filters = [];

    /** Current page of the main list. */
    public int $page = 1;

    /** Per-form idempotency keys: a double-submitted form is applied once. */
    public array $formKeys = [];

    public function boot(): void
    {
        $abilities = Navigation::find(static::ROUTE)[1][3] ?? null;
        abort_if($abilities === null, 404);
        abort_unless($abilities === [] || Gate::any($abilities), 403);
        abort_if(in_array(static::ROUTE, Navigation::MARKETPLACE_SELLER_PAGES, true) && ! Navigation::sellsOnMarketplace(app(TenantContext::class)->organization()), 403,
            'Only suppliers sell on the marketplace.');
    }

    protected function api(): InternalApi
    {
        return app(InternalApi::class);
    }

    /**
     * Run a write. Returns the API body, or null when it was refused (the toast says why).
     * Validation errors propagate to the error bag, prefixed with $form when given.
     */
    protected function attempt(callable $call, ?string $success = null): ?array
    {
        try {
            $result = $call($this->api());
        } catch (ApiError $e) {
            $this->notify($e->getMessage().($e->correlationId ? " (ref {$e->correlationId})" : ''), 'error');

            return null;
        }
        if ($success !== null) {
            $this->notify($success);
        }

        return is_array($result) ? $result : [];
    }

    /** Read that tolerates refusal (a section the member may not see renders empty). */
    protected function read(string $uri, array $query = [], array $default = []): array
    {
        try {
            return $this->api()->get($uri, $query);
        } catch (ApiError) {
            return $default;
        }
    }

    /** POST with the form's idempotency key; the key is renewed after success. */
    protected function submit(string $form, string $uri, array $data, ?string $success = null, string $method = 'post'): ?array
    {
        $this->resetErrorBag();
        $key = $this->formKeys[$form] ??= 'web-'.Str::ulid();
        $result = $this->attempt(fn (InternalApi $api) => $method === 'post'
            ? $api->post($uri, $data, $form, $key)
            : $api->send(strtoupper($method), $uri, $data, $form), $success);
        if ($result !== null) {
            unset($this->formKeys[$form]);
        }

        return $result;
    }

    protected function notify(string $message, string $type = 'success'): void
    {
        $this->dispatch('notify', type: $type, message: $message);
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /** Back to the first page whenever a filter changes. */
    public function updated(string $property): void
    {
        if (in_array($property, $this->filters, true)) {
            $this->page = 1;
        }
    }

    protected function can(string ...$abilities): bool
    {
        return Gate::any($abilities);
    }

    // ---- Lookups for display (names only; every decision stays with the API) ----------

    /** The member's warehouses: id => "CODE · Name". */
    protected function warehouseOptions(): array
    {
        return $this->memo('warehouses', fn () => collect($this->read('warehouses')['data'] ?? [])
            ->mapWithKeys(fn ($w) => [$w['id'] => $w['code'].' · '.$w['name']])->all());
    }

    /** Product display names from the shared product master. */
    protected function productNames(iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Product::query()->whereIn('id', $ids)->pluck('display_name', 'id')->all();
    }

    /** Batch labels ("NUMBER · exp date") for the organization's batches (tenant-scoped model). */
    protected function batchLabels(iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Batch::query()->whereIn('id', $ids)->get(['id', 'batch_number', 'expiry_date'])
            ->mapWithKeys(fn ($b) => [$b->id => $b->batch_number.($b->expiry_date ? ' · exp '.$b->expiry_date->format('Y-m-d') : '')])->all();
    }

    /** The organization's currency. */
    protected function currency(): string
    {
        return app(TenantContext::class)->organization()?->country?->currency_code ?? 'USD';
    }

    private array $memo = [];

    protected function memo(string $key, callable $resolve): mixed
    {
        return $this->memo[$key] ??= $resolve();
    }
}
