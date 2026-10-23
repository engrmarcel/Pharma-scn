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

### Switching on two-factor and email verification

Both are off after deployment. When you are ready:
1. Make sure mail delivery works (`MAIL_*`); verification links and resets depend on it.
2. A platform super admin enrols their own two-factor (web **Security** page or `POST /api/v1/auth/mfa/setup` + `/confirm`).
3. `PUT /api/v1/admin/security-settings` with `{"mfa_mode": "required_for_platform_staff"}` (or `optional` / `required_for_all`) and/or `{"email_verification_required": true}`. Alternatively set `SECURITY_MFA_MODE` / `SECURITY_EMAIL_VERIFICATION_REQUIRED` and run `php artisan config:cache`.
4. To roll back, set the mode back to `off` / `false`. No data is lost.

### Health and monitoring
- `GET /up` is the liveness check (framework).
- `GET /api/v1/health` is the readiness check (database, cache and storage, returning 503 when degraded). Point the load balancer at it.
- `GET /api/v1/admin/operations/status` (staff; web **Administration → Operations**) reports each queue's backlog and oldest job (through the queue API, so it is right for Redis and database queues), failed jobs, the scheduler and worker heartbeats, open alerts and review queues.
- **Built-in alerting (Phase 10b).** The scheduler runs `ops:heartbeat` and `ops:monitor` every minute. `ops:heartbeat` records that the scheduler is alive and sends a tiny job through each watched queue (`OPS_MONITORED_QUEUES`, default `default`); when a worker runs it, that queue's workers are known to be alive. `ops:monitor` compares the figures with the thresholds in `config/operations.php` (queue backlog and age, failed jobs in the last hour, scheduler and worker heartbeats, overdue and abandoned webhook deliveries, integration backlog and quarantine, offline-sync backlog, high-severity sync issues). Each problem opens one alert; platform staff with *operations* permission are e-mailed when it opens, escalates or clears, not every minute. Alert history: `GET /api/v1/admin/operations/alerts`. These e-mails are sent directly, not queued, so they still go out when the queue is the problem; they do depend on mail delivery and on the scheduler (an external probe should watch both, below).
- **Nightly consistency check.** `ops:consistency --alert` runs at 03:30 and opens a critical alert if stock no longer equals its ledger, stock is negative or over-reserved, a journal does not balance, a completed sale is unpaid, a document number repeats or a traceability chain fails verification. Run it by hand after a restore.
- **Prometheus.** Set `OPS_METRICS_TOKEN` and scrape `GET /api/v1/metrics` with `Authorization: Bearer <token>` (gauges named `pharma_*`: queue backlog, oldest job, heartbeat ages, failed jobs, webhook, integration and sync backlogs, open alerts). Without a token the endpoint returns 404. Platform-level figures only, no tenant data.
- **External checks** (the built-in alerting cannot report its own absence): probe `GET /api/v1/health` from outside and alert when it fails; alert when `pharma_scheduler_heartbeat_age_seconds` exceeds 300 or the scrape fails; and alert on 5xx rate > 1% and backup job failure.

### Why basic shared hosting is unsuitable (spec §33)
The platform needs long-running queue workers and a scheduler, Redis, private object storage, TLS control, and the ability to run several app instances behind a load balancer. Shared hosting typically allows none of these reliably, so notifications, integrations and offline sync would silently stall. It is acceptable only for a throwaway prototype.

### Backups and restore
- **Database:** use managed automated daily snapshots plus binlog/PITR, retained ≥ 35 days and encrypted. Run a logical dump weekly to a separate account.
- **Object storage:** turn on versioning and cross-region replication for the attachments bucket.
- **Independent logical backup (Phase 10c):** set `BACKUP_ENABLED=true`, a private `BACKUP_DISK` in another account, and the client paths (`MYSQLDUMP_PATH`, `MYSQL_CLIENT_PATH`) if they are not on PATH. The scheduler then runs `ops:backup` nightly (consistent snapshot, compressed, SHA-256, manifest of row counts and schema version; kept `BACKUP_RETENTION_DAYS`, at least 3).
- **Restore drill (monthly, automatic):** `ops:restore-drill` restores the newest backup into a throwaway `*_restore_drill` database, verifies checksum, tables and row counts, schema compatibility and the consistency invariants, records the measured recovery time and data age in `recovery_drills`, and raises an alert if it fails. The monitor alerts when the last good backup is over 26 hours old or the last passed drill over 100 days old. Once a quarter also drill the managed PITR restore and the attachments bucket on staging, and spot-check the audit log. Procedures and measured figures: DISASTER_RECOVERY.md.

### Rollback
Deploys are immutable releases (symlinked release directories or images). To roll back, switch to the previous release and run `php artisan queue:restart`. Migrations are additive (see DATABASE_DESIGN), so the previous release can run against the newer schema. Destructive schema changes are split across releases.

## CI

`.github/workflows/ci.yml` runs Pint, the full test suite on **MySQL 8**, an asset build, and `composer audit` / `npm audit`. It runs on every push to `main` and on pull requests (repository: https://github.com/engrmarcel/Pharma-scn).

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
