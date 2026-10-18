# Changelog

## [Unreleased]

### 2026-10-21: Phase 2.6b, Flutter field app (Phase 2 complete)
- `mobile/field_app` (Flutter 3.47, Dart 3.13, Android first, iOS runner included).
- Sign-in with the API token in Keystore/Keychain; organization choice validated against `/field-force/me`; one local database per user and organization.
- Assigned-customer directory, cleared of customers that are no longer assigned; customer-priced catalogue per pack, with the age of the stock snapshot shown.
- Visits: one-shot location at check-in and check-out (permission, service and timeout states recorded; coordinates never guessed); notes, feedback, permission-gated competitor information, activities and follow-up date; server-decided verification shown after sync; first-use location notice.
- Orders: draft on device → waiting to sync → submitted → accepted by ERP (sales order number, stock reserved or awaiting approval) or rejected with the reason; edit and resubmit.
- Durable ordered outbox: offline work survives restarts. Connection and server errors retry later; refusals are listed under "Needs attention" together with the steps that depend on them. Replays are idempotent through `client_reference`.
- Follow-ups (offline completion), activity report for representatives and supervisors.
- Tests: 10 unit/widget tests (SQLite via FFI), a live-API end-to-end test, and a new CI job (analyze + test). The debug APK builds locally.

### 2026-10-21: Phase 2.6a, field-force API
- Territories (code, parent, fulfilment warehouse) with representative and supervisor members (limited by the `mobile_users` entitlement) and customer assignments (territory and/or representative).
- New permission `customers.view_assigned` (sales representatives, new `field_supervisor` role): customers, quotations, sales orders, invoices and statements are limited to assigned customers; hidden records return 404; organization-wide receivables aging is closed to them.
- Visits: check-in and check-out with device-reported location status, coordinates, accuracy, mock flag and device context; the server classifies the evidence (verified within the radius, outside radius, low accuracy, mock location, no location, customer location unknown), refuses contradictory reports and implausible clocks, and never edits evidence afterwards. Visit details, activities, competitor information (permission-gated), and automatic follow-ups from the follow-up date.
- Mobile orders: draft, then submit, then accepted (the ERP sales order is created and confirmed, or held for approval) or rejected with reasons inside a savepoint (no sales order or reservation left behind); edit and resubmit; indicative device prices never override ERP pricing.
- Customer-priced mobile catalogue (per sellable pack) with a stamped stock snapshot; customer directory with location-verification and visit history; follow-up tasks; supervisor activity report (visits, verification, orders, accepted value, follow-up compliance).
- Replay-safe device `client_reference` on visits, orders and follow-ups. Daily `field-force:purge-locations` removes raw coordinates after the retention period. ADR-0010.
- Synthetic demo: two distributor territories, a supervisor, a verified visit with an accepted mobile order, and a visit recorded with location permission denied. 184 tests passing.

### 2026-10-20: Web interface redesign
- Corporate navy and gold design system (Tailwind 4 tokens and components); brand colours still come from `BrandingService`, with new defaults `#0b4ea2` / `#0b1f42`. Inter and Playfair Display are self-hosted through `@fontsource` (no third-party font requests). Icons from `blade-ui-kit/blade-heroicons`.
- Header with a utility bar (support contacts, date, non-production badge, current organization), sticky main bar and hover/click/keyboard dropdown menus (Operations, Organization switcher, Administration, account menu), plus a mobile menu. Page banners with breadcrumbs; a multi-column footer.
- Split-screen sign-in with an animated supply-network illustration, icon inputs, show/hide password and a busy state; matching two-factor challenge page.
- Dashboard: greeting hero with organization summary, statistic cards with icons and count-up figures (stock, expiry, expired, low stock, stock value, purchase and sales orders, receivables, POS sales today), 7-day sales trend, expiring-soon list and platform oversight cards. Each figure appears only when the module is enabled and entitled and the member holds the view permission; stock figures respect warehouse scopes; money is shown in the organization currency only.
- `ResolveTenant` gains an optional mode (`tenant:optional`) used by the dashboard: a missing, foreign or suspended selection shows no figures instead of an error.
- Reduced-motion support; 177 tests passing.

### 2026-10-20: Phase 2.5b, POS cashier UI
- Livewire register (`/pos`): open shift, scanner-first search (SKU, GTIN or batch number + Enter adds an exact match), server-priced cart with quantities, prescription indicator, hold and resume, checkout, cash with change, standalone card-terminal entry (pending until confirmed), cancel, close shift.
- Printable 72 mm receipt (`/pos/receipts/{sale}`) with batch numbers and expiry, no prescription details.
- Tenant pages in the web app: organization from the switcher, re-validated by `ResolveTenant`; Livewire persistent middleware re-applies tenant, module and account-security checks on component updates.
- `PosCatalogue` lookup shared by the API and the register. 174 tests passing.

### 2026-10-20: Phase 2.5a, POS backend
- Terminals (limited by the `pos_terminals` entitlement); shifts with opening float, signed drawer movements, supervisor-only cash in/out, close with expected/counted/variance, and independent review.
- Sales: idempotent start by `client_transaction_id`, cart pricing (override and discount controls), scanned-batch validation, prescription-only and controlled dispensing controls, hold, checkout with FEFO or scanned-batch reservation, cancel (cash returned, reservations released).
- Payments: cash at the till; standalone terminals confirmed by a different user after settlement; provider payments confirmed only by verified, idempotent webhooks (`payment_provider_events`); a development-only fake provider. ADR-0009.
- Atomic completion through ERP services: stock out with batch evidence, COGS, `pos` invoice, receipts allocated to it.
- Refunds with independent approval, credit to the exact invoice lines, return to the original batches (quarantined by default), cash out of the open drawer or by bank transfer.
- Receipts (batch numbers and expiry, no prescription details) and POS reports (summary with gross margin, by product, cashier, payment method, terminal, refunds, shifts, batches).
- Demo terminal, shift and cash sale. 170 tests passing.

### 2026-10-19: Phase 2.4b, sales
- Price lists (customer, branch, default) with quantity breaks and validity; catalogue default price fallback; price overrides gated by `sales.price.override`.
- Effective-dated tax rates per country and category (platform configuration), with a country default category. Seeded NG categories (EXEMPT, ZERO, VAT_STANDARD 7.5% from 2020-02-01) are flagged for professional confirmation.
- Quotations (expiry; quoted prices honoured), sales orders (discount and credit-limit approval with segregation of duties, FEFO reservations per line), partial deliveries (`ReservationService::consumeQuantity`) with cost of goods sold, invoicing of delivered quantities, credit notes with returns (quarantined by default) and cost reversal, customer payments and allocation, receivables aging, customer statements.
- Fix: `ReferenceDataSeeder` no longer overwrites admin-edited country activation or settings on redeploy (it only adds missing defaults).
- Demo sales order through invoice. 159 tests passing.

### 2026-10-19: Phase 2.4a, finance foundations
- Chart of accounts per organization (template keyed by `system_key`), financial periods (opened monthly on demand; close/reopen with reason).
- `GeneralLedger`: balanced, immutable journal entries with reversals; closed periods refuse postings.
- `AccountingBridge` posts inside the document transaction: goods receipts, supplier invoices (GRNI clearing, price variance, input tax, other charges), write-offs and counts, opening-balance and other direct receipts (`DirectReceiptService`), supplier returns and supplier payments.
- Supplier payments with allocation to approved invoices (balance and currency checks, partial or paid status).
- Reports: trial balance, account ledger, profit and loss, balance sheet, payables aging.
- `finance` added to default modules for trading organization types; ADR-0008. 151 tests passing.

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
