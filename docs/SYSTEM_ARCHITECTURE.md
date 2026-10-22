# System architecture

## Shape

A **modular monolith** (ADR-0001): one Laravel 13 application with explicit domain modules, versioned REST API, Livewire web UI, and queued jobs. It is deployed as stateless app instances plus separate queue workers.

```
app/
  Domain/
    Platform/       countries, regulators, reference data, settings, audit, attachments, branding
    Organizations/  organizations, memberships, invitations, branches, warehouses, verification
    Access/         permissions, roles, AccessControl (permission resolution)
    Catalogue/      Product Master, regulatory identifiers, governance, tenant product mappings
    Billing/        subscription plans, organization subscriptions, entitlement enforcement
  Http/
    Controllers/Api/V1/        tenant + authenticated endpoints
    Controllers/Api/V1/Admin/  platform-administration endpoints
    Controllers/Web/           session login, dashboard
    Middleware/                correlation id, tenant, module, idempotency, active user, headers
  Livewire/                    server-driven UI components
  Support/Tenancy/             TenantContext, TenantScope, BelongsToOrganization
  Jobs/Middleware/             TenantAware jobs
```

Rules between modules:
- **Services own writes.** Controllers validate input, authorize the request and call a service. Product records are written only by `ProductCatalogueService`, verification state only by `OrganizationVerificationService`, and later stock only by `InventoryLedger`. POS, the field-force app and integrations will call these same services, so business rules are never duplicated.
- **Domain errors** are `BusinessRuleViolation(code, message, status)`, which the API renders as `{message, error:{code, details}, correlation_id}`.

## Module dependency map

Arrows point from a module to the modules it may call. There are no reverse calls; reverse notification happens through domain events.

```
Platform  ◄──────────────── everything (audit, attachments, branding, countries, reference data)
Access    ◄──────────────── everything (permissions)
Organizations ──► Platform, Access, Billing
Billing   ──► Platform, Organizations
Catalogue ──► Platform, Organizations
── Phase 2+ ─────────────────────────────────────────────────────────────
Parties (suppliers/customers) ──► Organizations
Inventory (ledger, batches, FEFO) ──► Catalogue, Organizations
Procurement ──► Parties, Inventory, Finance
Sales ──► Parties, Inventory, Finance, Catalogue
Finance (ledger, payments) ──► Organizations
POS ──► Sales, Inventory, Finance            (no own stock/sales logic)
FieldForce ──► Parties, Catalogue, Sales      (orders become Sales orders after validation)
Sync (P3) ──► POS, FieldForce, Sales          (replays device transactions through their services)
Marketplace (P4) ──► Catalogue, Sales, Procurement, Organizations
Integrations (P5) ──► Catalogue, Inventory, Sales, Procurement, Traceability (via canonical mappers)
Traceability/Logistics (P7) ──► Inventory, Catalogue, Organizations
Analytics/AI (P8) ──► read models only; may *propose* actions to other modules, never execute them
```

## Domain events (spec §26)

- `App\Support\Events\DomainEvent` implements `ShouldDispatchAfterCommit`. Events fire only after the surrounding transaction commits, so a failing listener cannot undo a committed sale or edit, and a rolled-back change emits nothing (tested).
- Events carry identifiers, `occurredAt` and `correlationId`, never models or payloads.
- Implemented so far: `OrganizationRegistered`, `OrganizationVerificationChanged`, `ProductCreated`, `ProductUpdated`, `RegulatoryIdentifierUpdated`. The rest of the §26 list arrives with the modules that own them.
- These are internal events. Durable integration events will be written by listeners to a transactional `integration_outbox` in Phase 5. Consumers must be idempotent, keyed on event id.

## Request pipeline

```
AssignCorrelationId → (web: session/CSRF) → auth:sanctum|auth → EnsureUserIsActive
  → ResolveTenant (tenant routes) → [module:x] → [idempotent] → SubstituteBindings → controller
  → SecurityHeaders
```
`ResolveTenant` is placed ahead of `SubstituteBindings` in the middleware priority list (`bootstrap/app.php`), so route-bound tenant models are always loaded inside the tenant scope.

## Multi-tenancy (ADR-0003)

- The schema is shared, and tenant-owned tables carry `organization_id`.
- **`TenantContext`** is a scoped singleton holding the acting organization and membership. Only `ResolveTenant` (after checking for an active membership in an active organization) and `SetTenantContext` (job middleware) populate it.
- **`TenantScope`** filters every query on tenant models and **fails closed** (`1 = 0`) when no tenant is set. Cross-tenant reads need an explicit `Model::acrossTenants()`, used only behind platform permissions.
- **`BelongsToOrganization`** fills `organization_id` from the context on create. It refuses to create a record with no context, or for an organization other than the current one.
- Identity tables (`organization_memberships`, `roles`) are deliberately unscoped and are always queried with explicit organization and user filters.
- **Queued jobs:** jobs use the `TenantAware` trait to capture the tenant at dispatch, and their middleware restores it at execution.
- **Files** live under `tenants/{organization_id}/…` on a private disk and are served only through controllers that authorize the request.
- **Future isolation:** large enterprises can move to a dedicated database by giving `TenantContext` a connection resolver. Because all tenant data already carries `organization_id`, a per-tenant export is a filtered dump.

## Authorization

- `config/permissions.php` is the permission registry. Each permission has a scope:
  - **platform** permissions are held through platform roles;
  - **organization** permissions are held through membership roles and evaluated only against the current `TenantContext`.
- A `Gate::before` hook resolves registry abilities through `AccessControl`. All other abilities fall through to policies.
- **No super-user bypass.** A platform super admin has no access to tenant data unless they are a member of that organization.
- System roles are synced from config by `RolesAndPermissionsSeeder` on every deploy. Organizations may add custom roles built from organization-scope permissions only.

## Inventory integrity (spec §10.2–10.3, §13.4)

- **`InventoryLedger::post()`** is the only code that changes stock quantities. In one transaction it:
  - resolves batches and warehouses through the tenant scope;
  - creates any missing balance rows, then locks every touched row with `SELECT … FOR UPDATE` in sorted key order;
  - refuses negative stock (unless `inventory.allow_negative_stock`, and only for unreserved available stock) and refuses to dip below reserved quantities;
  - appends immutable movements.

  Deadlocks and lock timeouts are retried up to 3 times. `InventoryConcurrencyTest` shows a second database session holding the row blocks a competing posting, and that the last units cannot be sold twice.
- **`FefoAllocator`** picks the earliest-expiry sellable batches, with no-expiry batches last. Expired, recalled, quarantined, rejected and reserved stock is never allocated. Explicitly chosen batches go through `assertSellable()`.
- **Blocked stock never becomes sellable:**
  - quarantining a batch releases its reservations and moves all of its available stock to `quarantined`, in every warehouse;
  - receipts and adjustments of blocked batches cannot land in `available`;
  - transfers of a batch blocked in transit arrive quarantined.
- **Stock documents** (adjustments, transfers, counts) update their own rows and post to the ledger in the same transaction. Unreceived transfer quantity remains visible `in_transit`.
- **Warehouse access:** `LocationAccess` restricts reads and actions for members with `all_locations = false` to their assigned warehouses and branches.

## Procurement controls (spec §10.4)

- **Segregation of duties:** requisitions, purchase orders and supplier invoices must be approved by someone other than their creator. A single-person organization can turn on `procurement.allow_self_approval`, and the setting change is audited.
- **Approval threshold:** purchase orders below `procurement.approval_threshold` are auto-approved on submit, which is recorded in the audit log. With no threshold set, every order needs approval.
- **Goods receipts** go through `StockReceiptService`, so they obey the same catalogue, expiry, quarantine and ledger rules as any other receipt. Over-receipt is blocked beyond `over_receipt_tolerance_percent`.
- **Three-way match:** supplier invoices are compared with the order and the receipts, and re-matched under lock at approval. An invoice billing more than was received can only be approved with an explicit, audited override reason.

## POS (spec §11, ADR-0009)

- **No separate POS ledger.** A POS sale is a front end over the ERP: `PosSaleService` uses `SalesPricing`, `FefoAllocator`, `ReservationService`, `FulfilmentService`, `AccountingBridge` and `PaymentService`.
- **Sale lifecycle:**
  - The cart prices lines, validates scanned batches as sellable and applies prescription controls.
  - **Checkout** reserves stock first-expiry-first-out (or the scanned batch).
  - Payments are cash (confirmed at the till) or electronic (pending until a verified provider callback or a supervisor settlement check).
  - **Completion** is atomic: stock out with batch evidence, cost of goods sold, a `pos` invoice, and receipts allocated to it.
- **Shifts:** one open shift per terminal and per cashier. The drawer is the sum of signed cash movements: opening float, sales, cash in/out (supervisor only), refunds and cancellations. Closing records expected cash, counted cash and variance. A different user reviews the shift and must explain any variance.
- **Refunds:** a cashier requests; a different user with `pos.refund.approve` approves. The refund credits the exact invoice lines, returns goods to the batches they came from (quarantined unless restocked by an authorized user), and pays the money back from the terminal's open drawer or by bank transfer.
- **Prescription controls:** products classified prescription-only or controlled in the organization's country need `pos.dispense.prescription` plus a recorded prescription reference, prescriber and date. Receipts never show these details.

## Sales (spec §10.5)

- **Price resolution:** customer price list → branch price list → default price list → catalogue default selling price (per base unit). A price that differs from the resolved list price is an override and needs `sales.price.override`. Quoted prices are honoured on conversion.
- **Tax:** the catalogue item's tax category, or the country default category, gives the rate from effective-dated `tax_rates`. If no rate is configured the line is refused; no rate is ever assumed. Tax applies after discount. The seeded Nigerian values are editable configuration flagged for professional confirmation.
- **Confirming an order** reserves stock first-expiry-first-out per line, unless a discount exceeds `sales.max_discount_percent` or the customer's credit exposure would exceed their limit; then the order waits for approval by someone other than its creator.
- **Deliveries** consume reservations (partially if needed, earliest expiry first) and post cost of goods sold at batch cost. **Invoices** bill only delivered, uninvoiced quantities and post receivables, revenue and output tax.
- **Issued invoices are never edited.** Corrections are credit notes, which can return goods. Returned goods go to quarantine unless someone with `sales.returns.restock` restocks a sellable batch; restocked goods reverse their cost of goods sold. A credit cannot exceed the invoice's open balance; refunds of paid amounts arrive with POS refunds.
- **Customer payments** allocate to invoices. An unallocated amount stays as a credit on the receivable.

## Finance foundations (spec §10.6, ADR-0008)

- **`GeneralLedger::post()`** is the only writer of journal entries. Every entry balances at 4 decimals, each line is strictly a debit or a credit, and the entry lands in an open period (monthly periods open on demand). Entries are immutable; corrections are reversing entries.
- **`AccountingBridge`** posts operational documents inside their own transaction:

  | Document | Debit | Credit |
  |---|---|---|
  | Goods receipt | Inventory | Goods received not invoiced (GRNI) |
  | Supplier invoice | GRNI (at the cost used on receipt), purchase price variance, input tax, other charges | Accounts payable |
  | Write-offs and counts | Inventory adjustments (or Inventory, for gains) | Inventory (or Inventory adjustments) |
  | Opening-balance receipt | Inventory | Equity |
  | Supplier return | Returns awaiting credit | Inventory |
  | Supplier payment | Accounts payable | Cash / bank |

  A closed period therefore blocks the operational document as well, and the whole document is refused. Stock and books never diverge.
- **Accounts:** automatic postings find accounts by `system_key`, so an organization (or a country template) can rename or renumber the chart freely.
- **Always on:** the journal is maintained whether or not the `finance` module is enabled; the module only gates the finance API.

## Subscriptions and entitlements (spec §21, ADR-0007)

`EntitlementService::guard($org, 'branches', fn () => …)` wraps every limited creation. It checks the subscription state and plan or override limits under a row lock on the organization. The `module:x` middleware requires both platform enablement and a `module.x` entitlement. A lapsed subscription blocks new consumption but never hides or deletes existing records.

## Branding (spec §3)

`BrandingService::platform()` merges `config('platform.branding')` (env-driven defaults) with administrator-saved `platform_settings.branding`. `forOrganization()` adds organization overrides for an allow-listed set of keys only. Views, mail and receipts read from the service. The stable technical identifier is `config('platform.code') = pharma_network`.

## Country configuration (spec §20)

`countries` holds operating markets: currency, timezone, locale, phone code, `is_active` and JSON settings such as verification document requirements. Regulatory identifiers reference `regulatory_authorities` per jurisdiction rather than using country-specific columns. Tax and invoice rules will be added as effective-dated rule tables in Phase 1C. No Nigerian rule is hard-coded in shared logic.

## Reliability primitives

| Concern | Mechanism |
|---|---|
| Duplicate submissions | `Idempotency-Key` middleware, scoped by user and tenant; replays successful responses |
| Lost updates on shared records | Optimistic `version` on products; `lockForUpdate` in state-machine transitions |
| Traceability of requests | Correlation id in Laravel `Context`, which propagates to logs, the audit trail and queued jobs |
| Auditability | Append-only `audit_logs` with redaction |

## Extension points (spec §6.5, not built)

- **Event streaming (Kafka/Redpanda):** behind a domain-event outbox (Phase 5).
- **Search (OpenSearch):** behind a catalogue search interface once `LIKE` search is measured as insufficient.
- **Analytics store (ClickHouse or a warehouse):** fed from the outbox. Today analytics read through `AnalyticsDb` (optionally a read replica) plus a daily demand fact table (ADR-0018), which such a store can replace.
- **Regional deployment:** organizations carry a `data_region` and a deployment refuses other regions' organizations when enforced (ADR-0023); per-region databases and shared-data replication are still to build.
