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
| GET/POST · GET/PATCH `{id}` | `/suppliers` (module procurement or inventory) | `suppliers.view` / `suppliers.manage`; `q`, `status`, `type` filters |
| POST · DELETE | `/suppliers/{id}/contacts`, `/suppliers/{id}/contacts/{contactId}` | `suppliers.manage` |
| GET/POST · GET/PATCH `{id}` | `/customers` (module sales, pos or field_force) | `customers.view` / `customers.manage` |
| POST · DELETE | `/customers/{id}/contacts`, `…/{contactId}` | `customers.manage` |

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

`insufficient_stock` (409), `stock_reserved` (409), `batch_not_sellable` (409), `batch_expiry_mismatch` (409), `expired_on_receipt` (422), `product_not_in_catalogue` (422), `location_access_denied` (403), `stock_reference_invalid` (404), `transfer_state_invalid` (409), `batch_release_blocked` (409), `mfa_required` (401), `mfa_code_invalid` (401/422), `mfa_disabled` (403), `mfa_enrollment_required` (403/409), `mfa_required_by_policy` (403), `email_unverified` (403), `entitlement_limit_reached` (403), `subscription_inactive` (403), `module_not_entitled` (403), `synthetic_identifier_unverifiable` (422), `synthetic_identifier_rejected` (422), `organization_required` (400), `organization_access_denied` (403), `organization_suspended` (403), `account_suspended` (403), `module_not_enabled` (403), `organization_not_verified` (403), `review_conflict_of_interest` (403), `verification_conflict_of_interest` (403), `verification_documents_missing` (422), `verification_transition_invalid` (409), `product_version_conflict` (409), `package_hierarchy_invalid` (422), `gtin_invalid` (422), `product_identifier_duplicate` (409), `roles_invalid` (422), `last_owner` (409), `invitation_invalid` (404), `invitation_email_mismatch` (403), `idempotency_key_required|invalid` (400), `idempotency_key_reused` (422), `idempotency_request_in_progress` (409).

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
