# Database design

Source of truth: `database/migrations/`. The structure-only SQL file `database/sql/pharmaceutical_supply_chain_schema.sql` is **generated** from a migrated database (`php artisan schema:export-sql`), and `SchemaSnapshotTest` fails if it drifts from the migrations.

## Conventions

- **Keys:** domain tables use **ULID** primary keys (`char(26)`). ULIDs are sortable, safe to generate on clients for offline use, and do not reveal record counts. `users` keeps a bigint key for Sanctum compatibility; reference tables use natural code keys (`countries.code`, `dosage_forms.code`, …).
- **Money:** `decimal(19,4)` with an explicit `currency_code char(3)`. Quantities use `decimal(18,4)`, and strengths `decimal(18,6)`. There are no floating-point columns.
- **Timestamps:** stored in UTC (`app.timezone = UTC`). `DATETIME` is used for long-horizon dates such as expiries, to avoid the 2038 limit of `TIMESTAMP`.
- **Append-only tables** (`audit_logs`, `organization_verifications`, `regulatory_verifications`) are protected at the model layer against update and delete. Corrections are recorded as new rows.
- **Polymorphic types** use a fixed morph map (`product`, `organization`, `manufacturer`, …) so PHP class names never reach the database.
- **Soft deletes:** none so far. Lifecycle is expressed with `status` columns.

## Ownership: shared versus tenant data

| Platform-shared (governed by catalogue / compliance permissions) | Tenant-owned (`organization_id`, fail-closed scope) |
|---|---|
| countries, regulatory_authorities, units_of_measure, dosage_forms, administration_routes, product_categories | branches, warehouses, organization_invitations |
| manufacturers, brands, ingredients | organization_products (SKU, cost, price, tax category, preferred supplier, reorder) |
| products, product_ingredients, product_package_levels, product_identifiers, product_classifications | product_change_requests (the proposing organization's) |
| regulatory_identifiers, regulatory_verifications | attachments with `organization_id` |
| platform_settings, permissions, system roles | custom roles (`roles.organization_id`) |

Identity tables (`organizations`, `organization_memberships`, `membership_role`, `platform_role_user`, `organization_verifications`) are queried with explicit filters.

## Product Master

```
manufacturers 1─* brands
products *─1 brand, manufacturer, category, dosage_form, route
products 1─* product_ingredients *─1 ingredients      (strength value/unit, optional per value/unit)
products 1─* product_package_levels                   (level 0..n, quantity_of_child, derived base_unit_quantity)
products 1─* product_identifiers ─0..1 package level  (GTIN etc., globally unique per type+value)
products 1─* product_classifications                  (per country: otc / pharmacy_only / prescription_only / controlled)
products|manufacturers|organizations 1─* regulatory_identifiers *─1 regulatory_authorities
regulatory_identifiers 1─* regulatory_verifications   (append-only evidence)
products 1─* organization_products                    (tenant mapping)
organizations 1─* product_change_requests             (governance queue)
```

- **Package hierarchy:** levels are contiguous from 0. `base_unit_quantity` is the product of `quantity_of_child` down to level 0, calculated with bcmath, so converting between any two levels is `qty × from.base / to.base`.
- **Regulatory identifiers** (e.g. NAFDAC Registration No.) are jurisdiction-aware. The status the submitter declared (`declared_status`, `expiry_date`) is stored separately from the platform's `verification_status`, and both are separate from the evidence rows in `regulatory_verifications`. A verified identifier past its declared expiry is reported as `expired` without rewriting history. Values are normalized (uppercase, no whitespace). There is deliberately no global unique constraint on a value: one registration can legitimately cover several packs, and duplicate claims across unrelated products are surfaced by the lookup endpoint for review.
- **Optimistic concurrency:** `products.version` is incremented on every edit, and both edits and change requests state the version they were based on.

## Subscriptions (spec §21)

```
subscription_plans 1─* plan_entitlements (key, enabled, limit_value NULL = unlimited)
organizations 1─* organization_subscriptions (status, trial/period dates, ended_at NULL = current; history kept)
organizations 1─* organization_entitlement_overrides (negotiated exceptions, with reason and grantor)
```

## Parties and inventory (Phase 2.1–2.2, implemented)

```
suppliers / customers (tenant) 1─* party_contacts
batches (tenant; unique org+product+batch_number; one expiry per lot; quality_status, recall_status, unit_cost per base unit)
inventory_movements  APPEND-ONLY ledger: posting_id groups legs; signed base-unit quantity; balance_after; movement_type, reason, reference
inventory_balances   projection, unique (warehouse, batch, stock_state); quantity + reserved_quantity; only InventoryLedger writes it
stock_reservations   FEFO-allocated holds (active → consumed | released)
stock_adjustments(+lines) · stock_transfers(+lines, split per batch at dispatch) · stock_counts(+lines with system_quantity at posting)
bin_locations ─* warehouses · document_sequences (per-org ADJ/TRF/CNT numbering under row lock)
```

Stock states are `available`, `quarantined`, `damaged` and `in_transit`. *Sellable* means available, unreserved, batch released, not recalled, not expired, and meeting the organization's minimum remaining shelf life. Every balance equals the sum of its movements, which a test checks.

## Forward entity model (Phases 2–7, designed and not yet migrated)

All of these tables are tenant-owned (`organization_id`) unless marked *shared*. Quantities are stored in base units with the package level recorded.

```
Procurement    purchase_requisitions → purchase_orders(+items) → goods_receipts(+items with batch) → supplier_invoices · supplier_returns
Sales          price_lists(+items) · quotations → sales_orders(+items) → invoices(+items) · credit_notes · sales_returns
Finance        accounts (chart) · journal_entries(+lines, balanced) · financial_periods · payments · payment_allocations · tax_rules (effective-dated, per country)
POS            pos_terminals · pos_shifts (opening/expected/counted/variance) · pos_cash_movements · pos_sales(+items with batch_id) · pos_payments · pos_refunds · receipts
FieldForce     territories · territory_assignments · customer_assignments · visits (check-in/out lat/lng/accuracy/permission/evidence status) · visit_activities · follow_up_tasks · mobile_orders
Sync           devices (registration, revoked_at) · sync_inbox (client_txn_id unique, envelope, status, error_code) · sync_conflicts
Marketplace    listings · rfqs(+items) · quotations · b2b_orders(+items) · fulfilments · disputes · marketplace_fees
Integrations   connector_configurations · external_references · field_mappings · integration_inbox · integration_outbox · integration_runs · failed_messages · webhook_subscriptions · delivery_attempts
Traceability   serialized_items · traceability_events (append-only) · batch_genealogy · custody_transfers · shipments(+items, events) · recalls(+items, acknowledgements) · quarantine_records · scan_events
```

## Operations

- `audit_logs`: actor, action, subject, old/new values (secrets redacted), IP address, user agent, correlation id. Indexed by organization and time, and by subject.
- `idempotency_keys`: unique per `(scope, key)` where scope is `user:<id>|org:<id>`. Successful responses are stored for 48 h.
- `attachments`: private disk path, SHA-256 hash, size, MIME type, scan status.

## Initialising and updating

```bash
php artisan migrate --seed          # new environment: schema + reference data + permissions (+ demo data outside production)
php artisan migrate                 # upgrades; never migrate:fresh outside local development
php artisan db:seed --class=RolesAndPermissionsSeeder   # safe on every deploy: syncs the permission registry
php artisan schema:export-sql       # after any migration change; commit the regenerated SQL file
```

Migrations are additive and reversible (`down()` provided). Destructive changes require an explicit plan: add the new column, backfill, switch reads, then drop in a later release.

## Retention and sensitive data (initial policy)

| Data | Retention | Notes |
|---|---|---|
| Audit logs, verification histories | ≥ 7 years | Append-only; restricted access |
| Verification documents | Life of the organization + 7 years | Private storage; access by owner org and verification officers (audited) |
| Idempotency keys | 48 h | Purged by scheduled job (Phase 1B) |
| Personal data (users, contacts) | While account active + legal minimum | Erasure via documented workflow; financial/audit records retained where law requires |
| GPS visit data (Phase 2) | Configurable per organization, default 24 months | |

The legal minimums must be confirmed with counsel for each jurisdiction (NDPA 2023 for Nigeria).
