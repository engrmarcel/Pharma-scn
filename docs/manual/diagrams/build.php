<?php

/*
 * Builds the flow diagrams of the user documentation (docs/manual/diagrams/*.svg).
 *
 *   php docs/manual/diagrams/build.php
 *
 * Each diagram is a grid of boxes [col, row, "Title\nsubtitle", style] joined by arrows
 * [from, to, label?, 'dashed'?, via?]. "via" lists grid points the arrow passes through;
 * fractional rows or columns (e.g. 0.5) run through the gaps between boxes. The SVGs use
 * basic shapes only, so they render the same in browsers and in the PDF (dompdf).
 */

const CELL_W = 190, CELL_H = 92, BOX_W = 160, BOX_H = 58, PAD = 24;

const STYLES = [
    'actor' => ['fill' => '#0b1f42', 'stroke' => '#0b1f42', 'text' => '#ffffff'],
    'step' => ['fill' => '#ffffff', 'stroke' => '#1f4b8f', 'text' => '#0b1f42'],
    'gate' => ['fill' => '#fdf8ec', 'stroke' => '#d4951c', 'text' => '#5c3a0a'],
    'store' => ['fill' => '#e8f5ee', 'stroke' => '#059669', 'text' => '#064e3b'],
    'external' => ['fill' => '#f1f5f9', 'stroke' => '#64748b', 'text' => '#1e293b'],
    'alert' => ['fill' => '#fff1f2', 'stroke' => '#e11d48', 'text' => '#881337'],
];

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES);
}

function diagram(string $file, string $caption, array $nodes, array $edges, array $legend): void
{
    $cols = max(array_map(fn ($n) => $n[0], $nodes)) + 1;
    $rows = max(array_map(fn ($n) => $n[1], $nodes)) + 1;
    $w = $cols * CELL_W + 2 * PAD;
    $legendH = 34;
    $h = $rows * CELL_H + 2 * PAD + $legendH;
    $grid = fn (array $g) => [PAD + $g[0] * CELL_W + CELL_W / 2, PAD + $g[1] * CELL_H + CELL_H / 2];
    // Leave or enter a box through the side facing the given point.
    $side = function (array $c, array $toward) {
        $dx = $toward[0] - $c[0];
        $dy = $toward[1] - $c[1];

        return abs($dy) < 0.5 ? [$c[0] + ($dx <=> 0) * BOX_W / 2, $c[1]] : [$c[0], $c[1] + ($dy <=> 0) * BOX_H / 2];
    };

    $out = [sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" font-family="Helvetica, Arial, sans-serif">', $w, $h, $w, $h),
        '<title>'.esc($caption).'</title>', sprintf('<rect x="0" y="0" width="%d" height="%d" fill="#ffffff"/>', $w, $h)];

    foreach ($edges as $e) {
        [$from, $to] = $e;
        $label = $e[2] ?? null;
        $dashed = ($e[3] ?? null) === 'dashed';
        $a = $grid($nodes[$from]);
        $b = $grid($nodes[$to]);
        $via = array_map($grid, $e[4] ?? []);
        if ($via === [] && $a[0] !== $b[0] && $a[1] !== $b[1]) {
            $via = [[$a[0], $b[1]]];
        }
        $points = [$side($a, $via[0] ?? $b), ...$via, $side($b, $via === [] ? $a : $via[count($via) - 1])];
        $color = $dashed ? '#94a3b8' : '#d4951c';
        $out[] = sprintf('<path d="M%s" fill="none" stroke="%s" stroke-width="1.8"%s/>', implode(' L', array_map(fn ($p) => round($p[0], 1).','.round($p[1], 1), $points)),
            $color, $dashed ? ' stroke-dasharray="5,4"' : '');
        [$ax, $ay] = $points[count($points) - 1];
        [$bx, $by] = $points[count($points) - 2];
        $ang = atan2($ay - $by, $ax - $bx);
        $out[] = sprintf('<polygon points="%s,%s %s,%s %s,%s" fill="%s"/>', round($ax, 1), round($ay, 1), round($ax - 10 * cos($ang - 0.45), 1), round($ay - 10 * sin($ang - 0.45), 1),
            round($ax - 10 * cos($ang + 0.45), 1), round($ay - 10 * sin($ang + 0.45), 1), $color);
        if ($label) {
            $best = 0;
            $len = -1;
            for ($i = 0; $i < count($points) - 1; $i++) {
                $l = hypot($points[$i + 1][0] - $points[$i][0], $points[$i + 1][1] - $points[$i][1]);
                if ($l > $len) {
                    [$len, $best] = [$l, $i];
                }
            }
            $lx = ($points[$best][0] + $points[$best + 1][0]) / 2;
            $ly = ($points[$best][1] + $points[$best + 1][1]) / 2 + 4;
            $tw = strlen($label) * 5.4 + 10;
            $out[] = sprintf('<rect x="%s" y="%s" width="%s" height="15" rx="3" fill="#ffffff" stroke="#e2e8f0"/>', round($lx - $tw / 2, 1), round($ly - 11, 1), round($tw, 1));
            $out[] = sprintf('<text x="%s" y="%s" font-size="9.5" fill="#475569" text-anchor="middle">%s</text>', round($lx, 1), round($ly, 1), esc($label));
        }
    }

    foreach ($nodes as $n) {
        [$cx, $cy] = $grid($n);
        $s = STYLES[$n[3] ?? 'step'];
        $out[] = sprintf('<rect x="%s" y="%s" width="%d" height="%d" rx="10" fill="%s" stroke="%s" stroke-width="1.5"/>', $cx - BOX_W / 2, $cy - BOX_H / 2, BOX_W, BOX_H, $s['fill'], $s['stroke']);
        $lines = explode("\n", $n[2]);
        $start = $cy - (count($lines) - 1) * 7 + 4;
        foreach ($lines as $i => $line) {
            $out[] = sprintf('<text x="%s" y="%s" font-size="%s" font-weight="%s" fill="%s" text-anchor="middle">%s</text>', $cx, $start + $i * 14,
                $i === 0 ? 11.5 : 9.5, $i === 0 ? 'bold' : 'normal', $s['text'], esc($line));
        }
    }

    $x = PAD;
    $y = $h - $legendH + 8;
    foreach ($legend as $style => $text) {
        $s = STYLES[$style];
        $out[] = sprintf('<rect x="%s" y="%s" width="14" height="14" rx="3" fill="%s" stroke="%s"/>', $x, $y, $s['fill'], $s['stroke']);
        $out[] = sprintf('<text x="%s" y="%s" font-size="10" fill="#334155">%s</text>', $x + 20, $y + 11, esc($text));
        $x += 34 + strlen($text) * 5.6;
    }

    $out[] = '</svg>';
    file_put_contents(__DIR__."/{$file}.svg", implode("\n", $out)."\n");
    echo "{$file}.svg\n";
}

$legend = ['actor' => 'Person or system', 'step' => 'Platform step', 'gate' => 'Check or approval', 'store' => 'Record kept', 'external' => 'Outside the platform', 'alert' => 'Exception'];

diagram('01-system-architecture', 'System architecture', [
    'web' => [0, 0, "Web app\nstaff and managers", 'actor'],
    'mobile' => [1, 0, "Field force app\noffline-first", 'actor'],
    'pos' => [2, 0, "POS terminals\noffline-capable", 'actor'],
    'erp' => [3, 0, "Customer ERP\nSAP, Oracle, files", 'external'],
    'public' => [4, 0, "Public QR scan\npatients", 'external'],
    'api' => [1, 1, "Laravel application\nweb + REST /api/v1", 'step'],
    'gw' => [3, 1, "Integration gateway\nconnector API, inbox", 'step'],
    'mw' => [0, 2, "Security layers\nauth, tenant, role", 'gate'],
    'domain' => [2, 2, "Domain services\nbusiness modules", 'step'],
    'workers' => [4, 2, "Queue workers\nscheduler", 'step'],
    'db' => [1, 3, "MySQL\nper-tenant rows", 'store'],
    'redis' => [2, 3, "Redis\ncache, queues", 'store'],
    'files' => [3, 3, "Object storage\ndocuments, media", 'store'],
    'hooks' => [4, 3, "Webhooks out\nsigned, retried", 'external'],
], [
    ['web', 'api', null, null, [[0, 0.5], [1, 0.5]]], ['mobile', 'api'], ['pos', 'api', null, null, [[2, 0.5], [1, 0.5]]],
    ['erp', 'gw'], ['public', 'api', 'scan /t/{token}', 'dashed', [[4, 0.55], [1.5, 0.55], [1.5, 1]]],
    ['api', 'mw', null, null, [[0, 1]]], ['api', 'domain', null, null, [[2, 1]]], ['gw', 'domain', null, null, [[3, 1.5], [2.5, 1.5], [2.5, 2]]],
    ['domain', 'db', null, null, [[1, 2]]], ['domain', 'redis'], ['domain', 'files', null, null, [[3, 2]]],
    ['workers', 'domain', 'jobs'], ['workers', 'hooks', 'outbox'],
], $legend);

diagram('02-request-lifecycle', 'Lifecycle of an API request', [
    'client' => [0, 0, "Client\nweb, app, ERP", 'actor'],
    'auth' => [1, 0, "Authenticate\ntoken or session", 'gate'],
    'active' => [2, 0, "Account checks\nactive, MFA, device", 'gate'],
    'tenant' => [3, 0, "Organization\nX-Organization-Id", 'gate'],
    'module' => [4, 0, "Module + plan\nenabled, entitled", 'gate'],
    'idem' => [4, 1, "Idempotency\nsafe retries", 'gate'],
    'perm' => [3, 1, "Permission\nrole + location", 'gate'],
    'service' => [2, 1, "Domain service\none DB transaction", 'step'],
    'records' => [1, 1, "Records written\nledger, audit, trace", 'store'],
    'resp' => [0, 1, "JSON response\ndata or error code", 'actor'],
    'outbox' => [2, 2, "Outbox event\nsame transaction", 'store'],
    'worker' => [3, 2, "Queue worker\nafter commit: webhooks", 'step'],
], [
    ['client', 'auth'], ['auth', 'active'], ['active', 'tenant'], ['tenant', 'module'], ['module', 'idem'], ['idem', 'perm'],
    ['perm', 'service'], ['service', 'records'], ['records', 'resp'], ['service', 'outbox', null, 'dashed'], ['outbox', 'worker'],
], $legend);

diagram('03-onboarding', 'Organization onboarding and verification', [
    'user' => [0, 0, "Person signs up\naccount", 'actor'],
    'org' => [1, 0, "Register organization\ntype, country", 'step'],
    'mods' => [2, 0, "Default modules\ntrial plan", 'store'],
    'req' => [3, 0, "Country checklist\ndocs, licences", 'gate'],
    'docs' => [3, 1, "Upload documents\nadd licence numbers", 'step'],
    'submit' => [2, 1, "Submit for\nverification", 'step'],
    'review' => [1, 1, "Compliance review\nplatform staff", 'gate'],
    'verified' => [0, 1, "Approved: verified\nfull network access", 'store'],
    'changes' => [1, 2, "Changes requested\nwith notes", 'alert'],
    'team' => [0, 2, "Invite team\nroles, locations", 'step'],
], [
    ['user', 'org'], ['org', 'mods'], ['mods', 'req'], ['req', 'docs'], ['docs', 'submit'], ['submit', 'review'],
    ['review', 'verified'], ['review', 'changes'], ['changes', 'submit', 'resubmit', 'dashed', [[2, 2]]], ['verified', 'team'],
], $legend);

diagram('04-order-to-cash', 'Order to cash', [
    'field' => [1, 0, "Field order\nfrom the app", 'actor'],
    'quote' => [0, 1, "Quotation\noptional", 'step'],
    'so' => [1, 1, "Sales order\nprices, tax", 'step'],
    'approve' => [2, 1, "Approval\ndiscount, credit", 'gate'],
    'confirm' => [3, 1, "Confirm\nreserve stock FEFO", 'step'],
    'deliver' => [4, 1, "Delivery\nstock issued", 'step'],
    'ship' => [5, 1, "Shipment\nproof of delivery", 'external'],
    'ledger' => [4, 2, "Stock ledger + COGS\ntrace event", 'store'],
    'invoice' => [3, 2, "Invoice\nreceivable", 'step'],
    'pay' => [2, 2, "Payment\nallocated", 'step'],
    'credit' => [1, 2, "Credit note\nreturns", 'alert'],
    'pos' => [3, 3, "POS sale\ncounter, offline", 'actor'],
], [
    ['field', 'so', 'accepted'], ['quote', 'so'], ['so', 'approve'], ['approve', 'confirm'], ['confirm', 'deliver'], ['deliver', 'ship', null, 'dashed'],
    ['deliver', 'ledger'], ['ledger', 'invoice'], ['invoice', 'pay'], ['pay', 'credit', null, 'dashed'], ['pos', 'invoice', 'invoice + stock'],
], $legend);

diagram('05-procure-to-pay', 'Procure to pay', [
    'req' => [0, 0, "Requisition\nor recommendation", 'step'],
    'reqok' => [1, 0, "Approve\nrequisition", 'gate'],
    'po' => [2, 0, "Purchase order\nsupplier prices", 'step'],
    'pook' => [3, 0, "Approve + send\nabove threshold", 'gate'],
    'grn' => [4, 0, "Goods receipt\nbatches, expiry", 'step'],
    'mkt' => [0, 1, "Marketplace order\nto a supplier", 'actor'],
    'pay' => [1, 1, "Payment\nto supplier", 'step'],
    'inv' => [2, 1, "Supplier invoice\n3-way match", 'gate'],
    'stock' => [3, 1, "Stock + journal\ntrace event", 'store'],
    'quality' => [4, 1, "Quality status\nreleased, quarantine", 'gate'],
], [
    ['req', 'reqok'], ['reqok', 'po'], ['po', 'pook'], ['pook', 'grn'], ['grn', 'quality'], ['quality', 'stock'], ['stock', 'inv'], ['inv', 'pay'],
    ['mkt', 'po', 'creates PO', null, [[0, 0.5], [2, 0.5]]],
], $legend);

diagram('06-offline-sync', 'Offline capture and synchronization', [
    'user' => [0, 0, "Rep or cashier\nworks offline", 'actor'],
    'local' => [1, 0, "Encrypted device DB\nvisits, orders, sales", 'store'],
    'queue' => [2, 0, "Device outbox\nclient_txn_id", 'store'],
    'send' => [3, 0, "POST sync/batches\nwhen online", 'step'],
    'inbox' => [4, 0, "Sync inbox\nidempotent", 'gate'],
    'apply' => [4, 1, "Apply\nsame rules as online", 'step'],
    'ack' => [3, 1, "Acknowledgement\nsynced, per envelope", 'step'],
    'update' => [2, 1, "Device updated\nnumbers, status", 'store'],
    'issue' => [4, 2, "Sync issue\nfor review", 'alert'],
    'review' => [3, 2, "Supervisor\nresolves", 'actor'],
], [
    ['user', 'local'], ['local', 'queue'], ['queue', 'send'], ['send', 'inbox'], ['inbox', 'apply'], ['apply', 'ack'], ['ack', 'update'],
    ['apply', 'issue', 'conflict', 'dashed'], ['issue', 'review'], ['send', 'queue', 'retry with backoff', 'dashed', [[3, -0.42], [2, -0.42]]],
], $legend);

diagram('07-traceability-recall', 'Traceability and recall', [
    'move' => [0, 0, "Stock movement\nreceipt, sale, transfer", 'step'],
    'event' => [1, 0, "Trace event\nhash-chained", 'store'],
    'scan' => [2, 0, "Pack scan\nGS1 DataMatrix, QR", 'actor'],
    'result' => [3, 0, "Scan result\nrecorded, recalled", 'step'],
    'init' => [0, 1, "Recall prepared\nbrand owner", 'actor'],
    'second' => [1, 1, "Second person\nactivates", 'gate'],
    'holders' => [2, 1, "Every holder\nquarantined, notified", 'alert'],
    'ack' => [3, 1, "Holder\nacknowledges", 'step'],
    'close' => [2, 2, "Close recall\nexposure report", 'gate'],
    'recover' => [3, 2, "Recovery\nfrom records", 'store'],
], [
    ['move', 'event'], ['event', 'scan', null, 'dashed'], ['scan', 'result'], ['init', 'second'], ['second', 'holders'], ['holders', 'ack'],
    ['ack', 'recover'], ['recover', 'close'], ['event', 'holders', 'who holds the lot', 'dashed', [[1, 0.5], [2, 0.5]]],
], $legend);

diagram('08-integration', 'External system integration', [
    'erp' => [0, 0, "External system\nERP, WMS", 'external'],
    'auth' => [1, 0, "POST with key\n+ optional HMAC", 'gate'],
    'inbox' => [2, 0, "Durable inbox\nmessage_id dedupe", 'store'],
    'map' => [3, 0, "Field mappings\nto canonical model", 'step'],
    'apply' => [4, 0, "Domain services\nproducts, stock", 'step'],
    'status' => [1, 1, "Status by\nmessage_id", 'step'],
    'quar' => [3, 1, "Quarantine\nfix, map, retry", 'alert'],
    'event' => [4, 1, "Business event\ne.g. stock received", 'step'],
    'recv' => [0, 2, "Your endpoint\nverify, dedupe", 'external'],
    'hook' => [2, 2, "Signed webhook\nretries", 'step'],
    'outbox' => [4, 2, "Outbox\nsame transaction", 'store'],
], [
    ['erp', 'auth'], ['auth', 'inbox'], ['inbox', 'map'], ['map', 'apply'], ['map', 'quar', 'invalid', 'dashed'],
    ['inbox', 'status', '202 ack', 'dashed', [[2, 0.5], [1.5, 0.5], [1.5, 1]]], ['erp', 'status', 'poll', 'dashed'],
    ['apply', 'event'], ['event', 'outbox'], ['outbox', 'hook'], ['hook', 'recv'],
], $legend);
