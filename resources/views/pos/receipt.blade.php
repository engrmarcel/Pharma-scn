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
    @if ($r['header']['tax_identification_number'])<div class="c muted">{{ $r['display']['tax_identification_label'] }}: {{ $r['header']['tax_identification_number'] }}</div>@endif
    <hr>
    <div>Sale {{ $r['sale_number'] }} · Invoice {{ $r['invoice_number'] }}</div>
    <div class="muted">{{ $r['display']['completed_at'] }} · {{ $r['terminal'] }} · {{ $r['cashier'] }}</div>
    <hr>
    <table>
        @foreach ($r['lines'] as $li => $l)
            <tr><td colspan="2">{{ $l['product'] }}</td></tr>
            <tr><td class="muted">{{ rtrim(rtrim($l['quantity'], '0'), '.') }} {{ $l['package_level'] }} × {{ $r['display']['lines'][$li]['unit_price'] }}@if ((float) $l['discount'] > 0) (−{{ $r['display']['lines'][$li]['discount'] }})@endif</td><td class="r">{{ $r['display']['lines'][$li]['total'] }}</td></tr>
            @foreach ($l['batches'] as $b)
                <tr><td class="muted" colspan="2">&nbsp;Batch {{ $b['batch_number'] }}@if ($b['expiry_date']) exp {{ $b['expiry_date'] }}@endif</td></tr>
            @endforeach
            @if ($l['dispensed_against_prescription'])<tr><td class="muted" colspan="2">&nbsp;Dispensed against prescription</td></tr>@endif
        @endforeach
    </table>
    <hr>
    <table>
        <tr><td>Subtotal</td><td class="r">{{ $r['display']['money']['subtotal'] }}</td></tr>
        <tr><td>Discount</td><td class="r">{{ $r['display']['money']['discount'] }}</td></tr>
        <tr><td>Tax</td><td class="r">{{ $r['display']['money']['tax'] }}</td></tr>
        <tr><td><strong>Total</strong></td><td class="r"><strong>{{ $r['display']['money']['total'] }}</strong></td></tr>
        @foreach ($r['payments'] as $pi => $p)
            <tr><td>Paid ({{ str_replace('_', ' ', $p['method']) }})</td><td class="r">{{ $r['display']['payments'][$pi] }}</td></tr>
        @endforeach
        <tr><td>Change</td><td class="r">{{ $r['display']['money']['change'] }}</td></tr>
    </table>
    <hr>
    <div class="c muted">{{ $r['footer'] }}</div>
    <p class="c no-print"><button onclick="window.print()">Print</button></p>
</body>
</html>
