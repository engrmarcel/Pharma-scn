# Implementation status

Last updated: 2026-10-18 · Spec: `docs/source/MASTER_SPEC_v1.0.md` (phases per §31)

**Phase 0 ✅ · Phase 1 ✅ · Phase 2 ✅ · Phase 3 in progress: 3a sync engine ✅; offline POS replay (3b) and the app (3c) next.**

Legend: ✅ implemented and covered by passing tests · 🟡 partial (gap stated) · 📐 designed only · ⬜ not started

## Verified environment (inspected 2026-10-17)

| Component | Found | Notes |
|---|---|---|
| Project | Was empty; Laravel initialized; `git init` done, **nothing committed** | |
| PHP / Composer | 8.3.33 / 2.10.3 | Composer at `C:\ProgramData\ComposerSetup\bin` (not on PATH) |
| Laravel / Livewire / Sanctum | 13.37 / 4.4 / 4.x | |
| Database | **MariaDB 10.4.32** (XAMPP) | Production target MySQL 8 (ADR-0002); CI is configured for MySQL 8 |
| Redis | not installed | Database drivers used locally |
| Node / Flutter | 24.20 / 3.47.5 | Flutter field app in `mobile/field_app` (Dart 3.13) |

## Last verification run (2026-10-18)

- `php artisan test`: **188 tests, 1557 assertions, all passing**
- `flutter test` (mobile/field_app): **10 passing**, plus 1 live-API end-to-end test (run locally against `php artisan serve` with demo data; skipped in CI) (MariaDB `pharma_network_test`).
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
| Branch/warehouse access scopes | ✅ | `PUT /members/{id}/locations`; enforced on all inventory reads and actions |
| Onboarding, invitations, verification workflow | ✅ | |
| Subscription and entitlement foundations | ✅ | Plans, entitlements, overrides, enforcement for users, branches, warehouses, storage and modules. Invoicing, renewals and payment collection come later (§21.4) |
| Country configuration | ✅ | NG active; GH, KE, ZA, RW, EG configured and inactive |
| Platform admin (API) | ✅ | Organizations, verification, users, catalogue, plans, subscriptions, countries, failed jobs, ops status, audit |
| Platform admin (web UI) | 🟡 | Verification queue and catalogue browser only |
| Tenant web UI | 🟡 | Dashboard (permission-aware statistic cards, 7-day sales trend, expiry watch), catalogue browser, account security and the POS register; other ERP screens are API-only so far |
| Web design system | ✅ | Navy/gold corporate theme (brand colours remain configurable), self-hosted Inter + Playfair Display, Heroicons, dropdown navigation with organization switcher, split-screen sign-in; verified at desktop and 390 px widths |
| Audit infrastructure | ✅ | |
| API foundations (versioning, errors, idempotency, correlation, rate limits) | ✅ | OpenAPI document not yet produced |
| Domain events (after-commit) | ✅ | 5 events so far; outbox in Phase 5 |
| Logging and health checks | ✅ | JSON log channel, `/api/v1/health`, ops status |
| CI workflow | ✅ | GitHub Actions on MySQL 8; passing |
| Upload malware scanning | ⬜ | `scan_status` column only |
| Synthetic demo data (§32, Phase 1 portion) | ✅ | Batches, suppliers, customers, POS and orders come with Phase 2 |
| POS cashier UI (2.5b) | ✅ | Livewire register at `/pos` (scanner-first search, cart priced by the server, hold/resume, checkout, cash with change, standalone-terminal card entry, cancel, close shift) and printable 72 mm receipts; tenant and module checks re-applied on every Livewire update |
| POS backend (2.5a) | ✅ | Terminals (entitlement-limited), shifts with drawer accounting, variance and independent review; carts with pricing, scanned-batch validation, prescription controls; FEFO reservation at checkout; cash, standalone-terminal (supervisor-confirmed) and provider payments (signed, idempotent webhooks; dev-only fake provider); atomic completion (stock, COGS, invoice, receipts); refunds with independent approval; cancellation; receipts; reports. No real payment-gateway adapter yet (credentials needed) |
| Sales (2.4b) | ✅ | Price lists (customer/branch/default), effective-dated tax rates by category, quotations, sales orders with discount and credit-limit approval, FEFO reservations, partial deliveries with COGS, invoices from delivered quantities, credit notes with quarantined-by-default returns, customer payments, receivables aging, customer statements |
| Finance foundations (2.4a) | ✅ | Chart of accounts (per org, by system key), monthly periods with close/reopen, balanced immutable journal with reversals, automatic postings from receipts, supplier invoices (GRNI/PPV/tax/AP), write-offs, counts, opening balances, supplier returns and payments; supplier payment allocation; trial balance, ledger, P&L, balance sheet, payables aging. Management books, not certified statutory accounts |
| Procurement (2.3) | ✅ | Requisitions with approval → POs (supplier price lists, threshold-based approval, segregation of duties) → goods receipts with batch capture and over-receipt tolerance → supplier invoices with three-way match and audited override → supplier returns; supplier performance; purchase suggestions. |
| Inventory core (2.2) | ✅ | Append-only ledger + locked balances, batches with expiry/quality/recall status, FEFO (min shelf life), reservations, receipts with package-level conversion, adjustments, transfers (in-transit, partial receipt), counts, bins, quarantine/release, expiry & low-stock reports, batch-cost valuation; two-connection locking test |
| Sync engine (3a) | ✅ | Device registration with device-bound sliding tokens; revocation enforced on every API request (one-time `device_revoked` with wipe flag, then locked out; device tokens cannot act for another organization); `POST /sync/batches` storing every envelope durably before processing, in order, with per-envelope acknowledgement (synced/conflict/rejected/retry_required) and idempotent replays (`duplicate`); field-force operations replayed through the same services and validation as online, referenced by client reference; refused transactions kept, reviewable and annotatable; reconciliation report; `devices.manage` and `sync.reconcile` permissions; ADR-0011 |
| Flutter field app (2.6b) | ✅ | `mobile/field_app` (Flutter 3.47, Android first). Covers sign-in (token in Keystore/Keychain), organization choice, assigned-customer directory, one-shot location at check-in/out (status recorded, never guessed), visit log with activities and follow-up date, customer-priced catalogue with stock age, draft → queued → ERP-decided orders with unambiguous status wording, follow-ups, activity report, and a needs-attention list. Data lives in a local SQLite database with an ordered outbox and `client_reference` idempotency. Verified by `flutter analyze` (clean), 10 unit/widget tests, an end-to-end test against the live demo-seeded API (sign-in → verified visit → order accepted as a confirmed sales order), and a debug APK build. Not done: iOS build (needs macOS), local database encryption, and background sync (Phase 3) |
| Field-force API (2.6a) | ✅ | Territories with representatives, supervisors and a fulfilment warehouse; customer assignments that narrow representatives (`customers.view_assigned`) across customers, quotations, orders, invoices and statements; visits with server-classified location evidence (verified, outside radius, low accuracy, mock, no location, customer location unknown), clock checks, one open visit per representative, immutable evidence; activities; follow-ups; mobile orders validated into ERP sales orders (accepted = confirmed or held for approval; rejected with reasons and nothing left behind); customer-priced catalogue with a stamped stock snapshot; supervisor activity report; `mobile_users` limit; daily location purge; replay-safe `client_reference`; demo territories, visits and an accepted mobile order |
| Suppliers and customers with contacts (2.1) | ✅ | Tenant-scoped; optional link to a platform organization (no data access implied); module-gated; representatives see only assigned customers (2.6a) |
| **Product Master (Phase 2 scope, done early)** | ✅ | Products, ingredients, package hierarchy, GTIN, classifications, NAFDAC/regulatory identifiers with evidence, governance, tenant mappings |

## Known limitations and risks

1. MariaDB 10.4 locally versus MySQL 8 in production. CI runs the suite on MySQL 8 on every push.
2. Regulator directory URLs and verification document requirements (only `business_registration` for now) need compliance review.
3. No integration with NAFDAC exists. Verification is manual and evidenced.
4. Seeded plan prices are NULL until the commercial team sets them.
5. During earlier testing, a too-broad cleanup command may have stopped another local project's `php artisan serve` process.
