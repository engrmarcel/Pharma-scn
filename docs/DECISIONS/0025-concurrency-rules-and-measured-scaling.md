# ADR-0025: Concurrency rules and measured scaling (Phase 10a)

Status: accepted · 2026-10-23

## Context

Phase 10 asks for scaling "as justified by measurements". The first load test (docs/PERFORMANCE_AND_LOAD_TESTING.md) found that concurrent counter sales failed with deadlocks, that dashboards were the costliest reads, that the API rate limit ignored the plan, and that writes within one organization are serialized by the traceability chain head and the document-number counters.

## Decision

1. **Locking rules for new code.**
   - Lock an existing row before inserting it: `SELECT … FOR UPDATE`, and insert only if it is missing. Never `INSERT IGNORE` followed by a lock on a hot row: the shared lock taken on a duplicate deadlocks with a concurrent upgrade.
   - Do not lock rows that may not exist yet (gap locks). Let a unique index decide races: insert, and on a duplicate read the existing row.
   - Delete child rows by primary key, not by a parent-key range, inside hot transactions.
   - Lock stock balances in product order (then id).
   - A transaction made only of database work may be retried on deadlock (`DB::transaction($fn, 3)`, outermost transaction only). A transaction that calls an external service (a payment provider, a carrier) is not retried, because the call would repeat.
2. **Dashboards are cached per viewer** for a short time (default 60 s). The key holds the organization, the viewer, their permissions and their warehouse restrictions, so access changes apply immediately. Responses state `meta.generated_at`.
3. **The API rate limit follows the plan** (`api_requests_per_minute`), per user and organization, with a platform default and a ceiling for unlimited plans.
4. **Per-organization write serialization stays.** Gapless numbering and a single hash chain per organization serialize completions within one organization. The measured ceiling (about 3 completed sales per second on the test machine, estimated 20–30 in production) is above realistic demand. Number series and trace chains per store or till are the planned change if an organization approaches half that ceiling; they need their own ADR because they change the chain's verification and the numbering rules.
5. **Consistency is checked, not assumed.** `php artisan ops:consistency` verifies the stock ledger, reservations, journals, POS settlement, document-number uniqueness and every hash chain. It runs after load tests and restores, and can run on a schedule.

## Consequences

- Concurrent counter sales with up to 16 cashiers ran with no errors and every invariant intact.
- A dashboard can be up to a minute old; the response says when it was computed.
- Plans with a low API rate now refuse bursts that were previously allowed.
- The load-test tooling (`perf:generate`, `tools/load/`) and the request profiler (`PERF_PROFILE`) stay in the repository for later runs; neither is active in production.
