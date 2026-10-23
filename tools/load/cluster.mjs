// Starts N application instances (PHP built-in server, one request at a time each, OPcache on,
// debug off) on ports BASE+1..BASE+N, and a round-robin HTTP load balancer on BASE.
// The instances share the database, cache, sessions and queue, as production instances would.
//
//   node tools/load/cluster.mjs --instances 8 --port 8300 --database pharma_network_load
//
// Ctrl+C (or killing this process) stops the instances it started; their PIDs are written to
// tools/load/.cluster-pids so a stray cluster can be stopped by PID, never by process name.
import { spawn } from 'node:child_process';
import http from 'node:http';
import { writeFileSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, v, i, a) => (v.startsWith('--') ? [...acc, [v.slice(2), a[i + 1]]] : acc), []));
const instances = Number(args.instances ?? 8);
const port = Number(args.port ?? 8300);
const database = args.database ?? 'pharma_network_load';
if (!database.endsWith('_load')) throw new Error('The load cluster only runs against a *_load database.');

const here = dirname(fileURLToPath(import.meta.url));
// --root serves another checkout (for example a git worktree of an earlier commit, for A/B runs).
const root = args.root ? resolve(args.root) : resolve(here, '..', '..');
const pidFile = join(here, '.cluster-pids');
const children = [];

for (let i = 1; i <= instances; i++) {
    const p = port + i;
    const child = spawn('php', [
        '-d', 'zend_extension=opcache', '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0',
        '-d', 'opcache.memory_consumption=256', '-d', 'memory_limit=512M',
        '-S', `127.0.0.1:${p}`, join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'),
    ], {
        cwd: join(root, 'public'),
        env: { ...process.env, DB_DATABASE: database, APP_ENV: 'staging', APP_DEBUG: 'false', LOG_LEVEL: 'warning', PERF_PROFILE: args.profile === 'false' ? 'false' : 'true', PERF_PROFILE_SQL: args.sql === 'true' ? 'true' : 'false', QUEUE_CONNECTION: 'database' },
        stdio: 'ignore',
    });
    children.push({ port: p, child });
}
writeFileSync(pidFile, children.map((c) => c.child.pid).join('\n'));

// Least-busy balancing: each PHP built-in server handles one request at a time, so a request
// goes to the instance with the fewest requests in flight (ties rotate).
const agents = children.map(() => new http.Agent({ keepAlive: true, maxSockets: 1 }));
const inflight = children.map(() => 0);
let rotate = 0;
const pick = () => {
    rotate = (rotate + 1) % children.length;
    let best = rotate;
    for (let k = 0; k < children.length; k++) {
        const i = (rotate + k) % children.length;
        if (inflight[i] < inflight[best]) best = i;
    }
    return best;
};
const server = http.createServer((req, res) => {
    const i = pick();
    inflight[i]++;
    let done = false;
    const release = () => { if (!done) { done = true; inflight[i]--; } };
    res.on('close', release);
    const upstream = http.request({ host: '127.0.0.1', port: children[i].port, path: req.url, method: req.method, headers: req.headers, agent: agents[i] }, (up) => {
        res.writeHead(up.statusCode, up.headers);
        up.pipe(res);
    });
    upstream.on('error', (e) => { if (!res.headersSent) res.writeHead(502); res.end(JSON.stringify({ message: `instance ${children[i].port}: ${e.message}` })); });
    req.pipe(upstream);
});
server.keepAliveTimeout = 30000;
server.listen(port, '127.0.0.1', () => console.log(`load balancer on http://127.0.0.1:${port} -> ${instances} instances (${database})`));

const stop = () => {
    for (const { child } of children) { try { child.kill(); } catch {} }
    try { rmSync(pidFile); } catch {}
    process.exit(0);
};
process.on('SIGINT', stop);
process.on('SIGTERM', stop);

