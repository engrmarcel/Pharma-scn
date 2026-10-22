<?php

namespace App\Livewire\Analytics;

use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use App\Support\Web\ApiError;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Weekly demand forecast for one product, with the history it is based on and the method's backtest accuracy. */
#[Title('Demand forecast')]
class Forecast extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'analytics.forecast';

    #[Url(as: 'product')]
    public string $productId = '';

    public ?string $productName = null;

    public string $warehouse = '';

    public int $weeks = 8;

    protected function productPicked(string $target, array $product): void
    {
        $this->productId = $product['product_id'];
        $this->productName = $product['name'];
    }

    public function render()
    {
        $result = null;
        $error = null;
        if ($this->productId !== '') {
            try {
                $result = $this->api()->get('analytics/forecast', ['product_id' => $this->productId, 'warehouse_id' => $this->warehouse, 'horizon_weeks' => $this->weeks])['data'];
            } catch (ApiError $e) {
                $error = $e->getMessage();
            }
            $this->productName ??= $this->productNames([$this->productId])[$this->productId] ?? null;
        }

        return view('livewire.analytics.forecast', ['result' => $result, 'error' => $error, 'warehouses' => $this->warehouseOptions()]);
    }
}
