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

## Procurement (Phase 2.3, implemented)

```
supplier_price_list_items (supplier, product, package level, price, min qty, validity)
purchase_requisitions(+lines, package qty + base qty, suggested supplier) → purchase_orders(+lines: qty, base qty, unit price, tax rate,
  line subtotal/tax, received_base_quantity, invoiced_base_quantity; totals) → goods_receipts(+lines → batch, posting_id)
supplier_invoices(+lines; unique supplier + supplier_invoice_number; match_status, match_details) · supplier_returns(+lines, posting_id)
```

All amounts come from `App\Support\Money`: bcmath at 10 places, rounded half-up to 4.

## Forward entity model (Phases 2–7, designed and not yet migrated)

All of these tables are tenant-owned (`organization_id`) unless marked *shared*. Quantities are stored in base units with the package level recorded.

```
Sales          price_lists(+items) · quotations → sales_orders(+items) → invoices(+items) · credit_notes · sales_returns
Finance        accounts (chart) · journal_entries(+lines, balanced) · financial_periods · payments · payment_allocations · tax_rules (effective-dated, per country)
POS            pos_terminals · pos_shifts (opening/expected/counted/variance) · pos_cash_movements · pos_sales(+items with batch_id) · pos_payments · pos_refunds · receipts
FieldForce     territories (warehouse for mobile orders) · territory_members (representative|supervisor) · customers.territory_id / assigned_representative_id · field_visits (client_reference unique; check-in/out device time, server time, location status, lat/lng/accuracy, mock flag, distance, verification, device; location_purged_at) · field_visit_activities · field_follow_ups · field_orders (draft→submitted→accepted|rejected, sales_order_id) + field_order_lines (indicative prices)   [built in 2.6a]
Sync           devices (registration, revoked_at) · sync_inbox (client_txn_id unique, envelope, status, error_code) · sync_conflicts
Marketplace    marketplace_profiles (listed, regions, delivery options) · marketplace_listings (supplier product + pack, price, MOQ, availability live|declared with declared_at, allowed buyer types) · trading_relationships (buyer ↔ supplier, status, each side's ERP party id)   [built in 4a] · rfqs (+rfq_lines, rfq_invitations) · marketplace_quotes(+lines) · marketplace_orders(+lines, with buyer purchase order and supplier sales order ids per order and per line)   [built in 4b] · marketplace_shipments(+lines with batch evidence) · marketplace_invoices (supplier sales invoice, buyer supplier invoice) · marketplace_fees (append-only, waivable) · marketplace_disputes(+messages, credit note and return ids)   [built in 4c]
CRM            customers (+tier, visit_frequency_days, potential_value) · party_contacts (+professional profile, consent, anonymized_at) · customer_segments(+members) · crm_interactions · customer_feedback · detailing_messages · detailing_records   [built in 6a]
Integrations   integration_connectors (registry, hashed key, encrypted credentials) · integration_field_mappings · integration_external_references · integration_runs · integration_inbox (durable, unique per connector + message_id; quarantined/failed rows are the failed-message store) · integration_inventory_snapshots   [built in 5a; runs gained direction, dataset, record_count and file error in 5b] · integration_outbox (append-only, written in the business transaction) · webhook_subscriptions (encrypted secret) · webhook_deliveries (attempts, backoff, dead letter)   [built in 5c]
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
