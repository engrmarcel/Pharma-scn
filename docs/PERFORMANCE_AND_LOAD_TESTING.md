# Performance and load testing (Phase 10a)

Phase 10 asks for scaling work "as justified by measurements", with load-test results and their limitations documented. This page records how the platform was measured, what the measurements found, what was changed because of them, and what was deliberately left alone.

## 1. Summary

- **Correctness under concurrency was the real finding.** With 4 or more cashiers selling at once, 2–25% of counter sales failed with database deadlocks. Four causes were found and fixed (section 5.1). After the fixes, runs with up to 16 concurrent cashiers had **no errors and no deadlocks**, and `php artisan ops:consistency` found **every invariant intact**: stock equals its ledger, nothing is negative or over-reserved, every journal balances, every completed sale is paid and invoiced, document numbers are unique and every traceability chain verifies.
- **Dashboards were the costliest reads** (the executive dashboard ran 87 queries, 0.6–2.9 s). A short per-viewer cache made them about 5× faster under load, without letting anyone see figures after losing access.
- **The rate limit ignored the plan.** The per-user API limit was fixed at 120 a minute although plans define it (60 on Starter, unlimited on Enterprise). It now follows the plan.
- **Writes per organization are serialized by design** (gapless document numbers and one traceability hash chain per organization). On the test machine that caps completed counter sales at about **3 per second per organization**; the estimate for production hardware is 20–30 per second. That is above a large chain's realistic peak, so nothing was changed; the trigger and the design change are recorded in section 6.
- **Absolute timings on the test machine are pessimistic** (section 2). Query counts, error counts, lock waits and before/after ratios are the reliable results.

## 2. Test environment and its limitations

| Item | Test machine | Production target (DEPLOYMENT_GUIDE.md) |
|---|---|---|
| Application | 8 PHP 8.3 instances (built-in server, one request at a time each), OPcache on, `APP_DEBUG=false`, behind a least-busy load balancer (`tools/load/cluster.mjs`) | PHP-FPM behind a load balancer, several instances |
| Database | MariaDB 10.4 (XAMPP) on the same Windows machine, **16 MB InnoDB buffer pool** (XAMPP default), durable commits (`innodb_flush_log_at_trx_commit=1`) | MySQL 8, managed, buffer pool sized to the working set |
| Cache, sessions, queue, rate limiter | **Database** (`CACHE_STORE=database`): every rate-limit check is a locked read and a write | Redis |
| CPU | 8 cores shared by the load generator, 8 PHP instances and the database | Separate hosts |

Consequences:

- The load-test database grew to about 120 MB, so most reads miss the 16 MB buffer pool and go to disk. Per-query times on this machine are several times what a production database would show, and they drift as the data grows (section 4.3).
- About a third of every simple request's queries are cache-table reads and writes for the rate limiter and permission caches; with Redis these are sub-millisecond network calls.
- The shared XAMPP database server was not reconfigured, because other projects on the machine use it.

## 3. Method

1. **Synthetic data at volume** (`php artisan perf:generate`), created through the domain services so ledgers, trace chains and journals are what production would hold. It only runs against a database whose name ends in `_load`, never in production, and marks everything LOAD TEST (`@load.test` users). The run measured here: 500 products, 3 stores, 3,000 batches, 500 customers, 8 tills and 2,400 counter sales spread over 60 days (generation took 15 minutes; 3.0 sales a second through the services in one process).
2. **A production-like cluster** (`node tools/load/cluster.mjs --instances 8`): separate PHP processes sharing the database, cache, sessions and queue, which also exercises running several application instances behind a load balancer.
3. **Scenarios** (`node tools/load/run.mjs`): catalogue search, stock levels, expiring stock, customer search, POS product lookup, POS sales list, POS summary report, sales dashboard, executive dashboard, and a complete counter sale (start, cart, checkout, cash payment: four requests). Each scenario runs at 1, 4, 8 and 16 concurrent users for 15 seconds.
4. **Per-request profile** (`PERF_PROFILE=true`, `App\Http\Middleware\ProfileRequest`): time, query count and time, repeated queries, the slowest queries and peak memory per request, summarized per route by `node tools/load/summarize.mjs`. It is off by default and refuses to run in production.
5. **Lock analysis**: InnoDB lock-wait counters and sampled `innodb_lock_waits` during concurrent sales.
6. **Consistency** after every run: `php artisan ops:consistency`.
7. **A/B check** (`bash tools/load/ab.sh`): the previous commit and the current code, alternating, on the same database, to separate code effects from data growth.

Raw results are in `tools/load/results/` (JSON per run, plus `baseline-*` and `after-*` text and profiles).

## 4. Results

### 4.1 Before and after the fixes (8 instances; per second · median · 95th percentile · errors)

| Scenario | Users | Before | After |
|---|---|---|---|
| Executive dashboard | 1 | 1.6 · 635 ms · 749 ms · 0 | 6.3 · 139 ms · 189 ms · 0 |
| Executive dashboard | 16 | 6.4 · 2,864 ms · 3,032 ms · 0 | 32.8 · 481 ms · 632 ms · 0 |
| Sales dashboard | 16 | 13.9 · 1,211 ms · 1,385 ms · 0 | 32.3 · 483 ms · 714 ms · 0 |
| Counter sale (4 requests) | 1 | 1.27 · 826 ms · 1,065 ms · 0 | 0.73 · 1,332 ms · 1,869 ms · 0 |
| Counter sale | 8 | 2.73 · 1,746 ms · 2,327 ms · **61 errors** | 2.93 · 2,846 ms · 4,201 ms · **0** |
| Counter sale | 16 | 2.6 · 3,340 ms · 4,365 ms · **67 errors** | 3.33 · 5,634 ms · 7,820 ms · **0** |
| Catalogue search | 16 | 33.3 · 483 ms · 597 ms · 0 | 28.3 · 577 ms · 710 ms · 0 |
| Customer search | 16 | 36.3 · 435 ms · 597 ms · 0 | 29.9 · 530 ms · 764 ms · 0 |
| Stock levels | 16 | 15.7 · 1,037 ms · 1,292 ms · 0 | 13.3 · 1,263 ms · 1,491 ms · 0 |

Deadlocks logged during the "after" run: **0** (before: 135 failed requests, all deadlocks).

Before the fixes, failed sales also hid their cost: a sale that died early was cheap, so "before" throughput for counter sales is flattered by its failures.

### 4.2 Fixed cost per request

A simple customer search made 26 queries, of which 2 do the search. After the fixes it makes 22:

| Queries | What | Status |
|---|---|---|
| 5 | Rate limiter on the database cache (locked read and write) | Configuration: Redis in production |
| 7 | Permission and settings caches | Configuration: Redis in production |
| 3 → 1 | Module check, one query per candidate module | **Fixed**: one query |
| 4 → 2 | Subscription and plan loaded twice per entitlement check | **Fixed**: loaded once |
| 1 → 0 (mostly) | API token "last used" written on every request | **Fixed**: at most once a minute |
| 2 | Token and user lookup | Required |

### 4.3 Data growth versus code (A/B)

Simple reads looked 20–40% slower after the fixes than in the baseline. The A/B run settles it: the previous commit and the current code on the same database give the same results (table below), so the difference comes from the database having grown by about a quarter between the runs (more sales, movements and audit rows) on a buffer pool far smaller than the data, not from the code.

| Scenario | Users | Previous commit: per s · median · p95 | Current: per s · median · p95 |
|---|---|---|---|
| catalogue search | 1 | 6.0 · 167 ms · 218 ms | 6.0 · 165 ms · 207 ms |
| catalogue search | 8 | 27.2 · 289 ms · 384 ms | 29.2 · 271 ms · 357 ms |
| stock levels | 1 | 2.6 · 380 ms · 501 ms | 2.7 · 365 ms · 475 ms |
| stock levels | 8 | 12.6 · 632 ms · 825 ms | 13.7 · 591 ms · 707 ms |
| customers | 1 | 6.5 · 155 ms · 218 ms | 7.0 · 143 ms · 174 ms |
| customers | 8 | 32.5 · 237 ms · 355 ms | 34.5 · 226 ms · 309 ms |
| pos lookup | 1 | 5.6 · 171 ms · 251 ms | 5.6 · 172 ms · 235 ms |
| pos lookup | 8 | 28.5 · 265 ms · 388 ms | 27.4 · 279 ms · 395 ms |

Averages of two alternating rounds each (12 s per scenario and level). The current code is equal or slightly faster (POS lookup is within run-to-run noise): fewer queries per request (section 4.2).

## 5. Findings and changes

### 5.1 Deadlocks under concurrent sales (fixed)

Sampled lock waits and the error log pointed to four causes:

1. **Insert-then-lock on counters.** `DocumentNumberer::next()` and `TraceChain::append()` ran `INSERT IGNORE` on every call, then `SELECT … FOR UPDATE`. On an existing row the insert takes a shared lock, and two transactions upgrading shared locks to exclusive deadlock each other. Now the row is locked first and only inserted when missing.
2. **Gap lock when replacing a cart.** Replacing a sale's lines deleted them by sale id. On an empty cart that takes a gap lock at the end of the index, which is exactly where every new sale inserts its lines. Lines are now deleted by primary key.
3. **Inconsistent lock order across products.** Checkout and completion reserved and issued stock in cart order, so two sales with the same products in different orders locked balances in opposite orders. Both now go in product order.
4. **Lock on a missing idempotency key.** Claiming a new idempotency key locked a row that did not exist yet (a gap lock), so two new keys in the same gap deadlocked on insert. The unique index now decides: insert, and on a duplicate read the existing key.

In addition, the POS transactions (start, cart, checkout, cash or standalone-terminal payment) retry up to three times if the database still picks them as a deadlock victim; a deadlock rolls the whole transaction back, so the retry is clean. Payments through an electronic provider are not retried, because a retry would start a second charge at the provider.

### 5.2 Dashboards (changed)

The executive dashboard recomputes about sixty aggregates on every request. Two of the slowest queries read every row of the organization because no index matched an organization-and-period filter; two indexes were added (`inventory_movements (organization_id, posted_at)`, `sales_invoices (organization_id, invoice_date)`).

Dashboards are now kept for `ANALYTICS_DASHBOARD_CACHE_SECONDS` (default 60) per viewer. The cache key includes the organization, the viewer, their current permissions and their warehouse restrictions, so a change in access applies immediately; only the figures can be up to a minute old, and every dashboard response states `meta.generated_at`.

### 5.3 API rate limit (fixed)

The plan entitlement `api_requests_per_minute` is now enforced per user for the organization named in the request (cached for a minute). Requests without an organization, or naming one the user does not belong to, get the platform default (`API_DEFAULT_RATE_PER_MINUTE`, 120). "Unlimited" plans still have a platform ceiling (`API_UNLIMITED_RATE_CEILING`, 6,000).

### 5.4 Write serialization per organization (measured, not changed)

During 8 concurrent cashiers, 85% of sampled lock waits were on the organization's traceability chain head, and about a third of all request time was spent waiting for row locks. Every completed sale appends trace events to the organization's single hash chain and takes the next invoice, receipt and journal numbers from per-organization counters; each of these locks is held until the transaction commits. Removing only the chain head would move the queue to the number counters.

Completed counter sales therefore have a ceiling per organization: about 3 per second on the test machine, where the serialized part of a completion (from the first stock movement to commit, a few dozen queries) takes about 300 ms. On the test machine single queries and commits take several times longer than on a production database with its working set in memory, so the ceiling there is estimated at 20–30 per second. This is an estimate, not a measurement; it should be confirmed on staging hardware (section 6). A 500-till chain at one sale per till per minute needs about 8 per second.

### 5.5 Smaller observations

- **Guarded-attribute schema lookups.** Models with column-specific `$guarded` lists make Laravel read the table's columns from `information_schema` once per request per model class (about 5% of a payment's database time here). Left as is: switching 63 models to `$fillable` changes mass-assignment protection for a small gain.
- **Stock levels** aggregates every balance of the organization and runs the aggregation twice per page (rows and count). At 3,000 balances it is about 190 ms per query on the test machine.

## 6. Limits and when to act

| Watch | Measured here | Act when | What to do |
|---|---|---|---|
| Completed sales per organization | ~3/s (test machine), 20–30/s estimated in production | Peak completed sales of one organization approach half the production ceiling, or POS p95 rises with till count | Number series per store or till (for example `POS-T1-…`) and one traceability chain per store, verified together (needs an ADR: it changes the chain and numbering design) |
| Stock levels page | ~190 ms per aggregation at 3,000 balances | An organization passes about 50,000 batch balances, or the page's p95 exceeds 1 s in production | Maintain a product-and-store stock summary on posting instead of aggregating per request |
| Dashboards | 60 s cache per viewer | Many viewers per organization make the cache ineffective | Share the cache per permission set rather than per viewer, or precompute the heavy aggregates (the `analytics:refresh` job already builds daily demand) |
| Reporting load on the primary | Not separated in the test | Dashboard and report queries show up in primary database CPU | Point `ANALYTICS_DB_CONNECTION` at a read replica (already supported) |
| Rate limiter and caches | Database cache in the test | Always in production | Redis (`CACHE_STORE=redis`), as the deployment guide requires |

## 7. Scaling readiness (Phase 10d)

Phase 10 lists the scaling work to do "as justified by measurements". Each item, what is in place, and what would justify more:

| Item | In place | Evidence | Do more when |
|---|---|---|---|
| Multiple application instances | Stateless instances: sessions, cache, queue and rate limits in shared stores; uploaded files on configurable disks (`ATTACHMENTS_DISK`, `LANDING_MEDIA_DISK`, `BACKUP_DISK`); every scheduled task runs on one server only | The load test ran 8 instances on one database with no errors; `ScalingReadinessTest` | Always required in production |
| Load balancing | Any HTTP balancer; instances need no affinity (sessions are shared) | `tools/load/cluster.mjs` balanced 8 instances (least-busy) | — |
| Read replicas | Optional: `DB_READ_HOST` splits reads to replicas (writes, locking reads and reads inside transactions stay on the primary; a request reads its own writes); `ANALYTICS_DB_CONNECTION` sends dashboards and reports to a replica | Configuration verified; not load-tested against a real replica | Primary CPU is dominated by reads, or reports slow down transactions |
| Queue scaling | One `default` queue; add workers horizontally. Heartbeats and backlog alerts show when workers fall behind | Phase 10b monitoring | The oldest job regularly waits over a minute, or integration bursts delay notifications: split into `critical` (recall notices, alerts), `default`, `integrations`, `webhooks` and size workers per queue |
| Durable event delivery | Transactional outbox; deliveries retried with backoff, lost or interrupted deliveries recovered every minute, abandoned ones kept for redelivery | `ResilienceTest`, integration tests | — |
| Analytics data separation | Analytics read through their own connection (replica-ready), daily demand facts precomputed, dashboards cached per viewer | Section 5.2 | Reports load the primary: point `ANALYTICS_DB_CONNECTION` at a replica; later a warehouse fed from the outbox |
| Dedicated search | Database `LIKE` search on indexed columns | Catalogue and customer search: median about 150 ms on the test machine at 500 products and 500 customers | Search p95 over 500 ms in production, or catalogues above about 50,000 items: an external search index fed from the outbox |
| Event streaming | Webhooks from the outbox | — | A consumer needs replay or ordered streams beyond webhooks |
| Regional infrastructure | One deployment per region, organizations pinned to their region (ADR-0023) | Region tests | A new region's data must stay in-country |
| Service decomposition | One application | Section 5.4: the bottleneck is per-organization write serialization, which splitting services would not remove | A component's scaling or release needs differ measurably from the rest |
| Disaster recovery | Backups, tested restore drills, runbook | docs/DISASTER_RECOVERY.md | — |
| Load and resilience testing | Tooling and results in this document; failure-injection tests | Sections 3–5 | Re-run before each major release and on staging hardware |

## 8. Running the load test

```bash
# 1. A dedicated database (the generator refuses any other)
mysql -e "CREATE DATABASE pharma_network_load CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
DB_DATABASE=pharma_network_load php artisan migrate:fresh --seed --force
DB_DATABASE=pharma_network_load QUEUE_CONNECTION=sync php artisan perf:generate --products=500 --customers=500 --terminals=8 --days=60 --sales-per-day=40

# 2. The cluster (Ctrl+C stops it and its instances)
node tools/load/cluster.mjs --instances 8 --port 8300 --database pharma_network_load

# 3. Scenarios, then the per-route profile and the invariants
node tools/load/run.mjs --levels 1,4,8,16 --seconds 15
node tools/load/summarize.mjs
DB_DATABASE=pharma_network_load php artisan ops:consistency

# Optional: compare two checkouts on the same data
git worktree add ../pharm-scn-abtest <commit>   # link vendor/ and copy .env into it
bash tools/load/ab.sh ../pharm-scn-abtest . 2 "catalogue_search,customers" "1,8" 15
```

The cluster serves code with OPcache timestamps off, so restart it after changing code. Users of the load organization sign in with the password printed by `perf:generate`.
