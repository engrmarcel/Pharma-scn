<?php

namespace App\Domain\Analytics\Assistant;

use Illuminate\Support\Carbon;

/**
 * Local planner: keywords to reports, plain-English periods. Runs entirely on the
 * platform and is the default; an external model is used only when the platform is
 * configured for it and the organization has consented (AssistantService).
 */
class RuleBasedPlanner implements QuestionPlanner
{
    private const KEYWORDS = [
        'sales_summary' => ['sales', 'sold', 'revenue', 'invoice', 'turnover', 'income', 'customer', 'receivable', 'owe', 'overdue', 'debtor', 'top product', 'best sell', 'best-sell'],
        'purchases_summary' => ['purchase', 'supplier', 'procure', 'buying', 'spend', 'lead time', 'fill rate', 'po '],
        'inventory_summary' => ['stock', 'inventory', 'reorder', 'stockout', 'stock-out', 'running out', 'run out', 'slow mov', 'on hand', 'warehouse value'],
        'expiry_risk' => ['expir', 'shelf life', 'short-dated', 'short dated'],
        'pos_summary' => ['pos', 'till', 'cashier', 'counter', 'basket', 'walk-in', 'retail sale', 'refund'],
        'marketplace_summary' => ['marketplace', 'b2b', 'buyer'],
        'integration_health' => ['integration', 'connector', 'sap', 'erp', 'webhook', 'quarantine', 'interface'],
        'sync_status' => ['sync', 'offline', 'device'],
        'recall_status' => ['recall', 'incident', 'nafdac report'],
        'field_force_summary' => ['visit', 'field', 'rep ', 'reps', 'representative', 'territory', 'detailing'],
        'demand_forecast' => ['forecast', 'predict', 'demand', 'how many will', 'expect to sell', 'next week', 'next month'],
        'open_recommendations' => ['recommend', 'suggest', 'should i', 'should we', 'anomal', 'what needs attention', 'action items'],
    ];

    /** Requests to change something: the assistant reports only and says so. */
    private const ACTIONS = ['approve', 'create', 'delete', 'cancel', 'place an order', 'place order', 'order more', 'buy ', 'pay ', 'post ', 'adjust', 'write off', 'write-off',
        'release', 'quarantine the', 'transfer', 'send ', 'update ', 'change ', 'reject', 'issue a', 'refund the'];

    public function name(): string
    {
        return 'rules-v1';
    }

    public function plan(string $question, string $today): array
    {
        $q = ' '.mb_strtolower($question).' ';
        [$from, $to] = PeriodParser::parse($question, Carbon::parse($today)) ?? [null, null];

        $tools = [];
        foreach (self::KEYWORDS as $tool => $words) {
            foreach ($words as $word) {
                if (str_contains($q, $word)) {
                    $tools[] = $tool;
                    break;
                }
            }
        }
        // A forecast question about stock is answered by the forecast, not the stock report alone.
        if (in_array('demand_forecast', $tools, true) && ! preg_match('/\b(stock|inventory)\b/', $q)) {
            $tools = array_values(array_diff($tools, ['sales_summary']));
        }

        $declined = [];
        foreach (self::ACTIONS as $verb) {
            if (preg_match('/(^|[\s,.;])'.preg_quote(trim($verb), '/').'\b/', $q)) {
                $declined[] = trim($verb);
            }
        }

        if ($tools === [] && $declined === []) {
            $tools = ['sales_summary', 'inventory_summary', 'expiry_risk'];
        }

        return [
            'steps' => array_map(fn ($tool) => ['tool' => $tool, 'from' => $from, 'to' => $to, 'product_query' => $tool === 'demand_forecast' ? $question : null], array_slice($tools, 0, 5)),
            'declined_actions' => array_values(array_unique($declined)),
        ];
    }
}
