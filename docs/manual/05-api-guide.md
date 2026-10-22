# API guide

The REST API used by the web application, the mobile apps and other systems. Base address: `https://<your-host>/api/v1`. All requests and responses are JSON (`Accept: application/json`). The complete endpoint list is in `docs/API_SPECIFICATION.md`; `php artisan route:list --path=api` is authoritative.

## 1. Authentication

```http
POST /api/v1/auth/tokens
Content-Type: application/json

{"email": "user@example.com", "password": "…", "device_name": "warehouse-scanner-01"}
```

```json
{"data": {"token": "12|8Vq…", "token_type": "Bearer", "expires_at": "2026-11-21T10:00:00Z"}}
```

- Send the token on every request: `Authorization: Bearer 12|8Vq…`.
- Tokens expire after 30 days; request a new one before then. Revoke with `DELETE /auth/tokens/current`; list and revoke other sessions with `GET /auth/tokens`, `DELETE /auth/tokens/{id}`.
- With two-factor enabled, add `mfa_code` (or `recovery_code`) to the request; without it the response is `401 mfa_required`.
- `GET /auth/me` returns the user, their platform roles and their memberships (organization ids and roles).

## 2. Acting for an organization

Organization endpoints need the header `X-Organization-Id: <organization id>`. The id must be an active organization the caller is a member of; otherwise the response is `403 organization_access_denied` (the same answer whether the organization exists or not). The caller's roles in that organization decide what is allowed; a member restricted to some warehouses sees only those.

## 3. Conventions

| Topic | Rule |
|---|---|
| Success | `{"data": …}`; lists add `links` and `meta` (page, per_page, total) |
| Pagination | `?page=2` (and `per_page` where the endpoint offers it); follow `links.next` until it is null |
| Errors | `{"message": "…", "error": {"code": "…", "details": {…}}, "correlation_id": "…"}`; validation errors add `errors: {field: [messages]}` |
| Money and quantities | Decimal **strings** (`"1250.0000"`), never floats, always with `currency_code`. Amounts are rounded to the currency's smallest unit |
| Dates | ISO 8601 (`2026-10-22`, `2026-10-22T09:30:00+00:00`); timestamps are UTC, business dates follow the organization's time zone |
| Idempotency | Send `Idempotency-Key` (8–128 characters) on POST/PUT/PATCH. A retry with the same key and body replays the first response (`Idempotent-Replayed: true`); the same key with a different body returns `422 idempotency_key_reused` |
| Correlation | Send or receive `X-Correlation-Id`; it appears in error bodies and logs. Quote it to support |
| Rate limits | 120 requests per minute per user; sign-in 5 per minute per e-mail and IP. Over the limit: `429` with `Retry-After` |
| Versioning | Only additive changes within v1; removals or renames come with `/api/v2` and at least six months' notice |

### Status codes and common error codes

| Status | Meaning | Body |
|---|---|---|
| 400 | Missing organization header | `error.code`: `organization_required` |
| 401 | Not authenticated | `message: "Unauthenticated."`; at sign-in `error.code`: `mfa_required` |
| 403 | Not allowed | Missing permission: `message` only. Business rules: `error.code` such as `organization_access_denied`, `module_not_enabled`, `module_not_entitled`, `location_access_denied` |
| 404 | Not found or not visible to you | `message` |
| 409 | Conflicts with the current state | `error.code`, e.g. `shipment_not_in_transit`, `recommendation_not_open` |
| 422 | Invalid input or business rule | Validation: `errors {field: [messages]}`. Business rules: `error.code` with `details` |
| 429 | Too many requests | `message`; wait for `Retry-After` |

Business-rule errors always carry a stable `error.code`: branch on it, not on the message text. Every error body includes the `correlation_id`.

## 4. Areas of the API

| Area | Main paths | Module |
|---|---|---|
| Account | `auth/*`, `auth/mfa/*`, `auth/me` | — |
| Reference data | `reference/countries`, `reference/organization-types`, `reference/regulatory-authorities`, `reference/catalogue` | — |
| Organization | `organization`, `organization/documents`, `organization/onboarding-requirements`, `organization/regulatory-identifiers`, `organization/payment-methods`, `members`, `invitations`, `branches`, `warehouses`, `audit-logs` | — |
| Catalogue | `products`, `catalogue/items`, `product-change-requests` | catalogue |
| Inventory | `inventory/*`, `stock-adjustments`, `stock-transfers`, `stock-counts` | inventory |
| Procurement | `suppliers`, `purchase-requisitions`, `purchase-orders`, `goods-receipts`, `supplier-invoices`, `supplier-returns` | procurement |
| Sales | `customers`, `quotations`, `sales-orders`, `sales-invoices`, `customer-payments` | sales |
| Finance | `finance/accounts`, `finance/journal-entries`, `finance/payments`, `finance/periods`, `finance/reports/{report}` | finance |
| POS | `pos/terminals`, `pos/shifts`, `pos/sales`, `pos/refunds`, `pos/reports/{report}` | pos |
| Field force & CRM | `field-force/*`, `crm/*` | field_force |
| Offline sync | `devices`, `sync/batches`, `sync/status`, `sync/issues` | field_force or pos |
| Marketplace | `marketplace/*` | marketplace |
| Traceability & recalls | `trace/*`, `recalls`, `recall-notices`, `regulatory-incidents` | — |
| Logistics | `logistics/*` | logistics or inventory |
| Analytics | `analytics/*` (dashboards, forecast, recommendations, assistant) | analytics |
| Integration gateway | `integration/*` (configuration), `integration/inbound/*` (connector key) | integrations |
| Administration | `admin/*` (platform staff) | — |

## 5. Worked examples

All examples use `curl`; replace the host, token and ids.

### Stock levels

```bash
curl -s https://network.example.com/api/v1/inventory/stock-levels?per_page=50 \
  -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG" -H "Accept: application/json"
```

### Create and confirm a sales order

```bash
curl -s -X POST https://network.example.com/api/v1/sales-orders \
  -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG" \
  -H "Content-Type: application/json" -H "Idempotency-Key: so-2026-10-22-0001" \
  -d '{"customer_id": "01J…", "warehouse_id": "01J…",
       "lines": [{"product_id": "01J…", "package_level_code": "pack", "quantity": "10", "unit_price": "1200"}]}'

curl -s -X POST https://network.example.com/api/v1/sales-orders/01J…/confirm \
  -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG" -H "Idempotency-Key: so-0001-confirm"
```

The response carries the order with each line's price, discount, tax and total. If a line exceeds the discount limit or the customer's credit limit, the order waits for approval (`status: pending_approval`).

### Receive stock

```bash
curl -s -X POST https://network.example.com/api/v1/inventory/receipts \
  -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG" \
  -H "Content-Type: application/json" -H "Idempotency-Key: grn-ext-4471" \
  -d '{"warehouse_id": "01J…", "reason_code": "supplier_delivery", "reference": "DN-4471",
       "lines": [{"product_id": "01J…", "batch_number": "AMX2026A", "expiry_date": "2028-12-31",
                  "package_level_code": "pack", "quantity": "100", "unit_cost": "40"}]}'
```

### Scan a pack

Public check (no token; rate-limited; never names the organizations involved):

```bash
curl -s "https://network.example.com/api/v1/trace/scan?code=(01)06901234000016(17)281231(10)AMX2026A"
```

Members scanning in their own operation use `POST trace/scan {"code": "…"}` with their token and organization, which also records the scan.

The `result` is `ok`, `unknown`, `expired`, `recalled`, `revoked`, `dispensed` or `unregistered`, with a message and any warnings (for example a printed expiry that differs from the recorded one). A recorded pack is not proof of authenticity, and every answer says so.

### Ask the reporting assistant

```bash
curl -s -X POST https://network.example.com/api/v1/analytics/assistant \
  -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG" -H "Content-Type: application/json" \
  -d '{"question": "What is at risk of expiring?"}'
```

## 6. Good practice for API clients

1. Use a dedicated account per system with only the roles it needs (for example an **Integration administrator** or a read-only **Auditor**).
2. Store tokens in a secret store; never in source code. Renew before the 30-day expiry.
3. Send an `Idempotency-Key` on every write, derived from your own document number, so retries never double-post.
4. Retry `429`, `503` and network errors with exponential backoff; never retry `4xx` validation errors unchanged.
5. Log the `correlation_id` of failed calls.
6. Treat amounts and quantities as decimal strings end to end.
