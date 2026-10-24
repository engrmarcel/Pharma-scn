# Setup and installation

This guide takes the platform from a fresh server to a running installation: requirements, installation, configuration, background processes, the mobile app and the release procedure.

## 1. What you are installing

| Part | Technology | Notes |
|---|---|---|
| Web application and REST API | Laravel 13 (PHP 8.3+), Livewire 4, Tailwind 4 | One codebase serves the web screens, `/api/v1` and the integration gateway |
| Database | MySQL 8.0+ (production) | MariaDB 10.4 (XAMPP) is for local development only |
| Cache, queues, sessions | Redis 7 | Database drivers work for small installations |
| File storage | S3-compatible object storage (production), local disk (development) | Organization documents, landing page media, proofs of delivery |
| Background work | `queue:work` workers and the scheduler | Notifications, webhooks, integrations, analytics |
| Field force app | Flutter (Android first) in `mobile/field_app` | Offline-first, encrypted local database |
| Desktop POS terminal | Web (full-screen page at `/pos`) in Chrome or Edge on the counter computer | Needs a connection; a USB barcode scanner works as a keyboard; set the receipt printer as the browser's default printer |

## 2. Requirements

| Component | Minimum |
|---|---|
| PHP | 8.3 with `bcmath`, `ctype`, `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `sodium`, `zip` |
| Composer | 2.x |
| Node.js | 20+ (asset build only) |
| MySQL | 8.0+, `utf8mb4` |
| Redis | 7 (production) |
| Web server | Nginx or Apache with PHP-FPM; HTTPS |
| Flutter | 3.x with the Android SDK (to build the field app) |

Shared hosting is not suitable: the platform needs queue workers, a scheduler, Redis and private object storage.

## 3. Install

```bash
git clone https://github.com/engrmarcel/Pharma-scn.git pharma-network
cd pharma-network
composer install --no-dev --optimize-autoloader      # omit --no-dev on a development machine
cp .env.example .env
php artisan key:generate
# create the database (utf8mb4) and set DB_* in .env, then:
php artisan migrate --force
php artisan db:seed --class=ReferenceDataSeeder --force       # countries, regulators, units, tax rates
php artisan db:seed --class=RolesAndPermissionsSeeder --force # permissions and system roles
php artisan db:seed --class=SubscriptionPlanSeeder --force    # plans and entitlements
npm ci && npm run build
php artisan storage:link        # only if you serve files from the public disk
php artisan optimize
```

On a development machine `php artisan migrate --seed` also loads the **synthetic demo data** (organizations, users, stock). Every demo user has the password `Password123!`, for example `admin@demo.test` (platform super administrator) and `owner.distributor@demo.test`. The demo seeder refuses to run in production.

### Create the first platform administrator (production)

```bash
php artisan tinker
>>> $u = App\Models\User::create(['name' => 'Platform Admin', 'email' => 'admin@your-domain', 'password' => bcrypt('a-long-unique-password')]);
>>> $u->platformRoles()->attach(App\Domain\Access\Models\Role::query()->platform()->where('code', 'platform_super_admin')->first());
```

Sign in at `/login` and enrol two-factor authentication on the **Security** page before switching it on for staff (see the Administration guide).

## 4. Configure (`.env`)

| Group | Keys | Notes |
|---|---|---|
| Application | `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` | `APP_URL` must be the public HTTPS address |
| Database | `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | |
| Cache, queue, session | `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `REDIS_*` | |
| Mail | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | Needed for invitations, password resets, recall notices and alerts |
| Files | `ATTACHMENTS_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` | Private bucket with server-side encryption |
| Logging | `LOG_CHANNEL=json`, `LOG_LEVEL=info` | Every record carries a `correlation_id` |
| Security | `SECURITY_MFA_MODE` (`off`, `optional`, `required_for_platform_staff`, `required_for_all`), `SECURITY_EMAIL_VERIFICATION_REQUIRED` | Both off at installation; can also be switched at runtime by an administrator |
| Field force | `FIELD_FORCE_VERIFICATION_RADIUS_M`, `FIELD_FORCE_MAX_ACCURACY_M`, `FIELD_FORCE_LOCATION_RETENTION_DAYS`, … | Check-in verification and GPS retention |
| Offline sync | `SYNC_MAX_BATCH`, `SYNC_DEVICE_TOKEN_TTL_HOURS`, `SYNC_MAX_PROCESSING_ATTEMPTS` | |
| Analytics | `ANALYTICS_DB_CONNECTION` (optional read replica) | |
| Reporting assistant | `ANALYTICS_AI_PROVIDER=none` or `anthropic`, `ANTHROPIC_API_KEY`, `ANALYTICS_AI_MODEL` | External AI also needs each organization's consent |
| Regions | `PLATFORM_REGION`, `PLATFORM_REGION_ENFORCE`, `PLATFORM_REGION_URLS` | Leave enforcement off for a single deployment |
| Payments | `PAYMENTS_FAKE_ENABLED` | The development payment provider can never run in production |

After changing `.env` in production run `php artisan optimize`.

## 5. Background processes

Run these under Supervisor or systemd and restart them on every release.

```ini
[program:pharma-queue]
command=php /srv/pharma-network/artisan queue:work --sleep=2 --tries=3 --max-time=3600
numprocs=2
autorestart=true

[program:pharma-scheduler]
command=php /srv/pharma-network/artisan schedule:work
numprocs=1
autorestart=true
```

Run the scheduler on **one** node only. It starts:

| Time | Command | Purpose |
|---|---|---|
| Every minute | `integration:process-due` | Retries of integration messages |
| Every 5 minutes | `integration:sftp-poll` | File exchange with connected systems |
| 01:30 | `analytics:refresh` | Daily demand figures |
| 02:00 | `analytics:recommend` | Reorder, expiry and anomaly recommendations |
| 02:30 | `field-force:purge-locations` | GPS retention |
| 02:45 | `analytics:purge-assistant-log` | Assistant log retention |
| 05:30 | `crm:automation` | CRM follow-ups and escalations |

## 6. Web server

Point the document root at `public/`. Example Nginx block:

```nginx
server {
    listen 443 ssl http2;
    server_name network.example.com;
    root /srv/pharma-network/public;
    client_max_body_size 25m;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php8.3-fpm.sock; fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; }
}
```

Health checks: `GET /up` (alive) and `GET /api/v1/health` (database, cache and storage; returns 503 when degraded).

## 7. Field force mobile app

```bash
cd mobile/field_app
flutter pub get
flutter build apk --release --dart-define=API_BASE_URL=https://network.example.com/api/v1
```

The APK is in `build/app/outputs/flutter-apk/`. Representatives sign in with their platform account; the device registers itself with the organization and keeps an encrypted local database. iOS builds need a Mac with Xcode.

## 8. Release procedure

```bash
php artisan down                      # only when a migration needs it
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force           # never migrate:fresh in production
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=ReferenceDataSeeder --force
php artisan optimize
php artisan queue:restart
php artisan up
```

Migrations are additive, so the previous release can run against a newer schema: to roll back, switch to the previous release and restart the workers.

## 9. Backups and monitoring

- Database: daily encrypted snapshots with point-in-time recovery, kept at least 35 days; a weekly logical dump to a separate account.
- Object storage: versioning and replication.
- Test a restore every quarter.
- Alert on: failing readiness check, growing failed jobs (`GET /api/v1/admin/operations/status`), oldest pending job older than 5 minutes, 5xx rate above 1 %.

## 10. Verify the installation

1. `GET /api/v1/health` returns `{"status":"ok"}`.
2. Sign in at `/login` as the platform administrator.
3. Open **Administration → Documentation** and download the PDF manuals.
4. Register a test organization, upload a document and submit it for verification.
5. Check that the invitation e-mail arrives (mail and queue are working).
