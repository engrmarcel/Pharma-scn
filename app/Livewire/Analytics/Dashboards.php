<?php

namespace App\Livewire\Analytics;

use App\Livewire\WebPage;
use App\Support\Web\ApiError;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Area dashboards (sales, purchases, POS, marketplace, inventory, expiry risk, integrations, sync, recalls) within the member's permissions and locations. */
#[Title('Analytics dashboards')]
class Dashboards extends WebPage
{
    public const ROUTE = 'analytics.dashboards';

    public const BOARDS = ['sales' => 'Sales', 'purchases' => 'Purchases', 'inventory' => 'Inventory', 'expiry-risk' => 'Expiry risk', 'pos' => 'Point of sale',
        'marketplace' => 'Marketplace', 'integrations' => 'Integrations', 'sync' => 'Offline sync', 'recalls' => 'Recalls'];

    #[Url]
    public string $board = 'sales';

    public string $from = '';

    public string $to = '';

    public string $warehouse = '';

    public function mount(): void
    {
        $this->from = business_today()->subDays(29)->toDateString();
        $this->to = business_today()->toDateString();
    }

    public function render()
    {
        $board = array_key_exists($this->board, self::BOARDS) ? $this->board : 'sales';
        try {
            $data = $this->api()->get("analytics/{$board}", ['from' => $this->from, 'to' => $this->to, 'warehouse_id' => $this->warehouse])['data'];
            $refused = null;
        } catch (ApiError $e) {
            $data = null;
            $refused = $e->getMessage();
        }

        return view('livewire.analytics.dashboards', ['data' => $data, 'refused' => $refused, 'boards' => self::BOARDS, 'warehouses' => $this->warehouseOptions()]);
    }
}
