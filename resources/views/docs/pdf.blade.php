<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $brand['display_name'] }} documentation</title>
<style>
    @page { margin: 22mm 16mm 20mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.6pt; line-height: 1.5; color: #1e293b; }
    header.running { position: fixed; top: -14mm; left: 0; right: 0; font-size: 7.5pt; color: #64748b; border-bottom: 0.6pt solid #e2e8f0; padding-bottom: 2mm; }
    footer.running { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #64748b; border-top: 0.6pt solid #e2e8f0; padding-top: 2mm; }
    footer.running .page:after { content: counter(page); }
    .cover { page-break-after: always; padding-top: 28mm; }
    .cover .brand { font-size: 11pt; letter-spacing: 2pt; text-transform: uppercase; color: #b67614; font-weight: bold; }
    .cover h1 { font-size: 30pt; color: #0b1f42; margin: 6mm 0 3mm; line-height: 1.15; }
    .cover .sub { font-size: 12pt; color: #475569; }
    .cover table { margin-top: 12mm; width: 100%; border-collapse: collapse; }
    .cover td { padding: 2.2mm 2mm; border-bottom: 0.6pt solid #e2e8f0; vertical-align: top; font-size: 9pt; }
    .cover td.n { width: 8mm; color: #b67614; font-weight: bold; }
    .cover .meta { margin-top: 14mm; font-size: 8pt; color: #64748b; }
    .doc { page-break-before: always; }
    .doc.first { page-break-before: auto; }
    .doc-head { border-bottom: 2pt solid #d4951c; padding-bottom: 3mm; margin-bottom: 5mm; }
    .doc-head .eyebrow { font-size: 7.5pt; letter-spacing: 1.5pt; text-transform: uppercase; color: #b67614; font-weight: bold; }
    .doc-head h1 { font-size: 20pt; color: #0b1f42; margin: 1mm 0; }
    .doc-head .audience { font-size: 8.5pt; color: #475569; }
    .toc { background: #f8fafc; border: 0.6pt solid #e2e8f0; padding: 3mm 5mm; margin-bottom: 6mm; font-size: 8.5pt; }
    .toc strong { color: #0b1f42; }
    .toc ul { margin: 1.5mm 0 0 0; padding-left: 0; list-style: none; }
    h2 { font-size: 13pt; color: #0b1f42; margin: 7mm 0 2.5mm; padding-bottom: 1mm; border-bottom: 0.6pt solid #e2e8f0; page-break-after: avoid; }
    h3 { font-size: 10.5pt; color: #1f4b8f; margin: 5mm 0 2mm; page-break-after: avoid; }
    p { margin: 0 0 2.5mm; }
    ul, ol { margin: 0 0 3mm; padding-left: 6mm; }
    li { margin-bottom: 1mm; }
    a { color: #1f4b8f; text-decoration: none; }
    code { font-family: 'DejaVu Sans Mono', monospace; font-size: 8pt; background: #f1f5f9; padding: 0 1mm; color: #0b1f42; }
    pre { background: #0b1f42; color: #e2e8f0; padding: 3mm 4mm; font-size: 7.4pt; line-height: 1.4; white-space: pre-wrap; word-wrap: break-word; border-radius: 2mm; margin: 0 0 4mm; }
    pre code { background: transparent; color: #e2e8f0; padding: 0; font-size: 7.4pt; }
    table { width: 100%; border-collapse: collapse; margin: 0 0 4mm; font-size: 8.3pt; }
    th { background: #0b1f42; color: #ffffff; text-align: left; padding: 1.8mm 2mm; font-weight: bold; }
    td { padding: 1.6mm 2mm; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; }
    tr:nth-child(even) td { background: #f8fafc; }
    .doc-diagram { margin: 3mm 0 5mm; text-align: center; page-break-inside: avoid; }
    .doc-diagram img { width: 100%; }
    .doc-diagram figcaption { font-size: 7.5pt; color: #64748b; margin-top: 1mm; }
    blockquote { border-left: 2pt solid #d4951c; margin: 0 0 3mm; padding: 1mm 4mm; color: #475569; }
</style>
</head>
<body>
<header class="running">{{ $brand['display_name'] }} · Platform documentation</header>
<footer class="running">Generated {{ $generatedAt->format('j F Y') }} · Page <span class="page"></span></footer>

@if ($combined)
    <section class="cover">
        <div class="brand">{{ $brand['display_name'] }}</div>
        <h1>Platform documentation</h1>
        <div class="sub">Setup, administration, user guide, application flows, API and external integration</div>
        <table>
            @foreach ($docs as $i => $d)
                <tr><td class="n">{{ $i + 1 }}</td><td><strong>{{ $d['title'] }}</strong><br>{{ $d['summary'] }}</td><td style="width: 45mm; color: #64748b">{{ $d['audience'] }}</td></tr>
            @endforeach
        </table>
        <div class="meta">Generated {{ $generatedAt->format('j F Y, H:i') }} UTC from the documentation in the platform's repository (docs/manual).</div>
    </section>
@endif

@foreach ($docs as $i => $d)
    <section class="doc{{ $i === 0 ? ' first' : '' }}">
        <div class="doc-head">
            <div class="eyebrow">{{ $combined ? 'Part '.($i + 1).' of '.count($docs) : $brand['display_name'] }}</div>
            <h1>{{ $d['title'] }}</h1>
            <div class="audience">For: {{ $d['audience'] }}</div>
        </div>
        @if (count($d['toc']) > 2)
            <div class="toc"><strong>Contents</strong>
                <ul>@foreach ($d['toc'] as $t)<li>{{ $t['text'] }}</li>@endforeach</ul>
            </div>
        @endif
        {!! $d['html'] !!}
    </section>
@endforeach
</body>
</html>
