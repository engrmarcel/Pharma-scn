# User guide

How people in member organizations use the platform day to day. Each section names the screens to use and, for work done through connected systems or the API, the API calls (paths are relative to `/api/v1`; see the API guide).

## 1. Getting started

### Sign in
- **Web:** open the platform address, choose **Sign in**, enter your e-mail and password (and your two-factor code if your organization requires it).
- **Field force app:** enter the server address provided by your administrator, sign in, then choose your organization and the mode (field work or POS).
- Forgotten password: request a reset link with `POST auth/forgot-password {email}` (your support desk or app does this for you); the link lets you choose a new password.

### Choose your organization
If you belong to several organizations, pick the one to work in from the **Organization** menu. Everything you see and do applies to that organization only; a member restricted to some branches or warehouses sees only those.

### Register a new organization
Registration and verification are done through the API (by your onboarding application or with support's help):

1. Create an account: `POST auth/register`, then register the organization: `POST organizations {name, type, country_code}` (type: pharmacy, distributor, manufacturer, hospital, …).
2. The organization starts with the modules for its type on a trial plan.
3. Check what your country requires: `GET organization/onboarding-requirements`. Upload the documents (`POST organization/documents`), add licence numbers (`POST organization/regulatory-identifiers`, for example a pharmacy's PCN premises licence in Nigeria) and complete your address (`PATCH organization`).
4. Submit: `POST organization/verification/submit`. Platform compliance staff review it in their verification queue; you are notified of the decision.
5. Invite your team and give each person a role (`POST invitations`).

### Web screens

| Screen | For | What you can do |
|---|---|---|
| Dashboard | Everyone | Headline figures, expiring stock, today's sales, quick links |
| Product catalogue | Everyone | Search the shared product master by name, SKU, GTIN or registration number |
| POS register | Cashiers, pharmacists | Sell over the counter, take payment, print receipts |
| Field force dashboard | Supervisors | Team visits today, plan approvals, targets, pipeline |
| Analytics | Managers | Executive dashboard, recommendations, ask questions in plain English |
| Account security | Everyone | Password, two-factor, e-mail verification |

Other operations (purchasing, warehouse documents, finance, marketplace, logistics) are done through the API by your own systems or connected applications.

## 2. Inventory and warehouses

| Task | How |
|---|---|
| See stock | `GET inventory/stock-levels`, `inventory/balances` (by batch and state), `inventory/expiring?days=90`, `inventory/low-stock` |
| Receive stock without an order | `POST inventory/receipts` with batch number, expiry and quantity |
| Adjust or write off | `POST stock-adjustments` with a reason (damage, expiry, recall, count difference) |
| Move between warehouses | `POST stock-transfers`, then `…/dispatch` and `…/receive` |
| Count | `POST stock-counts`, enter counted quantities, `…/post` |
| Quarantine or release a batch | Quality actions on the batch (pharmacist or inventory manager) |

Rules you will notice: stock is issued **first expiry, first out**; expired, quarantined and recalled batches cannot be sold; every movement is written to the stock ledger and the traceability record.

## 3. Purchasing

1. **Requisition** (`POST purchase-requisitions`), submitted and approved; or accept a reorder recommendation in **Analytics**, which creates a draft requisition for you.
2. **Purchase order** (`POST purchase-orders`); orders above your organization's threshold need approval by someone else; then **send** it.
3. **Goods receipt** (`POST goods-receipts`): record each batch, expiry and quality status.
4. **Supplier invoice** (`POST supplier-invoices`): matched against the order and the receipt (three-way match).
5. **Payment** (`POST finance/payments/supplier`).

Returns to a supplier: `POST supplier-returns`.

## 4. Sales and customers

1. Create the customer (`POST customers`) with payment terms and credit limit.
2. **Quotation** (optional) → **Sales order** (`POST sales-orders`). Prices come from price lists; discounts above your limit and orders over the credit limit wait for approval.
3. **Confirm**: stock is reserved, first expiry first out.
4. **Delivery** (`POST sales-orders/{id}/deliveries`): stock leaves the warehouse.
5. **Invoice** (`POST sales-orders/{id}/invoice`), then record the **payment** (`POST customer-payments`).
6. Returns: **credit note** on the invoice (`POST sales-invoices/{id}/credit-notes`); returned goods go back as quarantined until checked.

Customer statement: `GET customers/{id}/statement`.

## 5. Point of sale

1. **Open a shift** with the opening cash.
2. Scan or search products; the till proposes the batch (first expiry). Prescription-only medicines need a pharmacist and prescription details.
3. Take payment: cash (change is calculated), a standalone card terminal (approval code, checked later by a supervisor) or an electronic provider enabled in your country.
4. Print or reprint the receipt (it shows batch numbers for recalls).
5. **Close the shift**: count the cash; a supervisor reviews differences.

The till keeps working offline. Sales captured offline are sent when the connection returns; if stock was no longer available, a **sync issue** is raised for the supervisor. Refunds are requested by cashiers and approved by supervisors.

## 6. Field force app

| Tab | What it does |
|---|---|
| My day | Today's approved route plan in visiting order, with progress |
| Customers | Your customers (only those in your territories), with history |
| Visits | Check in at the customer (location is captured and verified against the customer's location), record notes, detailing with approved key messages and samples, competitor information, follow-ups, then check out |
| Orders | Capture orders; they are requests until the company's sales team accepts them |
| Follow-ups | Tasks created from visits and by automation |

Everything works without signal. Items show **Waiting to sync** until they reach the server; the app retries automatically. The location notice in the menu explains how location is used.

Supervisors approve visit plans, see their team's activity and targets on the **Field force dashboard**.

## 7. CRM

Customer profiles (tier, visit frequency, potential), contacts with consent, segments, approved detailing messages, visit plans, targets, opportunities and customer feedback (including possible adverse events, which are flagged for pharmacovigilance). Automation creates follow-ups for missed plans, overdue visits and stale opportunities.

## 8. Marketplace

1. Suppliers publish a **profile** and **listings** (price, minimum order, availability).
2. Buyers **discover** suppliers, request a **trading relationship**, send **RFQs** and compare quotes.
3. A **marketplace order** creates the buyer's purchase order and the supplier's sales order; the supplier ships and invoices; the buyer receives.
4. Problems are raised as **disputes** on the order.

## 9. Traceability and recalls

- **Scan a pack** (GS1 DataMatrix, barcode or the platform's QR code): the result tells you whether the product and batch are recorded, expired or **recalled**. Anyone can scan a platform QR code at `/t/{code}`.
- **Lot history:** `GET trace/lots?…` shows every recorded movement of a batch you took part in.
- **Recall notice:** when a batch you hold is recalled, your stock is quarantined automatically and you receive a notice and an e-mail. **Acknowledge** it, return or destroy the stock, then **complete** the notice.
- **Incidents:** record stolen, lost or unscannable packs (`POST regulatory-incidents`); the reporting deadline of your country is shown.

## 10. Logistics and cold chain

Create a shipment from a delivery or transfer, assign a carrier, driver and vehicle, then dispatch. Drivers record tracking events, temperatures and the **proof of delivery** (receiver, signature, photo, delivered quantities). Temperature excursions alert the shipper, who records an assessment. Undelivered goods are corrected through the original document.

## 11. Analytics and the reporting assistant

- **Executive dashboard** (web **Analytics**): sales, stock value, expiry risk, POS, field force, integrations and recalls, limited to what your role and locations allow.
- **Recommendations:** reorder, overstock, expiry risk and anomalies. They are suggestions: you accept (a reorder becomes a draft requisition) or dismiss them with a reason.
- **Ask a question** in the box on the Analytics page, for example "What were our sales last month?" or "What is at risk of expiring?". Answers come from your own reports and are labelled as generated; the assistant cannot change anything.

## 12. Getting help

- Each error message includes a reference (`correlation_id`); quote it to support.
- Your organization's administrator manages members, roles and locations.
- Platform support contacts (e-mail and phone) are shown in the top bar of the web application.
