# Deployment guide

## Local development (this machine: XAMPP on Windows)

```bash
# prerequisites: PHP 8.3+, Composer, Node 20+, MariaDB/MySQL running (XAMPP)
composer install
cp .env.example .env && php artisan key:generate
# create databases (utf8mb4): pharma_network, pharma_network_test
php artisan migrate --seed       # reference data, permissions and demo users (password: Password123!)
npm install && npm run build     # or: npm run dev
php artisan serve                # http://127.0.0.1:8000  (web: /login, API: /api/v1)
php artisan queue:work           # notifications are queued
```

Demo data (local only, all SYNTHETIC, password `Password123!`):
- **Platform staff:** `admin@demo.test`, `compliance@demo.test`, `ops@demo.test`.
- **Organization owners:** `owner.<org>@demo.test`, where `<org>` is `manufacturer`, `importer`, `distributor`, `wholesaler`, `pharmacy1`, `pharmacy2` or `hospital`.
- **Role users**, for example `ph1.cashier@demo.test`, `dist.rep1@demo.test`, `hosp.auditor@demo.test`. The full list is in `DemoSeeder`. Set `MYSQLDUMP_PATH=C:/xampp/mysql/bin/mysqldump.exe` for `php artisan schema:export-sql`.

XAMPP is a development convenience only. Its MariaDB 10.4 is end-of-life and is **not** a production target (ADR-0002).

## Production target

| Component | Requirement |
|---|---|
| OS / runtime | Linux, PHP 8.3+ FPM, Nginx (or a load balancer in front of several app nodes) |
| Database | **MySQL 8.0+** (managed preferred), utf8mb4, automated encrypted backups, point-in-time recovery |
| Cache / queue / sessions | Redis 7 (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`) |
| Workers | Supervisor- or systemd-managed `php artisan queue:work`, separate from web nodes; `php artisan schedule:work` (or cron) on exactly one node |
| Files | S3-compatible object storage, private bucket (`ATTACHMENTS_DISK=s3`), server-side encryption |
| TLS | HTTPS only; HSTS is sent automatically on secure requests |
| Logs / monitoring | Centralized logs (JSON); uptime checks on `/up`; alerts on queue backlog, failed jobs, 5xx rate |
| Secrets | Host secret manager injects env vars; `.env` never committed |

Settings: `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=json` (structured JSON to stderr; every record carries `correlation_id`), `LOG_LEVEL=info`.

### Health and monitoring
- `GET /up` is the liveness check (framework).
- `GET /api/v1/health` is the readiness check (database, cache and storage, returning 503 when degraded). Point the load balancer at it.
- `GET /api/v1/admin/operations/status` (staff) reports queue backlog, the age of the oldest pending job, failed jobs and review queues.
- Alert on: readiness failing, failed jobs > 0 and growing, oldest pending job > 5 min, 5xx rate > 1%, and backup job failure.

### Why basic shared hosting is unsuitable (spec §33)
The platform needs long-running queue workers and a scheduler, Redis, private object storage, TLS control, and the ability to run several app instances behind a load balancer. Shared hosting typically allows none of these reliably, so notifications, integrations and offline sync would silently stall. It is acceptable only for a throwaway prototype.

### Backups and restore
- **Database:** use managed automated daily snapshots plus binlog/PITR, retained ≥ 35 days and encrypted. Run a logical dump weekly to a separate account.
- **Object storage:** turn on versioning and cross-region replication for the attachments bucket.
- **Restore drill (quarterly):** restore into a staging environment, run `php artisan migrate --pretend` (expecting no pending migrations), then `php artisan test --filter=SchemaSnapshot` against it, then spot-check the audit log and attachments. Record the measured RTO and RPO.

### Rollback
Deploys are immutable releases (symlinked release directories or images). To roll back, switch to the previous release and run `php artisan queue:restart`. Migrations are additive (see DATABASE_DESIGN), so the previous release can run against the newer schema. Destructive schema changes are split across releases.

## CI

`.github/workflows/ci.yml` runs Pint, the full test suite on **MySQL 8**, an asset build, and `composer audit` / `npm audit`. It has not been executed yet because the repository has no remote; push it to GitHub to activate it.

## External credentials the owner must configure

| Service | Needed for | Phase | Env / config |
|---|---|---|---|
| SMTP or transactional email provider | Invitations, password reset | 1 | `MAIL_*` |
| Object storage (S3-compatible) | Documents in production | 1 | `AWS_*`, `ATTACHMENTS_DISK=s3` |
| Redis | Cache, queue, sessions | 1 | `REDIS_*` |
| Malware scanner (ClamAV or cloud) | Upload scanning | 1–2 | TBD |
| Payment providers (e.g. Paystack, Flutterwave, Moniepoint, card acquirer) | POS electronic payments, subscription billing | 2 | Per-adapter keys and webhook secrets; development uses a clearly labelled sandbox/fake adapter |
| SMS / WhatsApp provider | OTP, recall notifications | 2, 7 | TBD |
| Maps / geocoding | Field-force addresses (optional) | 2 | TBD |
| Firebase Cloud Messaging / APNs | Mobile push | 2–3 | TBD |
| Google Play / Apple developer accounts | Mobile distribution | 2 | — |
| Customer ERP access (SAP, Oracle, Dynamics) | Integrations, configured per customer | 5 | Stored encrypted per connector |
| SFTP endpoints | File integrations | 5 | Per connector |
| NAFDAC / GS1 interfaces | Regulator lookups, GS1 data, if and where officially available | 5, 7 | Requires an official agreement; none is assumed |
| AI provider | Phase 8 assistant (only with data-processing agreement) | 8 | TBD |

## Release procedure

```bash
php artisan down --render="errors::503"   # only if the migration needs it
git pull && composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force               # never migrate:fresh / db:wipe in production
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # syncs permission registry
php artisan db:seed --class=ReferenceDataSeeder --force         # idempotent reference data
php artisan optimize
php artisan queue:restart
php artisan up
```

The `DemoSeeder` refuses to run in production, and `DatabaseSeeder` skips it there.

## Power and connectivity (spec §13.7)

Field and pharmacy device requirements will be added with Phase 2/3 (see `OFFLINE_FIRST_DESIGN.md`). The server side assumes nothing about client connectivity.
