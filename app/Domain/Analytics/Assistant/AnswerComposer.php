<?php

namespace App\Domain\Analytics\Assistant;

/**
 * Writes the answer from the report results, on the platform. Figures are quoted from the
 * reports exactly; nothing is invented, and no business data is sent anywhere to phrase it.
 */
class AnswerComposer
{
    /** @param list<array{tool: string, from: string, to: string, period_label: string, result: array}> $steps */
    public function compose(array $steps, array $declined): array
    {
        $paragraphs = [];
        $caveats = [];
        foreach ($steps as $s) {
            $r = $s['result'];
            if ($r['status'] !== 'ok') {
                $paragraphs[] = $this->title($s['tool']).': not shown. '.($r['message'] ?? '');

                continue;
            }
            [$text, $notes] = $this->describe($s['tool'], $r['data'], $s['period_label']);
            $paragraphs[] = $text;
            array_push($caveats, ...$notes);
        }
        if ($declined !== []) {
            array_unshift($paragraphs, 'I can only report figures; I cannot '.implode(', ', $declined).' anything. Use the relevant screen or API, where your approval rules apply.');
        }

        return ['text' => implode("\n\n", array_filter($paragraphs)), 'caveats' => array_values(array_unique($caveats))];
    }

    private function describe(string $tool, array $d, string $period): array
    {
        return match ($tool) {
            'sales_summary' => [sprintf('Sales %s: %d invoices, revenue %s%s. %s Receivables outstanding %s, of which overdue %s (%d customers).',
                $period, $d['invoices'], $this->money($d['revenue']), $d['credited'] && $this->money($d['credited']) !== 'none' ? ', credited '.$this->money($d['credited']) : '',
                $this->first($d['top_products'], fn ($p) => "Top product: {$p['product_name']} ({$this->num($p['quantity'])} units, {$p['currency_code']} {$this->num($p['amount'], 2)}).", ''),
                $this->money($d['customers']['receivables']), $this->money($d['customers']['overdue']), $d['customers']['customers_overdue']), []],
            'purchases_summary' => [sprintf('Purchases %s: committed spend %s across %d orders. %s', $period, $this->money($d['committed_spend']), collect($d['orders_by_status'])->sum(),
                $this->first($d['suppliers'], fn ($s) => "Most-used supplier: {$s['name']} ({$s['orders']} orders, fill rate ".$this->pct($s['fill_rate_percent']).', on time '.$this->pct($s['on_time_percent']).').', '')), []],
            'inventory_summary' => [sprintf('Stock: %d products in stock%s; %d at or below reorder level; %d at risk of running out within %d days%s; %d with stock but no sales in %d days.',
                $d['products_in_stock'], empty($d['stock_value']['withheld']) ? ', valued at '.$this->money($d['stock_value']['by_currency']) : '',
                $d['below_reorder_level'], count($d['stockout_risk']), config('analytics.cover_warning_days'),
                $this->first($d['stockout_risk'], fn ($p) => " (shortest cover: {$p['product_name']}, {$p['days_of_cover']} days)", ''),
                count($d['slow_moving']), config('analytics.demand_window_days')),
                ['Days of cover are estimates from the average daily demand of the last '.config('analytics.demand_window_days').' days.']],
            'expiry_risk' => [sprintf('Expiry: %s. %d batches are predicted to expire unsold%s.',
                collect($d['buckets'])->map(fn ($b) => str_replace(['_days', '-'], [' days', '–'], $b['bucket']).": {$this->num($b['quantity'])} units")->implode('; ') ?: 'nothing expires within 180 days',
                $d['at_risk_batches'], $this->first($d['at_risk'], fn ($l) => ", soonest {$l['product_name']} lot {$l['batch_number']} ({$this->num($l['estimated_unsold'])} units by {$l['expiry_date']})", '')),
                ['Expiry risk is a prediction from recent demand, earliest expiry first.']],
            'pos_summary' => [sprintf('Point of sale %s: %d sales, gross %s, average basket %s, %d refunds (%s); %s captured offline.', $period, $d['sales'], $this->money($d['gross']),
                $this->money($d['average_basket']), $d['refunds']['count'], $this->money($d['refunds']['amount']), $this->pct($d['offline_percent'])), []],
            'marketplace_summary' => [sprintf('Marketplace %s: as buyer %d orders worth %s; as supplier %d orders worth %s, fees %s.', $period,
                collect($d['as_buyer']['orders_by_status'])->sum(), $this->money($d['as_buyer']['value']), collect($d['as_supplier']['orders_by_status'])->sum(),
                $this->money($d['as_supplier']['value']), $this->money($d['as_supplier']['fees'])), []],
            'integration_health' => [sprintf('Integrations %s: %s. Messages: %s. Webhook success %s.', $period,
                collect($d['connectors'])->map(fn ($c) => "{$c['name']} {$c['health']}")->implode(', ') ?: 'no connectors',
                collect($d['messages_by_status'])->map(fn ($n, $s) => "{$n} {$s}")->implode(', ') ?: 'none', $this->pct($d['webhooks']['success_percent'])), []],
            'sync_status' => [sprintf('Offline sync %s: %s; open issues %d; %d active devices, %d not synced for 7 days.', $period,
                collect($d['transactions_by_status'])->map(fn ($n, $s) => "{$n} {$s}")->implode(', ') ?: 'no transactions',
                collect($d['open_issues'])->sum(), $d['devices']['active'], $d['devices']['not_synced_7_days']), []],
            'recall_status' => [sprintf('Recalls: %d notices pending, %d acknowledged and not completed; %d regulatory incidents open, %d overdue for reporting.',
                $d['notices_by_status']['pending'] ?? 0, $d['notices_by_status']['acknowledged'] ?? 0, $d['incidents']['open'], $d['incidents']['overdue']), []],
            'field_force_summary' => [sprintf('Field force %s: %d visits, %d completed, %s verified check-ins; %d orders captured, %d accepted.', $period,
                $d['visits']['total'], $d['visits']['completed'], $this->pct($d['visits']['verified_percent']), $d['conversion']['orders_captured'], $d['conversion']['orders_accepted']), []],
            'demand_forecast' => [sprintf('Forecast for %s: about %s units a week over the next %d weeks (%s to %s at 80%%), method %s%s.',
                $d['product_name'], $this->num(collect($d['forecast'])->avg('value'), 1), count($d['forecast']), $this->num(collect($d['forecast'])->min('low'), 1),
                $this->num(collect($d['forecast'])->max('high'), 1), str_replace('_', ' ', $d['method']),
                $d['accuracy']['wape_percent'] !== null ? ", backtest error {$d['accuracy']['wape_percent']}%" : ''),
                array_merge(['The forecast is a prediction from recorded sales.'], array_slice($d['assumptions'], 0, 2))],
            'open_recommendations' => [$d['items'] === [] ? 'No open recommendations.' : sprintf('%d open recommendations: %s.', count($d['items']),
                collect($d['items'])->take(3)->map(fn ($r) => "{$r['title']} ({$r['severity']})")->implode('; ')),
                ['Recommendations are machine-generated and change nothing until a person accepts them.']],
        };
    }

    private function title(string $tool): string
    {
        return ucfirst(str_replace('_', ' ', $tool));
    }

    private function money(array $money): string
    {
        return $money === [] ? 'none' : collect($money)->map(fn ($m) => $m['currency_code'].' '.$this->num($m['amount'], 2))->implode(' + ');
    }

    private function num($value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals);
    }

    private function pct(?float $value): string
    {
        return $value === null ? 'n/a' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    }

    private function first($items, callable $format, string $none): string
    {
        $first = collect($items)->first();

        return $first ? $format($first) : $none;
    }
}
