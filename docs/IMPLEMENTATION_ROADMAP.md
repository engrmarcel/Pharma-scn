# Implementation roadmap

Phases and acceptance criteria follow master spec §31 (`docs/source/MASTER_SPEC_v1.0.md`). A phase is complete only when the §35 definition of done is met, with evidence in `IMPLEMENTATION_STATUS.md`. Foundations for later phases may be built early (§31), but no phase is claimed complete ahead of its acceptance criteria.

| Phase | Scope (§31) | Status |
|---|---|---|
| **0** Discovery & architecture | Environment audit, docs, module boundaries, entity model, tenancy, API and DB conventions, test strategy, dev workflow, roadmap, external-credential inventory | ✅ |
| **1** Platform foundation | Skeleton, auth, organizations, tenancy, memberships, RBAC, verification, branches and warehouses, country config, branding, platform admin, audit, API foundations, **subscription and entitlement foundations**, logging and health, CI | ✅ (MFA and email verification carried into Phase 2 kickoff; see status) |
| **2** ERP + POS + Field force / Light CRM | Product Master incl. NAFDAC identifiers *(built early, done)*; ERP: inventory ledger, warehouse, batch and expiry, FEFO, procurement, suppliers, customers, sales, invoicing, finance foundations, transfers and adjustments; POS; Flutter field-force app | 🟡 Product Master done |
| **3** Offline-first POS & mobile sync | Encrypted local storage, offline sales, receipts, visits and orders, sync queue, idempotency, conflicts, reconciliation, device registration and revocation, offline payment states, 6-hour outage test | 📐 `OFFLINE_FIRST_DESIGN.md` |
| **4** B2B marketplace | Discovery, listings, relationships, RFQ, quotes, B2B orders, approvals, fulfilment, returns and disputes, payment status, marketplace fees, supplier verification | ⬜ |
| **5** Enterprise integration gateway | Canonical model, connector framework, registry, mappings, REST, SFTP/file, webhooks, inbox/outbox, retries, monitoring, reconciliation, SAP adapter | 📐 `ENTERPRISE_INTEGRATIONS.md` |
| **6** Advanced CRM & field force | Segmentation, territories, visit planning, targets, opportunities, automation, dashboards, detailing, CRM analytics | ⬜ |
| **7** Traceability, recalls & logistics | Batch traceability, QR, serialization, custody, shipments, proof of delivery, recall, quarantine, exposure reports, cold chain | 📐 `TRACEABILITY_DESIGN.md` |
| **8** Analytics & AI | Dashboards, expiry risk, forecasting, AI assistant and recommendations (human-approved) | ⬜ |
| **9** Multi-country expansion | Currency, localization, tax rules, regulatory configs, local payment adapters, country onboarding | 🟡 data model ready |
| **10** Scale & hardening | Measured scaling, replicas, event streaming, DR, load tests | ⬜ |

## Phase 2 plan (next), in increments

Each increment ships migrations, services, API, permissions, tests and docs before the next one starts.

1. **2.0 Security carry-over ✅:** TOTP two-factor with recovery codes (modes off / optional / required_for_platform_staff / required_for_all, default **off**), organization-level requirement, email verification (default **off**), self-registration, password-reset test.
2. **2.1 Parties:** suppliers and customers (tenant), with contacts and credit terms.
3. **2.2 Inventory core:**
   - `batches` (lot, manufacturing and expiry dates, quality/quarantine/recall status, source);
   - `inventory_movements`, an append-only ledger in base units;
   - `inventory_balances`, a projection locked with `SELECT … FOR UPDATE`;
   - reservations, adjustments, transfers, bin locations, stock counts;
   - location-scoped access;
   - `FefoAllocator`, which excludes expired, recalled and quarantined batches;
   - no negative stock unless policy allows it;
   - events `BatchCreated`, `StockReceived`, `StockTransferred`, `InventoryAdjusted`.
4. **2.3 Procurement:** requisition → approval → PO → GRN (batch capture) → supplier invoice and returns.
5. **2.4 Sales and finance:** quote → order → invoice → credit note and returns; payments and allocation; chart of accounts, balanced journal entries, financial periods; effective-dated tax rules.
6. **2.5 POS:** terminals, shifts and cash control, Livewire cashier UI (keyboard and scanner first), FEFO batch selection, split payments, receipts, held sales, returns with supervisor approval, payment provider adapters (verified callbacks, replay protection), and reports. Every POS operation runs through the ERP services from 2.2–2.4.
7. **2.6 Field force:**
   - API: territories, assignments, visits with truthful GPS evidence, follow-ups, mobile orders as drafts, then submitted, then server-validated.
   - Flutter Android app with a local SQLite model designed for Phase 3.

## Test coverage map for §29.2 mandatory scenarios

| # | Scenario | Covered by |
|---|---|---|
| 1 | Cross-tenant read | ✅ `TenantIsolationTest` |
| 12 | Subscription bypass via direct API | ✅ `SubscriptionEntitlementTest` |
| 15 | Unauthorized customer info (tenant level) | ✅ isolation tests; integration credentials in Phase 5 |
| 2, 3, 4, 13 | Refund approval, expired/recalled batch, atomic sale, oversell | Phase 2.2 / 2.5 |
| 5, 7, 8 | Duplicate sync, truthful GPS, offline order state | Phase 2.6 / 3 |
| 6 | Duplicate payment callbacks | Phase 2.5 |
| 9, 14 | Integration retries, failed jobs keep the original request | Phase 5 (failed-job listing and retry exist now) |
| 10, 11 | Recall exposure, immutable traceability | Phase 7 |
