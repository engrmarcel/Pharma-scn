# Pharmaceutical Supply Chain Network Platform

A multi-tenant pharmaceutical SaaS platform combining ERP, POS, a mobile field force, a B2B marketplace, traceability and enterprise integrations. It is built Nigeria-first and designed for other African markets. The product name is configurable (`config/platform.php`, or the admin branding API).

**Current state:** Phase 1 (platform foundation and Product Master) is implemented and tested. See [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md) for exactly what works and what does not, and [docs/IMPLEMENTATION_ROADMAP.md](docs/IMPLEMENTATION_ROADMAP.md) for what comes next.

## Quick start (local)

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# create MySQL/MariaDB databases: pharma_network and pharma_network_test (utf8mb4)
php artisan migrate --seed
npm run build
php artisan serve
php artisan test
```

Demo logins (local only, password `Password123!`): `admin@demo.test`, `compliance@demo.test`, `owner@demo.test`.

## Documentation

| | |
|---|---|
| Spec index | [PROJECT_MASTER_SPEC.md](PROJECT_MASTER_SPEC.md) |
| Requirements | [docs/PRODUCT_REQUIREMENTS.md](docs/PRODUCT_REQUIREMENTS.md) |
| Architecture | [docs/SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) |
| Database | [docs/DATABASE_DESIGN.md](docs/DATABASE_DESIGN.md) · [schema SQL](database/sql/pharmaceutical_supply_chain_schema.sql) |
| API | [docs/API_SPECIFICATION.md](docs/API_SPECIFICATION.md) |
| Security | [docs/SECURITY_AND_PRIVACY.md](docs/SECURITY_AND_PRIVACY.md) |
| Offline / integrations / traceability | [offline](docs/OFFLINE_FIRST_DESIGN.md) · [integrations](docs/ENTERPRISE_INTEGRATIONS.md) · [traceability](docs/TRACEABILITY_DESIGN.md) |
| Ops & QA | [deployment](docs/DEPLOYMENT_GUIDE.md) · [testing](docs/TESTING_STRATEGY.md) |
| Decisions | [docs/DECISIONS/](docs/DECISIONS/) |
| Changes | [CHANGELOG.md](CHANGELOG.md) |
