// Summarizes storage/logs/perf.jsonl (written by ProfileRequest when PERF_PROFILE=true) per route:
// requests, latency, queries per request and repeated queries, worst routes first.
//
//   node tools/load/summarize.mjs [--since 2026-10-23T10:00] [--top 20]
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, v, i, a) => (v.startsWith('--') ? [...acc, [v.slice(2), a[i + 1]]] : acc), []));
const file = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', 'storage', 'logs', 'perf.jsonl');
const rows = readFileSync(file, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)).filter((r) => !args.since || r.at >= args.since);

const groups = new Map();
for (const r of rows) {
    const key = `${r.method} ${r.route}`;
    if (!groups.has(key)) groups.set(key, { key, ms: [], queries: [], qms: [], repeated: [], mem: [], statuses: {}, top: new Map() });
    const g = groups.get(key);
    g.ms.push(r.ms); g.queries.push(r.queries); g.qms.push(r.query_ms); g.repeated.push(r.repeated); g.mem.push(r.memory_mb);
    g.statuses[r.status] = (g.statuses[r.status] ?? 0) + 1;
    for (const t of r.top_repeated ?? []) g.top.set(t.sql, Math.max(g.top.get(t.sql) ?? 0, t.n));
    g.slow ??= new Map();
    for (const q of r.slowest ?? []) { const s = g.slow.get(q.sql) ?? { n: 0, ms: 0 }; s.n++; s.ms += q.ms; g.slow.set(q.sql, s); }
}
const avg = (a) => a.reduce((s, x) => s + x, 0) / a.length;
const p95 = (a) => [...a].sort((x, y) => x - y)[Math.floor(a.length * 0.95)] ?? a.at(-1);
const table = [...groups.values()].map((g) => ({
    route: g.key, n: g.ms.length, avg_ms: avg(g.ms).toFixed(0), p95_ms: p95(g.ms).toFixed(0),
    queries: avg(g.queries).toFixed(1), max_queries: Math.max(...g.queries), query_ms: avg(g.qms).toFixed(1),
    repeated: avg(g.repeated).toFixed(1), mem_mb: Math.max(...g.mem), statuses: JSON.stringify(g.statuses),
    worst_repeat: [...g.top.entries()].sort((a, b) => b[1] - a[1])[0],
    slow: [...(g.slow ?? new Map()).entries()].map(([sql, s]) => ({ sql, total: s.ms, avg: s.ms / s.n })).sort((a, b) => b.total - a.total).slice(0, 2),
})).sort((a, b) => b.avg_ms * b.n - a.avg_ms * a.n).slice(0, Number(args.top ?? 25));

console.log(`${rows.length} requests profiled\n`);
console.log('| Route | n | avg ms | p95 ms | queries (max) | query ms | repeated | peak MB | status |');
console.log('|---|---|---|---|---|---|---|---|---|');
for (const t of table) console.log(`| ${t.route} | ${t.n} | ${t.avg_ms} | ${t.p95_ms} | ${t.queries} (${t.max_queries}) | ${t.query_ms} | ${t.repeated} | ${t.mem_mb} | ${t.statuses} |`);
console.log('\nMost repeated query per route (count in one request):');
for (const t of table.filter((t) => t.worst_repeat && t.worst_repeat[1] > 2)) console.log(`- ${t.route}: x${t.worst_repeat[1]}  ${t.worst_repeat[0]}`);
console.log('\nSlowest queries per route (most total time; average ms when among the slowest three):');
for (const t of table.filter((t) => t.slow.length)) for (const s of t.slow) console.log(`- ${t.route}: avg ${s.avg.toFixed(1)}ms  ${s.sql}`);
