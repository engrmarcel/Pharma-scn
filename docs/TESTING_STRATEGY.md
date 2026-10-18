# Testing strategy

## Layers

| Layer | Tooling | Purpose |
|---|---|---|
| Unit | PHPUnit (`tests/Unit`) | Pure logic: GTIN check digits, package hierarchy math |
| Feature / API | PHPUnit + Laravel HTTP testing (`tests/Feature`) | Endpoints end to end against a real MariaDB/MySQL test database: validation, permissions, persistence, audit |
| Isolation | `TenantIsolationTest` + tenant cases in every module | Cross-tenant reads, writes, bindings, validation rules, jobs |
| UI | Livewire component tests (`WebInterfaceTest`) | Components render and **actions re-authorize** |
| Schema | `SchemaSnapshotTest` | The SQL schema file matches the migrations |
| Static | Laravel Pint | Code style |

Tests run against **MariaDB/MySQL, not SQLite**, because row locking, decimals, foreign keys and JSON behaviour must match production. The test database is `pharma_network_test` (see `phpunit.xml`), and `TestingSeeder` loads reference data and the permission registry.

## Rules

- Every new tenant-owned model gets an isolation test: it is invisible across tenants, cannot be modified across tenants, and a client-supplied `organization_id` is ignored.
- Every endpoint gets at least: a happy path, a permission denial and a validation failure.
- State machines (verification, change requests, and later orders, shifts and recalls) are tested for every invalid transition.
- Money and quantity tests assert **exact decimal strings**.
- Concurrency-sensitive services (from Phase 1B: the inventory ledger) get tests that simulate competing allocations.

## Running

```bash
php artisan test                    # full suite
php artisan test --filter=TenantIsolation
vendor/bin/pint --test              # style
npm run build                       # asset build check
```

## CI (to configure when a remote repository exists)

On each push: `composer install`, `npm ci && npm run build`, run MySQL 8 as a service, `php artisan test`, `vendor/bin/pint --test`, `composer audit`, `npm audit --omit=dev`.
