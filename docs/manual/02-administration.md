# Administration guide

For platform staff who run the network, and for organization administrators who run their own organization on it.

## 1. Who administers what

| Role | Scope | Typical tasks |
|---|---|---|
| Platform super administrator | Whole platform | Everything below; branding, landing page, security policy, countries |
| Platform operations administrator | Whole platform | Organizations, users, subscriptions, modules, operations, marketplace fees |
| Compliance / verification officer | Whole platform | Verify organizations, review catalogue changes, verify identifiers and traceability events, run recalls |
| Organization owner / administrator | One organization | Members, roles, locations, modules in use, integrations, documents |

Platform roles never give access to an organization's own data: staff see what the platform needs (verification documents, recall exposure, totals), not the organization's books.

Administration screens on the web: **Administration → Verification queue**, **Landing page** and **Documentation**. Everything else is available through the administration API (`/api/v1/admin/...`, Section 10) and is audited.

## 2. Security policy

| Setting | Where | Effect |
|---|---|---|
| Two-factor mode | `PUT /admin/security-settings {"mfa_mode": ...}` | `off`, `optional`, `required_for_platform_staff`, `required_for_all` |
| Email verification | `PUT /admin/security-settings {"email_verification_required": true}` | New accounts must verify their e-mail |
| Reset a user's two-factor | `POST /admin/users/{id}/mfa-reset` | When a phone is lost; audited |
| Suspend / reactivate a user | `POST /admin/users/{id}/suspend` · `/reactivate` | Revokes access immediately |

Before requiring two-factor for staff, enrol your own account (web: **Account → Security**). Make sure e-mail delivery works before requiring verification.

## 3. Organizations

### Verification queue (web)
1. Open **Administration → Verification queue**. Organizations waiting for review are listed with their documents.
2. Open the documents, check them against the country's requirements (the checklist shows what the country and organization type require, e.g. a Nigerian pharmacy's PCN premises licence).
3. **Approve**, **Request changes** (notes required) or **Reject** (notes required). A reviewer cannot decide on an organization they belong to.

Verified organizations can trade on the marketplace and propose catalogue changes.

### Lifecycle (API)
| Task | Request |
|---|---|
| List and inspect | `GET /admin/organizations` · `GET /admin/organizations/{id}` |
| Suspend / reactivate | `POST /admin/organizations/{id}/suspend {reason}` · `/reactivate` (no data is deleted) |
| Enable or disable modules | `PUT /admin/organizations/{id}/modules` |
| Subscription | `GET`/`PUT /admin/organizations/{id}/subscription` |
| Entitlement overrides | `PUT /admin/organizations/{id}/entitlement-overrides {key, enabled, limit, reason}` |

A module works only when it is enabled **and** the organization's plan includes it. Plans and their entitlements: `GET /admin/subscription-plans`, `PATCH /admin/subscription-plans/{plan}`.

## 4. Countries

Each country is configuration: formats, phone and address rules, tax identity, verification documents and licences per organization type, regulatory deadlines, payment providers and data region.

1. `GET /admin/countries/{code}` shows the profile, tax rates, regulators and the **readiness checklist**.
2. Fill the gaps: `PATCH /admin/countries/{code}` (profile), `POST /admin/countries/{code}/tax-rates` (effective-dated), `POST /admin/regulatory-authorities/{code}/identifier-types`.
3. Record the tax, regulatory and legal reviews with a reference (`settings.reviews`).
4. `POST /admin/countries/{code}/activate`. Activation is refused while any blocking check fails. Deactivating only stops new registrations.

Exchange rates for platform reports: `POST /admin/exchange-rates` (with the source of the rate). They are never applied to an organization's books.

## 5. Product catalogue

| Task | Request |
|---|---|
| Review changes proposed by organizations | `GET /admin/product-change-requests` · `POST …/{id}/approve` · `/reject` |
| Create or edit a product | `POST /admin/products` · `PATCH /admin/products/{id}` |
| Add a regulatory identifier | `POST /admin/products/{id}/regulatory-identifiers` |
| Record a verification check | `POST /admin/regulatory-identifiers/{id}/verifications` |

A verification record is the platform's own check; it is never presented as a statement by the regulator.

## 6. Traceability, recalls and incidents (compliance)

- **Lots and integrity:** `GET /admin/trace/lots?...`, `GET /admin/trace/integrity/{organizationId}` (recomputes the organization's hash chain).
- **Verify an event:** `POST /admin/trace/events/{id}/verifications` (corroborated, independently verified or disputed).
- **Recall:** prepare with `POST /admin/recalls`; a **second** compliance officer activates it with `POST /admin/recalls/{id}/activate`. Activation quarantines the lots in every holder's stock and notifies them. Follow progress with `GET /admin/recalls/{id}/exposure`; close when every notice is acknowledged (or with an override reason).

## 7. Marketplace

Fees: `GET /admin/marketplace/fees`, waive with a reason `POST /admin/marketplace/fees/{id}/waive`. Escalated disputes: `GET /admin/marketplace/disputes`, add notes `POST …/{id}/notes`.

## 8. Branding, landing page and documentation

- **Branding** (name, colours, logo, support contacts): `PUT /admin/branding`.
- **Landing page** (web: **Administration → Landing page**): every text, link, figure, network link, feature, phone screen and the hero background (particles or video). Changes are published with **Publish** and recorded in the audit log; **Reset to defaults** restores the original content.
- **Documentation** (web: **Administration → Documentation**): these manuals, with a PDF download per document and **Download all (PDF)**.

## 9. Operations

| Check | Where |
|---|---|
| Readiness | `GET /api/v1/health` |
| Queues, failed jobs, review queues | `GET /admin/operations/status` |
| Failed jobs | `GET /admin/operations/failed-jobs` · `POST …/{uuid}/retry` |
| Audit trail | `GET /admin/audit-logs` (platform), `GET /audit-logs` (organization) |
| Platform figures by country | `GET /admin/analytics/platform?reporting_currency=USD` |

## 10. Organization administration (owners and administrators)

| Task | How |
|---|---|
| Profile, address, tax number | `PATCH /organization` (country formats are validated) |
| Verification | Upload documents (`POST /organization/documents`), add licences (`POST /organization/regulatory-identifiers`), check `GET /organization/onboarding-requirements`, then `POST /organization/verification/submit` |
| Branches and warehouses | `POST /branches`, `POST /warehouses` |
| Invite members | `POST /invitations {email, roles[]}`; the owner role can only be granted by an owner |
| Roles and locations | `PUT /members/{id}/roles`; `PUT /members/{id}/locations` restricts a member to some branches or warehouses (they then see only those) |
| Remove access | `PUT /members/{id}/status {"status": "suspended"}`; the last owner cannot be removed |
| Devices | `GET /devices`, revoke a lost phone or till |
| Integrations | See *Connecting an external system* |
| External AI for the assistant | `PUT /analytics/assistant/settings` with an explicit acknowledgement (only if the platform offers it) |

## 11. Organization roles

| Role | Main permissions |
|---|---|
| Owner | Everything in the organization |
| Administrator | Everything except ownership transfer |
| Finance manager / Accountant | Ledger, payments, reports / bookkeeping |
| Inventory manager / Warehouse officer | Stock, quality, counts, transfers / receiving and moving stock |
| Procurement officer / Hospital procurement officer | Requisitions, purchase orders, receipts |
| Sales manager / Sales representative | Orders, prices, approvals / own customers and orders |
| Field force supervisor | Team visits, plan approvals, targets |
| Pharmacist | Dispensing prescription-only medicines, quality |
| POS cashier / POS supervisor | Selling / cash, refunds, shift review |
| Logistics coordinator / Driver | Shipments / delivery, temperatures, proof of delivery |
| Integration administrator | Connectors, mappings, webhooks |
| Auditor | Read-only access to records and the audit trail |
