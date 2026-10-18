<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $r['sale_number'] }}</title>
    <style>
        body { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; width: 72mm; margin: 0 auto; padding: 8px; color: #000; background: #fff; }
        h1 { font-size: 14px; text-align: center; margin: 0 0 4px; }
        .c { text-align: center; } .r { text-align: right; } .muted { color: #444; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; } td { vertical-align: top; padding: 1px 0; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <h1>{{ $r['header']['organization'] }}</h1>
    <div class="c muted">{{ $r['header']['address'] }}@if ($r['header']['phone']) · {{ $r['header']['phone'] }}@endif</div>
    @if ($r['header']['tax_identification_number'])<div class="c muted">TIN {{ $r['header']['tax_identification_number'] }}</div>@endif
    <hr>
    <div>Sale {{ $r['sale_number'] }} · Invoice {{ $r['invoice_number'] }}</div>
    <div class="muted">{{ \Illuminate\Support\Carbon::parse($r['completed_at'])->format('Y-m-d H:i') }} · {{ $r['terminal'] }} · {{ $r['cashier'] }}</div>
    <hr>
    <table>
        @foreach ($r['lines'] as $l)
            <tr><td colspan="2">{{ $l['product'] }}</td></tr>
            <tr><td class="muted">{{ rtrim(rtrim($l['quantity'], '0'), '.') }} {{ $l['package_level'] }} × {{ $l['unit_price'] }}@if ((float) $l['discount'] > 0) (−{{ $l['discount'] }})@endif</td><td class="r">{{ $l['total'] }}</td></tr>
            @foreach ($l['batches'] as $b)
                <tr><td class="muted" colspan="2">&nbsp;Batch {{ $b['batch_number'] }}@if ($b['expiry_date']) exp {{ $b['expiry_date'] }}@endif</td></tr>
            @endforeach
            @if ($l['dispensed_against_prescription'])<tr><td class="muted" colspan="2">&nbsp;Dispensed against prescription</td></tr>@endif
        @endforeach
    </table>
    <hr>
    <table>
        <tr><td>Subtotal</td><td class="r">{{ $r['totals']['subtotal'] }}</td></tr>
        <tr><td>Discount</td><td class="r">{{ $r['totals']['discount'] }}</td></tr>
        <tr><td>Tax</td><td class="r">{{ $r['totals']['tax'] }}</td></tr>
        <tr><td><strong>Total {{ $r['currency_code'] }}</strong></td><td class="r"><strong>{{ $r['totals']['total'] }}</strong></td></tr>
        @foreach ($r['payments'] as $p)
            <tr><td>Paid ({{ str_replace('_', ' ', $p['method']) }})</td><td class="r">{{ $p['tendered'] ?? $p['amount'] }}</td></tr>
        @endforeach
        <tr><td>Change</td><td class="r">{{ $r['totals']['change'] }}</td></tr>
    </table>
    <hr>
    <div class="c muted">{{ $r['footer'] }}</div>
    <p class="c no-print"><button onclick="window.print()">Print</button></p>
</body>
</html>
