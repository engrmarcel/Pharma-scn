# User guide

How people in member organizations use the platform day to day. Each section names the web screens to use. Menu paths read **Workspace → Area → Screen**. The same work can be done by connected systems through the API (paths are relative to `/api/v1`; see the API guide), and every web screen goes through the same API rules.

## 1. Getting started

### Create an account and sign in
- **New to the platform:** choose **Create an account** on the sign-in page, enter your name, work e-mail and a password (at least 10 characters, with letters and numbers). You are signed in straight away.
- **Sign in:** open the platform address, choose **Sign in**, enter your e-mail and password (and your two-factor code if two-factor is on for your account).
- **Forgotten password:** choose **Forgot your password?** on the sign-in page. The e-mailed link opens a page where you choose a new password; changing it also signs you out of the mobile apps.
- **Field force app:** enter the server address provided by your administrator, sign in, then choose your organization and the mode (field work or POS).

### Register a new organization, or join one
After creating an account, the dashboard offers two choices:

1. **Register your organization:** name, type (pharmacy, distributor, manufacturer, hospital, …), country and contact details. You become its owner. It starts with the modules for its type on the default plan, and unverified.
2. **Accept an invitation:** open the link in the invitation e-mail, or paste the code from it. The invitation must have been sent to the e-mail address you signed up with.

Then complete verification in **Organization → Profile & verification**: the checklist shows what your country and organization type require. Complete the address and licence numbers (for example a pharmacy's PCN premises licence in Nigeria), upload the documents, then **Submit for verification**. Platform compliance staff review it and you are notified of the decision.

Invite your team in **Organization → Members & roles** and give each person roles and, if needed, specific branches or warehouses.

### Choose your organization
If you belong to several organizations, pick the one to work in from the **Organization** menu. Everything you see and do applies to that organization only; a member restricted to some branches or warehouses sees only those.

### Finding screens
The **Workspace** menu groups every area you can use: supply chain, commerce, field and CRM, finance and insight, connect. You see an area only when your organization has its module and your role has a permission in it. Each screen has a short description under its title and tabs for the other screens of the same area.

## 2. Inventory and warehouses

| Task | Screen |
|---|---|
| See stock | **Inventory → Stock on hand** (by product, warehouse and state; expiring and low stock filters), **Batches** |
| Receive stock without an order | **Inventory → Receive stock**: batch number, expiry and quantity per line |
| Adjust or write off | **Inventory → Adjustments**, with a reason (damage, expiry, recall, count difference) |
| Move between warehouses | **Inventory → Transfers**: create, dispatch, then receive at the other end |
| Count | **Inventory → Stock counts**: start a count, enter counted quantities, post |
| Quarantine or release a batch | **Inventory → Batches**, open the batch (pharmacist or inventory manager) |
| Ledger | **Inventory → Movements** |

Rules you will notice: stock is issued **first expiry, first out**; expired, quarantined and recalled batches cannot be sold; every movement is written to the stock ledger and the traceability record. Allocation and expiry rules are in **Inventory → Settings**.

## 3. Purchasing

1. **Procurement → Requisitions**: create, submit and approve; or accept a reorder suggestion (**Reorder suggestions**, or a recommendation in **Analytics → Recommendations**), which creates a draft requisition for you.
2. **Procurement → Purchase orders**: orders above your organization's threshold need approval by someone else; then **Send** it to the supplier.
3. **Procurement → Goods receipts**: record each batch, expiry and quality status against the order.
4. **Procurement → Supplier invoices**: pre-filled from the order and matched against the order and the receipt (three-way match). An unmatched invoice needs an override reason to approve.
5. **Finance → Payments**: pay the supplier and allocate the payment to invoices.

Returns to a supplier: **Procurement → Supplier returns**. Suppliers themselves are kept in **Customers & suppliers → Suppliers**.

## 4. Sales and customers

1. **Customers & suppliers → Customers**: payment terms, credit limit, contacts, price list; the statement is on the customer.
2. **Sales → Quotations** (optional), converted into a **Sales order**. Prices come from **Price lists**; discounts above your limit and orders over the credit limit wait for approval.
3. **Confirm** the order: stock is reserved, first expiry first out.
4. **Deliver**: stock leaves the warehouse.
5. **Invoice**, then record the customer's payment in **Sales → Payments** (allocated oldest invoice first, or by hand).
6. Returns: a **credit note** on the invoice (**Sales → Invoices**); returned goods go back as quarantined until checked.

Overdue balances: **Sales → Receivables aging**.

## 5. Point of sale

At the till (**Point of sale → Register**):

1. **Open a shift** with the opening cash.
2. Scan or search products; the till proposes the batch (first expiry). Prescription-only medicines need a pharmacist and prescription details.
3. Take payment: cash (change is calculated), a standalone card terminal (approval code, checked later by a supervisor) or an electronic provider enabled in your country.
4. Print or reprint the receipt (it shows batch numbers for recalls).
5. **Close the shift**: count the cash; a supervisor reviews differences.

Back office for supervisors: **Shifts** (cash movements, closing and reviewing shifts), **Refunds** (requested by cashiers, approved by a different person), **Pending payments** (confirm card and transfer payments), **Reports** and **Terminals**.

The till keeps working offline. Sales captured offline are sent when the connection returns; if stock was no longer available, a **sync issue** is raised for the supervisor in **Devices & sync → Sync**.

## 6. Field force app

| Tab | What it does |
|---|---|
| My day | Today's approved route plan in visiting order, with progress |
| Customers | Your customers (only those in your territories), with history |
| Visits | Check in at the customer (location is captured and verified against the customer's location), record notes, detailing with approved key messages and samples, competitor information, follow-ups, then check out |
| Orders | Capture orders; they are requests until the company's sales team accepts them |
| Follow-ups | Tasks created from visits and by automation |

Everything works without signal. Items show **Waiting to sync** until they reach the server; the app retries automatically. The location notice in the menu explains how location is used.

On the web, **Field force** has the supervisor's **Field force dashboard**, **Visits**, **Field orders** (submit into a sales order), **Follow-ups**, **Territories** and the **Activity report**. Visits logged on the web carry no device location and stay unverified.

## 7. CRM

**CRM → Accounts** holds customer profiles (tier, visit frequency, potential) and contacts with recorded consent. Alongside: **Visit plans** (built with suggested customers, approved by a supervisor), **Opportunities** (pipeline by stage), **Targets**, **Interactions**, **Feedback** (possible adverse events are flagged for pharmacovigilance), **Detailing** (approved key messages; a second person approves), **Segments**, **CRM analytics** and **Automation**, which creates follow-ups for missed plans, overdue visits and stale opportunities.

## 8. Marketplace

1. Suppliers publish a **Profile** and **Listings** (price, minimum order, availability, allowed buyer types).
2. Buyers **Discover** suppliers, request a **trading relationship** (approved in **Trading partners**), send **RFQs** and compare **Quotes**.
3. A marketplace **Order** creates the buyer's purchase order and the supplier's sales order; the supplier ships and invoices; the buyer receives.
4. Problems are raised as **Disputes** on the order. Marketplace fees charged to suppliers are listed in **Fees**.

## 9. Traceability and recalls

- **Scan a pack** (**Traceability → Verify a pack**; GS1 DataMatrix, barcode or the platform's QR code): the result tells you whether the product and batch are recorded, expired or **recalled**. Anyone can scan a platform QR code at `/t/{code}`.
- **Identifiers:** issue batch, product and SSCC identifiers with their QR code.
- **Lot history:** **Traceability → Lot history** shows every recorded movement of a batch you took part in; **Events** lists your own events and **Integrity** checks your hash chain and downloads EPCIS.
- **Recall notice:** when a batch you hold is recalled, your stock is quarantined automatically and you receive a notice and an e-mail. In **Recalls & incidents → Recall notices**, **acknowledge** it, return or destroy the stock, then **complete** the notice.
- **Issuing a recall** (brand owners): **Recalls & incidents → Recalls**; a second person activates it.
- **Incidents:** record stolen, lost or unscannable packs in **Regulatory incidents**; your country's reporting deadline is shown.

## 10. Logistics and cold chain

In **Logistics → Shipments**, create a shipment from a delivery or transfer, assign a carrier, driver, vehicle and temperature logger, then dispatch. Record tracking events, temperatures and the **proof of delivery** (receiver, condition, delivered quantities, signature). Temperature excursions alert the shipper, who records an assessment. **Reconciliation** compares shipped, delivered and returned quantities; undelivered goods are corrected through the original document.

## 11. Finance

**Finance → Reports** has the profit and loss, balance sheet, trial balance, account ledgers and payables aging. **Journal entries** (balanced manual entries and reversals), **Payments** (supplier payments), **Chart of accounts** and **Periods** (close and reopen) complete the area.

## 12. Analytics and the reporting assistant

- **Executive dashboard** and area **Dashboards**: sales, purchases, stock value, expiry risk, POS, marketplace, field force, integrations and recalls, limited to what your role and locations allow.
- **Forecast:** weekly demand with a range and the model's measured accuracy.
- **Recommendations:** reorder, overstock, expiry risk and anomalies. They are suggestions: you accept (a reorder becomes a draft requisition) or dismiss them with a reason.
- **Assistant:** ask a question in plain English, for example "What were our sales last month?". Answers come from your own reports and are labelled as generated; the assistant cannot change anything.

## 13. Connected systems and devices

- **Integrations** (integration administrators): connectors, field mappings, messages received (retry, ignore or fix a code and reprocess), runs, webhooks, external references and health. See *Connecting an external system*.
- **Devices & sync:** registered tills and phones (revoke or wipe a lost one), and the issues raised by offline transactions.

## 14. Getting help

- Each error message includes a reference (`correlation_id`); quote it to support.
- Your organization's administrator manages members, roles and locations.
- Platform support contacts (e-mail and phone) are shown in the top bar of the web application.
