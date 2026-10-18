# Implementation status

Last updated: 2026-10-18 · Spec: `docs/source/MASTER_SPEC_v1.0.md` (phases per §31)

**Phase 0 ✅ · Phase 1 ✅ · Phase 2 in progress: Product Master ✅ (done early), 2.0 account security ✅, 2.1 suppliers & customers ✅; inventory, procurement, sales, POS and field force not started.**

Legend: ✅ implemented and covered by passing tests · 🟡 partial (gap stated) · 📐 designed only · ⬜ not started

## Verified environment (inspected 2026-10-17)

| Component | Found | Notes |
|---|---|---|
| Project | Was empty; Laravel initialized; `git init` done, **nothing committed** | |
| PHP / Composer | 8.3.33 / 2.10.3 | Composer at `C:\ProgramData\ComposerSetup\bin` (not on PATH) |
| Laravel / Livewire / Sanctum | 13.37 / 4.4 / 4.x | |
| Database | **MariaDB 10.4.32** (XAMPP) | Production target MySQL 8 (ADR-0002); CI is configured for MySQL 8 |
| Redis | not installed | Database drivers used locally |
| Node / Flutter | 24.20 / 3.47.5 | Flutter unused until Phase 2.6 |

## Last verification run (2026-10-18)

- `php artisan test`: **117 tests, 567 assertions, all passing** (MariaDB `pharma_network_test`).
- `vendor/bin/pint --test`: passing. `npm run build`: succeeds.
- `php artisan migrate:fresh --seed`: succeeds, loading 7 synthetic organizations, 24 users, 5 `DEMO-` identifiers and 7 trial subscriptions.
- `database/sql/pharmaceutical_supply_chain_schema.sql` regenerated. It imports cleanly into an empty database (49 tables, 63 foreign keys), and `SchemaSnapshotTest` confirms it matches the migrations.
- **CI (GitHub Actions run #1, commit ba206f7): passed.** Pint, full test suite on MySQL 8.0, asset build, `composer audit` and `npm audit` all green: https://github.com/engrmarcel/Pharma-scn/actions/runs/39052574988

## Phase 1 acceptance (§31)

| Criterion | Evidence |
|---|---|
| Organizations can be created and managed | `OrganizationOnboardingTest` (register, verify, suspend, modules) |
| Authorized users invited and assigned roles | `AccessControlTest` (invitations, role changes, last-owner protection) |
| Tenant isolation tested | `TenantIsolationTest` (13 tests) plus per-module isolation cases |
| Privileged operations protected | Admin endpoint denials, conflict-of-interest guards, Livewire action re-authorization |
| Branding and country config not hard-coded | `BrandingAndAuditTest`; country admin endpoint (`PlatformOperationsTest`) |
| Migrations and initial tests pass | See verification run |

## Capability detail

| Capability | Status | Notes |
|---|---|---|
| Branding (platform + permitted organization keys) | ✅ | |
| Token auth, device revoke, logout; web login; throttling | ✅ | |
| Password reset | ✅ | Revokes all tokens; no account enumeration |
| Email verification, two-factor (TOTP + recovery codes) | ✅ | **OFF by default**; switchable via env or `PUT /admin/security-settings`; organization-level requirement; platform reset for lost devices |
| Self-service registration | ✅ | `POST /auth/register` |
| Account suspension (revokes tokens and sessions) | ✅ | |
| Multi-tenancy (fail-closed scope, resolution, jobs, files) | ✅ | |
| RBAC: registry, all §5.2 roles, organization-scoped evaluation | ✅ | Custom-role CRUD endpoints not built (model supports it) |
| Branch/warehouse access scopes | 🟡 | Model only; enforced from Phase 2.2 |
| Onboarding, invitations, verification workflow | ✅ | |
| Subscription and entitlement foundations | ✅ | Plans, entitlements, overrides, enforcement for users, branches, warehouses, storage and modules. Invoicing, renewals and payment collection come later (§21.4) |
| Country configuration | ✅ | NG active; GH, KE, ZA, RW, EG configured and inactive |
| Platform admin (API) | ✅ | Organizations, verification, users, catalogue, plans, subscriptions, countries, failed jobs, ops status, audit |
| Platform admin (web UI) | 🟡 | Verification queue and catalogue browser only |
| Audit infrastructure | ✅ | |
| API foundations (versioning, errors, idempotency, correlation, rate limits) | ✅ | OpenAPI document not yet produced |
| Domain events (after-commit) | ✅ | 5 events so far; outbox in Phase 5 |
| Logging and health checks | ✅ | JSON log channel, `/api/v1/health`, ops status |
| CI workflow | ✅ | GitHub Actions on MySQL 8; passing |
| Upload malware scanning | ⬜ | `scan_status` column only |
| Synthetic demo data (§32, Phase 1 portion) | ✅ | Batches, suppliers, customers, POS and orders come with Phase 2 |
| Suppliers and customers with contacts (2.1) | ✅ | Tenant-scoped; optional link to a platform organization (no data access implied); module-gated; sales-rep territory narrowing arrives in 2.6 |
| **Product Master (Phase 2 scope, done early)** | ✅ | Products, ingredients, package hierarchy, GTIN, classifications, NAFDAC/regulatory identifiers with evidence, governance, tenant mappings |

## Known limitations and risks

1. MariaDB 10.4 locally versus MySQL 8 in production. CI runs the suite on MySQL 8 on every push.
2. Regulator directory URLs and verification document requirements (only `business_registration` for now) need compliance review.
3. No integration with NAFDAC exists. Verification is manual and evidenced.
4. Seeded plan prices are NULL until the commercial team sets them.
5. During earlier testing, a too-broad cleanup command may have stopped another local project's `php artisan serve` process.
