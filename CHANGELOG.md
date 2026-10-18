# Changelog

## [Unreleased]

### 2026-10-19: Phase 2.3, procurement
- Purchase requisitions (submit, approve/reject, convert to per-supplier POs) and purchase orders (draft → pending approval → approved → sent → partially received → received → closed; reject, edit and resubmit; cancel only without receipts).
- Approval threshold and segregation of duties (`procurement.allow_self_approval` for single-person organizations); supplier price lists with validity and minimum quantities; expected date from supplier lead time.
- Goods receipts against POs with batch capture through `StockReceiptService`, over-receipt tolerance, and idempotency.
- Supplier invoices with three-way match (matched / variance / unmatched), re-matched under lock at approval, audited override, and duplicate supplier invoice protection. Supplier returns through the ledger.
- Supplier performance (on-time rate, lead time, fill rate) and purchase suggestions from reorder levels.
- `App\Support\Money` for exact half-up decimal arithmetic; `UnitConverter`; `StockQueries`.
- Demo purchase order with a partial goods receipt. 144 tests passing.

### 2026-10-19: Phase 2.2, inventory core
- Batches (expiry, quality and recall status, batch cost); append-only `inventory_movements` ledger; `inventory_balances` projection maintained only by `InventoryLedger` under ordered row locks with deadlock retry; negative stock blocked unless the organization allows it.
- FEFO allocation that excludes expired, recalled, quarantined and reserved stock, with an optional minimum remaining shelf life. Reservations (reserve, consume, release).
- Receipts with package-level conversion (Idempotency-Key required); adjustments and write-offs; transfers with FEFO dispatch, in-transit tracking and partial receipt; stock counts against live balances; bins.
- Batch quarantine, release and reject across warehouses (releases reservations; expired or recalled batches cannot be released).
- Stock levels, batch balances, ledger, expiring, low-stock and valuation endpoints; costs gated by `inventory.valuation.view`.
- Branch/warehouse access scopes enforced (`PUT /members/{id}/locations`).
- Idempotency middleware is now re-entrant (fixes a double claim when group and route both apply it).
- Two-connection locking test; synthetic demo batches with spread expiries. 133 tests passing.

### 2026-10-19: Phase 2.1, suppliers and customers
- Tenant-owned `suppliers`, `customers` and `party_contacts` with codes unique per organization, credit and payment terms, a customer location for the field force, and an optional link to a platform organization.
- API with search, filters, contacts (single primary) and audit; module-gated (`module:procurement|inventory`, `module:sales|pos|field_force`). Module middleware now accepts alternatives.
- New permissions `suppliers.*` / `customers.*`, mapped to the relevant system roles.
- `organization_products.preferred_supplier_id` now has a foreign key and is validated against the organization's own suppliers.
- Synthetic suppliers and customers in demo data.

### 2026-10-18: Phase 2.0, account security
- TOTP two-factor authentication (pragmarx/google2fa) with QR enrolment, replay protection, 8 single-use hashed recovery codes, web login challenge, self-service disable, and platform reset for lost devices.
- Email verification with signed links; self-service registration endpoint.
- Security policy (`config/security.php` + runtime admin setting). **Two-factor and email verification are OFF by default** and can be switched on in production without a redeploy. Organizations may require two-factor for their members when the platform allows it.
- Account security web page; tests for the password-reset flow.

### 2026-10-18: Phase 1 completion against the full spec (§31)
- Subscription and entitlement foundations: plans, plan entitlements, organization subscriptions (with history), overrides, `EntitlementService::guard()` with row locking; enforcement for users (including pending invitations), branches, warehouses, storage and modules; tenant and admin billing endpoints.
- Domain events after commit (`OrganizationRegistered`, `OrganizationVerificationChanged`, `ProductCreated`, `ProductUpdated`, `RegulatoryIdentifierUpdated`).
- Health readiness endpoint, operations status, failed-job listing and retry (no payloads), admin user directory, country configuration endpoint, JSON log channel.
- CI workflow (MySQL 8, Pint, tests, audits).
- Synthetic demo data per §32: 7 organizations across all chain roles, role users, branches and warehouses, 5 products with `DEMO-` NAFDAC identifiers. Synthetic identifiers can never be marked verified and are rejected in production.
- Docs re-aligned to official phases 0–10; module dependency map, forward entity model, external-credentials inventory, backup/restore and rollback procedures; ADR-0007.

### 2026-10-18: Phase 1 foundation and Product Master
- Laravel 13 modular monolith; Sanctum; Livewire 4; Tailwind 4.
- Multi-tenancy with fail-closed scope, tenant-aware jobs and files.
- Permission registry and all §5.2 roles.
- Onboarding, invitations, verification workflow.
- Product Master with NAFDAC/regulatory identifiers and governance.
- Audit log, idempotency, correlation IDs, security headers, branding, country configuration.
- Generated SQL schema; web login, dashboard, catalogue browser, verification queue.
