// Load test against the LOAD TEST organization (php artisan perf:generate) behind tools/load/cluster.mjs.
//
//   node tools/load/run.mjs --base http://127.0.0.1:8300/api/v1 --levels 1,4,8,16 --seconds 20 [--only pos_sale,stock_levels]
//
// Each scenario runs at each concurrency level for a fixed time; every virtual user loops on
// its scenario. Prints p50/p95/p99 latency, throughput and errors per scenario and level, and
// writes the full result to tools/load/results/<timestamp>.json.
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, v, i, a) => (v.startsWith('--') ? [...acc, [v.slice(2), a[i + 1]]] : acc), []));
const base = args.base ?? 'http://127.0.0.1:8300/api/v1';
const levels = (args.levels ?? '1,4,8,16').split(',').map(Number);
const seconds = Number(args.seconds ?? 20);
const only = args.only ? args.only.split(',') : null;
const PASSWORD = 'LoadTest-2026!';
const rand = (n) => Math.floor(Math.random() * n);
const ulid = () => {
    const A = '0123456789abcdefghjkmnpqrstvwxyz';
    let t = Date.now(), s = '';
    for (let i = 0; i < 10; i++) { s = A[t % 32] + s; t = Math.floor(t / 32); }
    for (let i = 0; i < 16; i++) s += A[rand(32)];
    return s;
};

async function call(method, path, { token, org, body, idem } = {}) {
    const headers = { Accept: 'application/json' };
    if (token) headers.Authorization = `Bearer ${token}`;
    if (org) headers['X-Organization-Id'] = org;
    if (body) headers['Content-Type'] = 'application/json';
    if (method !== 'GET') headers['Idempotency-Key'] = idem ?? `load-${ulid()}`;
    const t0 = performance.now();
    const res = await fetch(base + path, { method, headers, body: body ? JSON.stringify(body) : undefined });
    const text = await res.text();
    const ms = performance.now() - t0;
    let json = null;
    try { json = JSON.parse(text); } catch {}
    if (res.status >= 400) {
        const err = new Error(`${method} ${path} -> ${res.status} ${json?.message ?? text.slice(0, 120)}`);
        err.status = res.status;
        throw err;
    }
    return { json, ms };
}

async function signIn(email) {
    const { json } = await call('POST', '/auth/tokens', { body: { email, password: PASSWORD, device_name: 'load test' } });
    return json.data.token;
}

// ---- Setup ------------------------------------------------------------------------------------
const ownerToken = await signIn('owner@load.test');
const me = (await call('GET', '/auth/me', { token: ownerToken })).json.data;
const org = me.memberships.map((m) => m.organization).find((o) => o.name === 'LOAD TEST Pharmacy Chain').id;
const owner = { token: ownerToken, org };
const products = (await call('GET', '/catalogue/items?per_page=200', owner)).json.data;
const productIds = products.map((p) => p.product_id ?? p.product?.id).filter(Boolean);

const cashiers = [];
for (let i = 1; i <= 8; i++) {
    const token = await signIn(`cashier${i}@load.test`);
    const pos = (await call('GET', '/pos/me', { token, org })).json.data;
    const terminal = pos.terminals.find((t) => t.code === `T${i}`) ?? pos.terminals[0];
    let shift = pos.shift;
    if (!shift) shift = (await call('POST', '/pos/shifts', { token, org, body: { pos_terminal_id: terminal.id, opening_float: '20000' } })).json.data;
    cashiers.push({ token, org, terminal, shift });
}
const today = new Date().toISOString().slice(0, 10);
const monthAgo = new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10);

// ---- Scenarios: each returns the number of requests it made -----------------------------------
const scenarios = {
    catalogue_search: async () => { await call('GET', `/catalogue/items?q=${encodeURIComponent('Loadtestamine 00' + rand(50))}&per_page=25`, owner); },
    stock_levels: async () => { await call('GET', `/inventory/stock-levels?per_page=50&page=${1 + rand(10)}`, owner); },
    expiring: async () => { await call('GET', '/inventory/expiring?days=240', owner); },
    customers: async () => { await call('GET', `/customers?q=${encodeURIComponent('Customer ' + (1 + rand(499)))}`, owner); },
    pos_lookup: async (vu) => { const c = cashiers[vu % cashiers.length]; await call('GET', `/pos/lookup?pos_terminal_id=${c.terminal.id}&q=${encodeURIComponent('Loadtestamine 00' + rand(50))}`, c); },
    pos_sales_list: async () => { await call('GET', '/pos/sales?per_page=25', owner); },
    pos_report: async () => { await call('GET', `/pos/reports/summary?from=${monthAgo}&to=${today}`, owner); },
    sales_dashboard: async () => { await call('GET', `/analytics/sales?from=${monthAgo}&to=${today}`, owner); },
    executive_dashboard: async () => { await call('GET', `/analytics/executive?from=${monthAgo}&to=${today}`, owner); },
    // A complete counter sale: start, lines, checkout, cash payment (4 requests).
    pos_sale: async (vu) => {
        const c = cashiers[vu % cashiers.length];
        const sale = (await call('POST', '/pos/sales', { ...c, body: { pos_shift_id: c.shift.id, client_transaction_id: ulid() } })).json.data;
        const lines = Array.from({ length: 1 + rand(3) }, () => ({ product_id: productIds[rand(productIds.length)], package_level_code: 'unit', quantity: String(1 + rand(3)) }));
        await call('PUT', `/pos/sales/${sale.id}/lines`, { ...c, body: { lines } });
        const checked = (await call('POST', `/pos/sales/${sale.id}/checkout`, c)).json.data;
        await call('POST', `/pos/sales/${sale.id}/payments`, { ...c, body: { method: 'cash', amount: String(checked.total), tendered: String(Math.ceil(Number(checked.total) / 500) * 500 || 500) } });
        return 4;
    },
};

const pct = (sorted, p) => (sorted.length ? sorted[Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))] : null);
const results = [];
for (const [name, fn] of Object.entries(scenarios)) {
    if (only && !only.includes(name)) continue;
    for (const level of levels) {
        const lat = [];
        let errors = 0, requests = 0;
        const errorSamples = new Set();
        const end = performance.now() + seconds * 1000;
        await Promise.all(Array.from({ length: level }, async (_, vu) => {
            while (performance.now() < end) {
                const t0 = performance.now();
                try {
                    requests += (await fn(vu)) ?? 1;
                    lat.push(performance.now() - t0);
                } catch (e) {
                    errors++;
                    if (errorSamples.size < 3) errorSamples.add(e.message);
                }
            }
        }));
        lat.sort((a, b) => a - b);
        const r = { scenario: name, concurrency: level, iterations: lat.length, requests, errors, per_second: +(lat.length / seconds).toFixed(2),
            p50: Math.round(pct(lat, 50)), p95: Math.round(pct(lat, 95)), p99: Math.round(pct(lat, 99)), max: Math.round(lat.at(-1) ?? 0), error_samples: [...errorSamples] };
        results.push(r);
        console.log(`${name.padEnd(20)} c=${String(level).padStart(2)}  ${String(r.per_second).padStart(7)}/s  p50 ${String(r.p50).padStart(5)}ms  p95 ${String(r.p95).padStart(5)}ms  p99 ${String(r.p99).padStart(5)}ms  errors ${errors}${errorSamples.size ? '  ' + [...errorSamples][0] : ''}`);
    }
}

const out = join(dirname(fileURLToPath(import.meta.url)), 'results');
mkdirSync(out, { recursive: true });
const file = join(out, `${new Date().toISOString().replace(/[:.]/g, '-')}.json`);
writeFileSync(file, JSON.stringify({ base, levels, seconds, at: new Date().toISOString(), results }, null, 2));
console.log(`\nSaved ${file}`);
