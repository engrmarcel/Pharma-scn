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

Platform staff work in the **Administration** menu; each person sees only the screens their role allows. Every screen calls the administration API (`/api/v1/admin/...`), which is also available to scripts, and every change is audited. Organization owners and administrators work in the **Organization** menu (Section 10).

## 2. Security policy

| Setting | Where | Effect |
|---|---|---|
| Two-factor mode | **Administration → Security policy** (`PUT /admin/security-settings`) | Off, optional, required for platform staff, required for everyone |
| Email verification | **Administration → Security policy** | New accounts must verify their e-mail |
| Reset a user's two-factor | **Administration → Users**, **Reset two-factor** (`POST /admin/users/{id}/mfa-reset`) | When a phone is lost; a reason is required and audited |
| Suspend / reactivate a user | **Administration → Users** (`POST /admin/users/{id}/suspend` · `/reactivate`) | Revokes access immediately; you cannot suspend yourself |

Before requiring two-factor for staff, enrol your own account (web: **Account → Security**). Make sure e-mail delivery works before requiring verification.

## 3. Organizations

### Verification queue (web)
1. Open **Administration → Verification queue**. Organizations waiting for review are listed with their documents.
2. Open the documents, check them against the country's requirements (the checklist shows what the country and organization type require, e.g. a Nigerian pharmacy's PCN premises licence).
3. **Approve**, **Request changes** (notes required) or **Reject** (notes required). A reviewer cannot decide on an organization they belong to.

Verification opens trading in prescription-only and controlled products on the marketplace (other products can be traded before it) and lets the organization propose catalogue changes.

### Lifecycle
In **Administration → Organizations**, search or filter by verification status, state and type, then open an organization:

| Tab | What you can do | API |
|---|---|---|
| Profile | Details, verification history, download documents | `GET /admin/organizations/{id}` |
| Modules | Turn modules on or off | `PUT /admin/organizations/{id}/modules` |
| Subscription | Change the plan or status; add an entitlement override with a reason | `PUT …/subscription` · `PUT …/entitlement-overrides` |
| Status | Suspend with a reason, or reactivate (no data is deleted) | `POST …/suspend` · `/reactivate` |

A module works only when it is enabled **and** the organization's plan includes it. Plans, prices, trial days and entitlements (modules, features and limits) are edited in **Administration → Subscription plans**; organizations on a plan get the change straight away.

## 4. Countries

Each country is configuration: formats, phone and address rules, tax identity, verification documents and licences per organization type, regulatory deadlines, payment providers and data region.

In **Administration → Countries**, open a country:

1. **Readiness** lists every check (currency, time zone, locale, tax rates, regulators, required identifiers and documents, incident deadlines, payments) as pass, warning or fail.
2. Fill the gaps: **Details** (name, currency, time zone, locale, phone code), **Profile** (the country settings as JSON; lists are replaced, not merged), **Tax rates** (effective-dated; an open rate for the same category ends the day before), **Regulators** (identifier types with an optional format pattern and example).
3. **Reviews:** record the tax, regulatory and legal reviews; a completed review needs a reference.
4. **Activate**. Activation is refused while any blocking check fails. **Deactivate** (with a reason) only stops new registrations.

Exchange rates for platform reports: **Administration → Exchange rates** (with the source of the rate). They are never applied to an organization's books.

## 5. Product catalogue

In **Administration → Product master**:

| Task | Where | API |
|---|---|---|
| Review changes proposed by organizations | **Change requests** tab: approve or reject with notes | `POST /admin/product-change-requests/{id}/approve` · `/reject` |
| Create or edit a product | **New product**, or open a product and **Edit** | `POST /admin/products` · `PATCH /admin/products/{id}` |
| Add a regulatory identifier | Open the product, **Add regulatory identifier** | `POST /admin/products/{id}/regulatory-identifiers` |
| Record a verification check | **Record a check** on the identifier | `POST /admin/regulatory-identifiers/{id}/verifications` |

A verification record is the platform's own check; it is never presented as a statement by the regulator.

## 6. Traceability, recalls and incidents (compliance)

- **Lots and integrity** (**Administration → Traceability**): choose a product and batch to see its history across every organization; check an organization's hash chain.
- **Verify an event:** **Verify** on an event in the lot history records an independent check (independently verified or disputed), the method and the evidence.
- **Recall** (**Administration → Recalls**): prepare it; a **second** compliance officer activates it. Activation quarantines the lots in every holder's stock and notifies them. Follow progress in the exposure view; close when every notice is acknowledged (or with an override reason).

## 7. Marketplace

Organizations that buy or sell medicines (manufacturers, importers, distributors, wholesalers, pharmacies and chains, hospitals, health facilities and institutional buyers) have the marketplace switched on when they register; turn it off or on for one organization in **Administration → Organizations → Modules**. Who may buy from whom, and which products need both parties verified, is set in `config/marketplace.php`.

**Administration → Marketplace**: escalated disputes (open one to read the messages and add a platform note both parties see; the supplier resolves it) and fees (totals by status; waive an accrued fee with a reason).

## 8. Branding, landing page and documentation

- **Branding** (**Administration → Branding**): name, colours, logo, favicon, e-mail sender and support contacts, with a preview.
- **Landing page** (web: **Administration → Landing page**): every text, link, figure, network link, feature, phone screen and the hero background (particles or video). Changes are published with **Publish** and recorded in the audit log; **Reset to defaults** restores the original content.
- **Documentation** (web: **Administration → Documentation**): these manuals, with a PDF download per document and **Download all (PDF)**.

## 9. Operations

| Check | Where |
|---|---|
| Readiness | `GET /api/v1/health` |
| Queues, integration backlogs, review queues | **Administration → Operations** (refreshes every 30 seconds) |
| Alerts | **Administration → Operations**, top of the page. Checked every minute: queue backlog and age, failed jobs, whether the scheduler and queue workers are running, webhook, integration and offline-sync backlogs, and the nightly consistency check. Operations staff are e-mailed when an alert opens and when it clears |
| Failed jobs | **Administration → Operations**: **Retry** sends a job back to the queue (payloads are not shown) |
| Audit trail | **Administration → Audit log** (platform); **Organization → Audit log** (organization) |
| Platform figures by country | **Administration → Platform analytics**, optionally in one reporting currency |

## 10. Organization administration (owners and administrators)

| Task | Screen |
|---|---|
| Profile, address, tax number | **Organization → Profile & verification** (country formats are validated) |
| Verification | Same screen: the checklist shows what your country requires; add licences, upload documents, then **Submit for verification** |
| Branches and warehouses | **Organization → Branches & warehouses** (with bins) |
| Invite members | **Organization → Members & roles**, invite by e-mail with roles; the owner role can only be granted by an owner |
| Roles and locations | Open a member: roles, and the branches or warehouses they are restricted to (they then see only those) |
| Remove access | Suspend or remove the member; the last owner cannot be removed |
| Plan and usage | **Organization → Subscription** |
| Devices | **Devices & sync → Devices**: revoke or wipe a lost phone or till |
| Integrations | **Workspace → Integrations**; see *Connecting an external system* |
| External AI for the assistant | **Analytics → Assistant** settings, with an explicit acknowledgement (only if the platform offers it) |

## 11. Setting up point of sale on a desktop

For organizations that sell from a computer at the counter with the **POS terminal** (**Workspace → Point of sale → POS terminal**). The POS app (tablet or phone) is set up the same way and can be used alongside it, for example as a backup during outages.

1. **Module:** the organization needs the POS module (on by default for pharmacies and pharmacy chains) and a plan that includes it; platform staff turn it on in **Administration → Organizations → Modules** if needed.
2. **Terminals:** in **Point of sale → Terminals**, register one terminal per till, each linked to the store (warehouse) it sells from. A terminal has one open shift at a time. The plan's *POS terminals* limit applies.
3. **Prices and tax:** products sell at the default price list (**Sales → Price lists**) for the pack size sold; a product with no price cannot be sold. Tax comes from the product's tax category and the country's rates.
4. **People and roles** (**Organization → Members & roles**):
   - *POS cashier*: sells, opens and closes their own shift, requests refunds.
   - *Pharmacist*: also sells prescription-only medicines (records the prescription).
   - *POS supervisor*: also records cash in and out, cancels sales after payment, approves discounts above the limit, confirms terminal payments, reviews shifts and approves refunds.
   - Restrict a member to their branch or store under **Locations**, so they see only that store's terminals and stock.
5. **Counter computer:** a current browser (Chrome or Edge), signed in as the cashier. A USB barcode scanner works as a keyboard (no setup). For receipts, set the 72–80 mm receipt printer as the browser's default printer; for printing without the print dialog, run the browser in kiosk printing mode (for example Chrome's `--kiosk-printing` option) on that computer. The terminal needs a connection to the server.
6. **Settings:** the largest discount without approval is in **Sales → Settings**. Which medicines are prescription-only comes from each product's classification for your country in the product master.

Shifts, cash differences, refunds and pending card payments are reviewed in the other **Point of sale** screens; sales from the terminal and the app appear together in **Point of sale → Reports** and **Analytics → Dashboards**.

## 12. Organization roles

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
