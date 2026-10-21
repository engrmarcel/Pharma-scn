# API specification (v1)

Base path: `/api/v1`. JSON only. A machine-readable OpenAPI document is planned. Until then this file and `php artisan route:list --path=api` are authoritative.

## Conventions

| Topic | Rule |
|---|---|
| Auth | `Authorization: Bearer <token>`, issued by `POST /auth/tokens` (30-day expiry) |
| Tenant | Tenant endpoints require `X-Organization-Id: <ulid>`. It is honoured only if the caller has an active membership in an active organization; otherwise `403 organization_access_denied`, whether the id is unknown or belongs to someone else |
| Idempotency | Unsafe tenant/admin/onboarding requests accept `Idempotency-Key` (8–128 chars `[A-Za-z0-9._:-]`). The same key with the same body replays the stored 2xx response (`Idempotent-Replayed: true`); the same key with a different body returns `422 idempotency_key_reused`; a key still in flight returns `409`. 4xx/5xx responses are not stored |
| Correlation | `X-Correlation-Id` is accepted (8–64 chars) or generated; it is always returned and included in error bodies |
| Success body | `{ "data": … }`. Lists add `links` and `meta` (Laravel pagination) |
| Error body | `{ "message", "error": { "code", "details"? }, "correlation_id" }`. Validation errors add `errors: {field: [..]}` |
| Money / quantities | Decimal **strings** (`"1250.1250"`), never floats; always with `currency_code` |
| Rate limits | 120/min per user for the API; auth endpoints 5/min per email+IP and 30/min per IP |
| Compatibility | Additive changes only within v1 (new fields/endpoints). Removing or renaming a field requires `/api/v2` and a deprecation period of at least 6 months |

## Endpoints

### Public
| Method | Path | Notes |
|---|---|---|
| GET | `/branding` | Display name, colours, support contacts |
| POST | `/auth/tokens` | `{email, password, device_name}` → `{token, token_type, expires_at}` |
| POST | `/auth/register` | `{name, email, password, password_confirmation, device_name}` → token. Sends a verification link only when verification is required |
| POST | `/auth/tokens` (two-factor) | When two-factor is on and the user is enrolled: add `mfa_code` or `recovery_code`; without it the response is `401 mfa_required` |
| GET | `/email/verify/{id}/{hash}` (web, signed) | Verification link target |
| POST | `/auth/forgot-password` | Always returns the same message |
| POST | `/auth/reset-password` | Revokes all existing tokens |

### Authenticated (no tenant)
| Method | Path | Notes |
|---|---|---|
| GET | `/auth/me` | User, platform roles, memberships with roles |
| GET | `/auth/tokens` · DELETE `/auth/tokens/{id}` · DELETE `/auth/tokens/current` | Device/session management |
| GET | `/auth/security` | Policy, `email_verified`, `mfa_enabled`, `mfa_required` |
| POST | `/auth/mfa/setup` `{password}` → `{secret, otpauth_uri, qr_svg}` · `/auth/mfa/confirm` `{code}` → recovery codes (shown once) | `403 mfa_disabled` while the platform mode is `off` |
| DELETE | `/auth/mfa` `{password, code \| recovery_code}` · POST `/auth/mfa/recovery-codes` `{code}` | |
| POST | `/auth/email/verification-notification` | Resend link (6/min) |
| GET | `/reference/countries` · `/reference/organization-types` · `/reference/regulatory-authorities` · `/reference/catalogue` | |
| POST | `/organizations` | Register an organization; caller becomes owner; default modules by type |
| POST | `/invitations/accept` | `{token}`; must match the invited email |
| GET | `/products` | `q, dosage_form, manufacturer_id, ingredient, status, sort, per_page`. Products under review are hidden from non-catalogue staff |
| GET | `/products/{id}` | Full record incl. ingredients, package levels, identifiers, classifications, regulatory identifiers |
| GET | `/regulatory-identifiers/lookup` | `authority, value` → declared identifiers and product ids |

### Tenant (`X-Organization-Id` required)
| Method | Path | Permission |
|---|---|---|
| GET / PATCH | `/organization` | `organization.view` / `organization.manage` |
| GET / POST | `/organization/documents` (multipart `category`, `file`) | view / manage |
| GET | `/organization/documents/{id}` | `organization.view` (download) |
| POST | `/organization/verification/submit` | `organization.manage` |
| GET | `/members` · `/roles` | `organization.view` |
| POST | `/invitations` | `organization.members.manage` (owner role only grantable by owners) |
| PUT | `/members/{id}/roles` · `/members/{id}/status` | `organization.members.manage`; last-owner protection |
| GET/POST/PATCH | `/branches`, `/warehouses` | `locations.view` / `locations.manage` |
| GET/POST | `/product-change-requests` | `catalogue.propose`; organization must be platform-verified |
| GET/POST/PATCH | `/catalogue/items` | `tenant_products.*`; module `catalogue` |
| GET | `/audit-logs` | `audit.view`; this organization only |
| PUT | `/members/{id}/locations` `{all_locations, branch_ids[], warehouse_ids[]}` | `organization.members.manage`; restricts inventory visibility and actions |
| GET | `/inventory/stock-levels` (`product_id`, `warehouse_id`) | `inventory.view`; per product+warehouse: available on hand, reserved, **sellable**, blocked, quarantined, damaged, in transit, nearest sellable expiry (base units) |
| GET | `/inventory/balances`, `/inventory/movements`, `/inventory/expiring?days=`, `/inventory/low-stock` | `inventory.view` (movement `unit_cost` only with `inventory.valuation.view`) |
| GET | `/inventory/valuation` | `inventory.valuation.view`; value at batch cost |
| POST | `/inventory/allocations/preview` `{product_id, warehouse_id, quantity}` | `inventory.view`; FEFO plan, no reservation |
| POST | `/inventory/receipts` **(Idempotency-Key required)** `{warehouse_id, supplier_id?, reason_code?, lines[{product_id, batch_number, expiry_date, manufacturing_date?, quantity, package_level_code?, unit_cost?, quality_status?, bin_location_id?}]}` | `inventory.receive`; product must be in the organization catalogue |
| GET / PATCH | `/inventory/settings` `{allow_negative_stock, min_shelf_life_days}` | view / `inventory.settings.manage` |
| GET / POST | `/warehouses/{id}/bins` | view / `inventory.settings.manage` |
| GET | `/batches`, `/batches/{id}` | `inventory.view`; includes `sale_block_reason` |
| POST | `/batches/{id}/quarantine` · `/release` · `/reject` `{reason}` | `inventory.quality` |
| GET / POST | `/stock-adjustments` **(POST needs Idempotency-Key)** `{warehouse_id, reason_code, notes?, lines[{batch_id, stock_state?, quantity_delta}]}` | `inventory.adjust` |
| GET / POST · GET `{id}` | `/stock-transfers` `{from_warehouse_id, to_warehouse_id, lines[{product_id, quantity, batch_id?}]}` | `inventory.transfer` |
| POST | `/stock-transfers/{id}/dispatch` · `/receive` **(Idempotency-Key)** `{lines[{line_id, quantity}]}` · `/cancel` | `inventory.transfer`; source/destination access respectively |
| GET / POST | `/stock-counts` · PUT `/{id}/lines` · POST `/{id}/post` | `inventory.count` (posting also needs `inventory.adjust`) |
| GET / POST | `/purchase-requisitions` `{warehouse_id, needed_by?, lines[{product_id, quantity, package_level_code?, suggested_supplier_id?}]}` (module procurement) | `procurement.view` / `procurement.requisition` |
| POST | `/purchase-requisitions/{id}/submit` · `/approve` · `/reject {notes}` · `/convert {supplier_id?}` | requisition / `procurement.approve` / `procurement.order`; approver ≠ requester unless `allow_self_approval` |
| GET / POST · GET/PATCH `{id}` | `/purchase-orders` `{supplier_id, warehouse_id, expected_date?, lines[{product_id, quantity, package_level_code?, unit_price?, tax_rate?}]}` (price from supplier price list when omitted) | `procurement.view` / `procurement.order` |
| POST | `/purchase-orders/{id}/submit` · `/approve` · `/reject {reason}` · `/send` · `/cancel {reason}` · `/close {reason}` | order / approve; submit auto-approves below `approval_threshold`; no threshold = all need approval |
| GET / POST | `/goods-receipts` **(Idempotency-Key)** `{purchase_order_id, supplier_delivery_note?, lines[{purchase_order_line_id, batch_number, expiry_date, quantity (PO package level), quality_status?}]}` | `inventory.receive` + `procurement.view`; `over_receipt` beyond tolerance |
| GET / POST | `/supplier-invoices` **(Idempotency-Key)** `{supplier_id, purchase_order_id?, supplier_invoice_number, invoice_date, due_date?, lines[]}` → `match_status` matched / variance / unmatched | `procurement.invoice`; duplicate supplier document → 409 |
| POST | `/supplier-invoices/{id}/approve {override_reason?}` · `/cancel {reason}` | approve / invoice; unmatched needs an override reason |
| GET / POST | `/supplier-returns` **(Idempotency-Key)** `{supplier_id, warehouse_id, reason_code, lines[{batch_id, base_quantity, stock_state?}]}` | `procurement.return` |
| GET / PUT | `/suppliers/{id}/price-list` · GET `/suppliers/{id}/performance` | view / order |
| GET | `/procurement/suggestions` (low stock + preferred supplier + list price; suggestions only) · GET/PATCH `/procurement/settings` `{approval_threshold, allow_self_approval, over_receipt_tolerance_percent}` | view / `procurement.settings.manage` |
| GET / POST · PATCH `{id}` | `/pos/terminals` (module pos) | `pos.sell` / `pos.terminals.manage`; limited by the `pos_terminals` entitlement |
| POST · GET `current` · GET `{id}` | `/pos/shifts` `{pos_terminal_id, opening_float}` | `pos.sell`; one open shift per terminal and per cashier |
| POST | `/pos/shifts/{id}/cash-movements` **(Idempotency-Key)** `{amount (±), reason}` · `/close {counted_cash}` · `/review {notes}` | `pos.cash.manage` / own shift / `pos.shift.review` (not the cashier; notes required for variance) |
| GET | `/pos/lookup?pos_terminal_id&q=` (name, SKU, GTIN or batch number; sellable stock at the terminal) | `pos.sell` |
| POST · GET `{id}` | `/pos/sales` `{pos_shift_id, client_transaction_id (ULID), customer_id?}`; same client id returns the same sale | `pos.sell` (own sales) |
| PUT | `/pos/sales/{id}/lines` `{lines[{product_id, quantity, package_level_code?, unit_price?, discount_percent?, batch_id?, prescription?{reference, prescriber_name, prescribed_on}}]}` | price change: `sales.price.override`; discount above limit: `sales.approve`; Rx: `pos.dispense.prescription` |
| POST | `/pos/sales/{id}/hold` · `/checkout` · `/cancel {reason}` | cancel with payments: `pos.sale.cancel` |
| POST | `/pos/sales/{id}/payments` **(Idempotency-Key)** `{method: cash\|card\|bank_transfer\|mobile_money, amount, tendered?, channel: manual_terminal\|<provider>, approval_code?}` | `pos.sell`; electronic stays pending |
| GET · POST | `/pos/payments/pending` · `/pos/payments/{id}/confirm` | `pos.payments.confirm` (not the selling cashier) |
| GET | `/pos/sales/{id}/receipt` | own sale |
| POST · GET · POST | `/pos/sales/{id}/refunds` **(Idempotency-Key)** `{reason, refund_method: cash\|bank_transfer, lines[{pos_sale_line_id, quantity, restock_state?}]}` · `/pos/refunds` · `/pos/refunds/{id}/approve\|reject` | `pos.refund.request` / `pos.refund.approve` (not the requester) |
| GET | `/pos/reports/{summary\|by-product\|by-cashier\|by-payment-method\|by-terminal\|refunds\|shifts\|batches}?from&to` | `pos.reports.view` |
| POST (public) | `/payments/webhooks/{provider}` | provider signature only; idempotent per event id |
| GET / POST · PUT `{id}/items` | `/price-lists` `{code, name, branch_id?, is_default?, items[{product_id, package_level_code, unit_price, minimum_quantity?, valid_from?, valid_to?}]}` (module sales) | `sales.view` / `sales.price_lists.manage` |
| GET / POST · POST `{id}/convert` **(Idempotency-Key)** | `/quotations` `{customer_id, warehouse_id, valid_until?, lines[]}` | `sales.quote` / `sales.order`; quoted prices honoured; expired → 409 |
| GET / POST **(Idempotency-Key)** · GET/PATCH `{id}` | `/sales-orders` `{customer_id, warehouse_id, requested_date?, lines[{product_id, quantity, package_level_code?, unit_price?, discount_percent?}]}` | `sales.view` / `sales.order`; price differing from the list price needs `sales.price.override` |
| POST | `/sales-orders/{id}/confirm` · `/approve` · `/reject {reason}` · `/cancel {reason}` · `/close {reason}` | order / `sales.approve`; confirm reserves stock FEFO or holds for approval (discount above limit, credit limit) |
| POST | `/sales-orders/{id}/deliveries` **(Idempotency-Key)** `{lines[{sales_order_line_id, quantity}]}` · `/sales-orders/{id}/invoice` **(Idempotency-Key)** | `sales.deliver` / `sales.invoice`; delivery posts cost of goods sold, invoice bills delivered quantities |
| GET · GET `{id}` · POST `{id}/credit-notes` **(Idempotency-Key)** | `/sales-invoices` (credit note: `{reason_code, return_warehouse_id?, lines[{sales_invoice_line_id, quantity, batch_id?, restock_state?}]}`) | `sales.view` / `sales.credit`; restocking as `available` needs `sales.returns.restock` and a sellable batch |
| POST | `/customer-payments` **(Idempotency-Key)** `{customer_id, amount, method, payment_date, allocations[{sales_invoice_id, amount}]}` | `finance.payments.record` |
| GET | `/customers/{id}/statement` · `/sales/receivables-aging` · GET/PATCH `/sales/settings {max_discount_percent, allow_self_approval}` | `sales.view` / `sales.settings.manage` |
| GET / POST · PATCH `{id}` | `/finance/accounts` (module finance) | `finance.view` / `finance.accounts.manage`; system accounts cannot be deactivated |
| GET · POST `{id}/close` · `{id}/reopen {reason}` | `/finance/periods` | view / `finance.periods.manage`; closed periods refuse every posting |
| GET / POST **(Idempotency-Key)** · POST `{id}/reverse {reason}` | `/finance/journal-entries` `{entry_date, description, lines[{account_id, debit?, credit?, memo?}]}` | view / `finance.journal.post`; must balance; entries are immutable |
| GET · POST `/finance/payments/supplier` **(Idempotency-Key)** `{supplier_id, amount, method, payment_date, reference?, account_id?, allocations[{supplier_invoice_id, amount}]}` · POST `/finance/payments/{id}/allocations` | `/finance/payments` | view / `finance.payments.record` |
| GET | `/finance/reports/{trial-balance \| balance-sheet \| profit-and-loss \| account-ledger \| payables-aging}` (`as_of`, `from`, `to`, `account_id`) | `finance.view`; management reports, not statutory |
| GET/POST · GET/PATCH `{id}` | `/suppliers` (module procurement or inventory) | `suppliers.view` / `suppliers.manage`; `q`, `status`, `type` filters |
| POST · DELETE | `/suppliers/{id}/contacts`, `/suppliers/{id}/contacts/{contactId}` | `suppliers.manage` |
| GET/POST · GET/PATCH `{id}` | `/customers` (module sales, pos or field_force) | `customers.view` / `customers.manage` |
| POST · DELETE | `/customers/{id}/contacts`, `…/{contactId}` | `customers.manage` |
| GET · POST · POST `{id}/revoke {reason, wipe?}` | `/devices` (module field_force or pos) `{name, platform: android\|ios\|other, app_version?, role: field\|pos, pos_terminal_id? (pos)}` | list: own, or all with `devices.manage`; register: role access (field-force or `pos.sell`); returns a device-bound `token`; revoke: own device or `devices.manage`. A revoked device's next request gets `401 device_revoked {wipe}` once |
| POST | `/sync/batches` `{envelopes[≤50]{client_txn_id, type, schema_version, client_created_at, attempt?, payload}}` | device token only (`403 device_required` otherwise). Per-envelope ack: `synced {result}` · `conflict` · `rejected` · `retry_required`, with `error{code, message, details}`; replays return the stored ack with `duplicate: true` (ADR-0011). Types: `field.visit.check_in` (check-in body) · `field.visit.check_out` (`visit_client_reference` + check-out body) · `field.visit.cancel {visit_client_reference, reason}` · `field.order.capture` (order body, `field_visit_client_reference?`) · `field.order.resubmit {order_client_reference, lines?, notes?}` · `field.follow_up.complete {follow_up_id, outcome}` · `pos.sale {client_reference, shift_id, customer_id?, completed_at, device_total?, lines[{product_id, package_level_code?, quantity, unit_price, discount_percent?, batch_id?, prescription?}], payments[{method: cash\|card\|bank_transfer\|mobile_money, amount, tendered?, approval_code? (non-cash)}]}` (POS devices on the shift's terminal; result carries sale status and per-payment `state`: `offline_cash_recorded` · `confirmed` · `payment_pending`; replay findings come back as `warnings`) |
| GET | `/sync/status` | device token: device, inbox counts by state, open issues, server time |
| GET · POST `{id}/resolve {notes}` | `/sync/inbox` (`status`, `device_id`, `type`, `unresolved`) | `sync.reconcile` or `devices.manage` / `sync.reconcile`; refused transactions are kept and annotated, never deleted |
| GET · POST `{id}/resolve {action: acknowledge\|record_found_stock, notes, batch_id? \| batch_number + expiry_date, unit_cost?}` | `/sync/issues` (`status`, `type`, `device_id`) | `sync.reconcile`; `record_found_stock` applies to POS `stock_shortfall` issues: it receives the found stock and issues it to the sale, posting the cost of goods sold |
| GET | `/sync/reconciliation?from&to` | `sync.reconcile` or `devices.manage`; per device: received/synced/conflict/rejected/retry, unresolved, issues, offline POS sales |
| GET | `/field-force/me` (module field_force) | any field-force permission; returns permissions, territories, location settings and notice, server time |
| GET / POST · PATCH `{id}` · PUT `{id}/members` | `/field-force/territories` `{code, name, parent_id?, warehouse_id?}` · members `{members[{user_id, role: representative\|supervisor}]}` | list: own territories, or all with `field_force.territories.manage`/`view_all`; changes: `field_force.territories.manage`; members limited by the `mobile_users` entitlement |
| PUT | `/field-force/customer-assignments` `{customer_ids[], territory_id\|null, assigned_representative_id?\|null}` | `field_force.territories.manage` |
| GET · GET `{id}` | `/field-force/customers` (`q`, `territory_id`, `updated_since`) | `customers.view` (all) or `customers.view_assigned` (own territories/assignments; others 404); detail adds contacts, location-verification history, recent visits, orders and open follow-ups |
| GET | `/field-force/catalogue?customer_id&warehouse_id?&q&page` | `field_force.orders.capture`; per sellable pack: indicative customer price and its source; sellable stock with `meta.stock_as_of` |
| GET · GET `{id}` | `/field-force/visits` (`representative_id`, `customer_id`, `status`, `from`, `to`) | own; team with `field_force.team.view`; all with `field_force.view_all` |
| POST | `/field-force/visits` (check-in) `{client_reference, customer_id, visit_type, objectives?, captured_offline?, check_in{occurred_at, location_status: granted\|denied\|restricted\|service_disabled\|unavailable\|timeout, latitude?, longitude?, accuracy_m?, mock_location?, device{platform, os_version, app_version, device_id, model}?}}` | `field_force.visits.capture`; 201, or 200 with `meta.replayed` for the same `client_reference`; one open visit per representative (409 `visit_in_progress`) |
| PATCH · POST `{id}/activities` · POST `{id}/check-out` · POST `{id}/cancel {reason}` | `/field-force/visits/{id}` (details: `objectives, discussion_notes, products_discussed[], customer_feedback, competitor_information, order_opportunity, outcome, next_action, follow_up_date`; check-out adds `check_out{…}`, required `outcome`, `activities[]`) | only the visit's representative (403 `visit_not_yours`); competitor information needs `field_force.competitor.record`; `follow_up_date` creates a follow-up task |
| GET · GET `{id}` · POST · PATCH `{id}` · POST `{id}/submit` · POST `{id}/cancel {reason}` | `/field-force/orders` `{client_reference, customer_id, field_visit_id?, warehouse_id?, captured_at, captured_offline?, requested_date?, notes?, submit?, lines[{product_id, package_level_code?, quantity, indicative_unit_price?}]}` | `field_force.orders.capture`; responses carry `accepted_by_erp`, `sales_order{number, status, total}`, `stock_reserved`; rejected orders carry `rejection_reasons[{code, message, details}]` |
| GET · POST · POST `{id}/complete {outcome}` · `{id}/cancel` | `/field-force/follow-ups` `{client_reference?, customer_id, field_visit_id?, assigned_to?, title, notes?, due_on}` | `field_force.visits.capture`; assign to self, or within the visible team |
| GET | `/field-force/reports/activity?from&to` | `field_force.reports.view`; per visible representative: visits, completed, verified and unverified check-ins, without location, customers visited, average minutes, orders captured/submitted/accepted/rejected, accepted value, follow-up compliance |

### Platform administration (`/admin`, platform roles; no tenant context)
| Method | Path | Permission |
|---|---|---|
| GET | `/admin/organizations`, `/admin/organizations/{id}` | `platform.organizations.view` |
| GET | `/admin/organizations/{id}/documents/{doc}` | `platform.organizations.verify` (access is audited) |
| POST | `/admin/organizations/{id}/verification-decisions` `{decision: approve\|reject\|request_changes\|revoke, notes}` | `platform.organizations.verify` |
| POST | `/admin/organizations/{id}/suspend` · `/reactivate` | `platform.organizations.suspend` |
| PUT | `/admin/organizations/{id}/modules` `{modules: {pos: true}}` | `platform.organizations.modules` |
| POST / PATCH | `/admin/products`, `/admin/products/{id}` (PATCH needs `version`) | `catalogue.manage` |
| POST | `/admin/products/{id}/regulatory-identifiers` | `catalogue.manage` |
| POST | `/admin/regulatory-identifiers/{id}/verifications` `{method, result, source, notes, evidence_attachment_id}` | `regulatory.verify` |
| GET | `/admin/product-change-requests?status=` · POST `…/{id}/approve` · `…/{id}/reject` | `catalogue.review` |
| PUT | `/admin/branding` | `platform.settings.manage` |
| POST | `/admin/users/{id}/suspend` · `/reactivate` | `platform.users.manage` |
| GET | `/admin/audit-logs` | `platform.audit.view` (platform-level events only) |
| GET / PATCH | `/admin/subscription-plans`, `/admin/subscription-plans/{id}` (name, price, trial, `entitlements: {key: {enabled, limit}}`) | `platform.billing.manage` |
| GET / PUT | `/admin/organizations/{id}/subscription` (`plan_code` and/or `status`) | `platform.billing.manage` |
| PUT | `/admin/organizations/{id}/entitlement-overrides` `{key, enabled, limit, reason}` | `platform.billing.manage` |
| GET | `/admin/operations/status` (queue backlog, failed jobs, review queues) | `platform.operations.manage` |
| GET / POST | `/admin/operations/failed-jobs`, `…/{uuid}/retry` (payloads never returned) | `platform.operations.manage` |
| GET | `/admin/users?q=` (identity fields only) | `platform.users.manage` |
| GET / PATCH | `/admin/countries`, `/admin/countries/{code}` | `platform.settings.manage` |
| GET / PUT | `/admin/security-settings` `{mfa_mode, email_verification_required}` | `platform.settings.manage` |
| POST | `/admin/users/{id}/mfa-reset` `{reason}` | `platform.users.manage` |
| PATCH | `/organization/security` `{require_mfa}` (tenant) | `organization.manage` |

Tenant addition: `GET /organization/subscription` (plan, status, effective entitlements, usage), which needs `organization.billing.view`.

Public addition: `GET /health`, which returns `200 {"status":"ok","checks":{"database":"ok","cache":"ok","storage":"ok"}}` or `503` with `degraded`. Check details are logged, not returned.

## Stable error codes (selection)

`journal_unbalanced` (422), `period_closed` (409), `allocation_exceeds_balance` (422), `allocation_exceeds_payment` (422), `system_account_required` (409), `insufficient_stock` (409), `stock_reserved` (409), `batch_not_sellable` (409), `batch_expiry_mismatch` (409), `expired_on_receipt` (422), `product_not_in_catalogue` (422), `location_access_denied` (403), `stock_reference_invalid` (404), `transfer_state_invalid` (409), `batch_release_blocked` (409), `mfa_required` (401), `mfa_code_invalid` (401/422), `mfa_disabled` (403), `mfa_enrollment_required` (403/409), `mfa_required_by_policy` (403), `email_unverified` (403), `entitlement_limit_reached` (403), `subscription_inactive` (403), `module_not_entitled` (403), `synthetic_identifier_unverifiable` (422), `synthetic_identifier_rejected` (422), `organization_required` (400), `organization_access_denied` (403), `organization_suspended` (403), `account_suspended` (403), `module_not_enabled` (403), `organization_not_verified` (403), `review_conflict_of_interest` (403), `verification_conflict_of_interest` (403), `verification_documents_missing` (422), `verification_transition_invalid` (409), `product_version_conflict` (409), `package_hierarchy_invalid` (422), `gtin_invalid` (422), `product_identifier_duplicate` (409), `roles_invalid` (422), `last_owner` (409), `invitation_invalid` (404), `invitation_email_mismatch` (403), `idempotency_key_required|invalid` (400), `idempotency_key_reused` (422), `idempotency_request_in_progress` (409).

## Example: register, upload, submit

```http
POST /api/v1/organizations
Authorization: Bearer …
Idempotency-Key: onboard-2026-10-18-001
{"name":"Lekki Community Pharmacy","type":"pharmacy","country_code":"NG","city":"Lagos"}

POST /api/v1/organization/documents          (multipart: category=business_registration, file=@cac.pdf)
X-Organization-Id: 01M5650EP6SZR4P4K2JK8DA78H

POST /api/v1/organization/verification/submit
X-Organization-Id: 01M5650EP6SZR4P4K2JK8DA78H
```
