# Changelog

## [Unreleased]

### 2026-10-26: Phase 7a, traceability ledger, QR and serialization
- `trace_events`: an append-only, hash-chained ledger recorded inside every stock posting (`InventoryLedger` → `TraceRecorder`) and on batch creation.
  - Events carry the counterparty organization from the document, locations, GLNs, and business and recording times.
  - Integrity check: `GET /trace/integrity`.
- Lot history and events (`/trace/lots`, `/trace/events`), shown to the organizations involved. Corrections and reversals are new events. Counterparty corroboration is automatic; independent verification and disputes are recorded by platform staff (`/admin/trace/…`).
- Identifiers (`/trace/identifiers`, `/trace/serials`):
  - opaque QR codes (SVG), shown with a regulatory notice for Nigerian organizations not to print them on packs;
  - GS1 serials, unique per GTIN across the platform;
  - SSCC aggregation and revocation.
- Scanning: public `GET /trace/scan`, the page `/t/{token}`, member `POST /trace/scan` (with own stock of the lot) and `POST /trace/dispense`. It reads tokens, GS1 element strings, raw DataMatrix and Digital Link. Results report recalled, expired, revoked, dispensed or unregistered, with the authenticity disclaimer.
- GLNs on organizations and warehouses. EPCIS 2.0 JSON-LD export (`GET /trace/export/epcis`).
- Regulatory review of the NAFDAC Pharmaceutical Products (Traceability) Regulations 2024 (S.I. No. 49 of 2024): `docs/REGULATORY_TRACEABILITY_REVIEW.md`. ADR-0015.
- Permissions `traceability.view`, `traceability.manage`, `traceability.view_all` and `traceability.verify`.

### 2026-10-25: Phase 6c, supervisor dashboards, CRM analytics and mobile workflows (Phase 6 complete)
- Supervisor dashboard (`GET /crm/dashboard/supervisor`, plus the web page `/field-force/dashboard` under Operations):
  - the day's visits and calls in progress, planned calls done and unverified check-ins;
  - per-representative orders, adherence and overdue follow-ups;
  - plans awaiting approval, approvable on the page;
  - pipeline, targets with pace, and feedback including possible adverse events.
- CRM analytics (`GET /crm/analytics?from&to&territory_id`): coverage and call-frequency compliance by tier and segment, visit outcomes and verification, visit-to-order conversion, pipeline win rate, detailing reach, feedback and interactions. Everything is limited to the viewer's customers.
- Mobile:
  - `GET /crm/my-day` (planned calls in route order, due follow-ups, approved key messages);
  - the app's My day tab;
  - offline detailing and feedback with the pharmacovigilance notice;
  - local database schema v3;
  - sync types `crm.detailing.record`, `crm.feedback.record` and `crm.interaction.log` (`CrmSyncHandler`, sharing `CrmRules` with the REST endpoints).
- Synthetic CRM demo data for the distributor.
- 241 server tests and 17 app tests passing; both live end-to-end app tests pass against a local server.

### 2026-10-25: Phase 6b, CRM planning and selling
- Visit plans (`/crm/visit-plans`):
  - submit, then approve or reject; approval needs `crm.plans.approve`, a different person, and visibility of every planned customer;
  - cancel, cancel individual calls, adherence;
  - nearest-neighbour route order (`…/route`);
  - suggestions (`/crm/visit-suggestions`);
  - a check-in marks the planned call as visited.
- Sales targets (`/crm/targets`, `crm.targets.manage`): invoiced revenue, product units, field-order value and completed visits per representative or territory, with attainment and pace. Figures are limited to the viewer's customers unless the viewer is the representative or has `view_all`.
- Leads and opportunities (`/crm/opportunities`):
  - pipeline with weighted value;
  - won only with a mobile or sales order, lost only with a reason;
  - lead conversion (`…/convert-customer`, `customers.manage`).
- Follow-up automation (`crm:automation`, daily 05:30; `/crm/automation/run|settings`, `crm.automation.manage`): missed planned calls, overdue visits, stale opportunities, and escalation to the territory supervisor. Each follow-up is created once per cause. Unavailable or rescheduled visits get a follow-up the next working day.
- Handover (`POST /crm/handover`): accounts, open follow-ups and opportunities move to another representative, and future calls are cancelled.
- Permissions `crm.plans.approve` (supervisors), `crm.targets.manage` and `crm.automation.manage`.
- 236 tests passing.

### 2026-10-25: Phase 6a, CRM foundations
- Customer CRM profile (`tier`, `visit_frequency_days`, `potential_value`) and the account list `GET /crm/customers`, with due and overdue visits and filters for segment, tier and territory.
- Segments (`/crm/segments`): manual, or rule-based on customer type, territory, tier, city, no completed visit in N days, and 12-month revenue. Refresh replaces rule members and keeps manual ones; member lists follow customer visibility.
- Professional contacts (`/crm/customers/{id}/contacts`, `/crm/contacts/{id}`) hold work data only. Consent to direct contact is recorded with time and source and enforced on outbound calls, e-mails and messages. Anonymization is irreversible; audit entries record field names only.
- Interactions (`/crm/interactions`) and structured feedback (`/crm/feedback`). A possible adverse event is flagged for pharmacovigilance and cannot be resolved without the safety case reference.
- Detailing: key messages (`/crm/detailing-messages`) are drafted, then approved by someone other than the author, and retired rather than edited. `POST /field-force/visits/{id}/detailing` records approved messages only, with samples by quantity and batch.
- Customer timeline (`GET /crm/customers/{id}/timeline`) across field, CRM and (with `sales.view`) ERP records.
- **Changed:** supervisors see a teammate's activity only for customers they can see themselves (`FieldForceScope::scopeActivity`, ADR-0014). Visits, orders, follow-ups and the activity report all follow it.
- Permissions `crm.accounts.manage`, `crm.segments.manage`, `crm.feedback.manage` and `crm.detailing.manage`.
- 230 tests passing.

### 2026-10-24: Phase 5c, integration outbox and webhooks (Phase 5 complete)
- Transactional outbox (`integration_outbox`). Domain events now go through `DomainEvents::raise()`: the outbound event and its deliveries are written in the business transaction, only for organizations with a matching subscriber, and delivery jobs are queued after commit.
- Webhook subscriptions (`/integration/webhooks`):
  - signing secret generated, shown once and encrypted; rotatable;
  - event catalogue at `/integration/webhooks/event-types`;
  - test event, delivery log, manual redelivery, pause/activate/disable;
  - payloads carry the subscriber connector's own product keys.
- Delivery is at least once, signed (`X-Webhook-Signature` = HMAC-SHA256 over timestamp and body; `X-Webhook-Id` for deduplication). Retries use backoff of 1, 5, 15, 60, 240 and 720 minutes; a delivery goes dead after 8 attempts. Receivers are paused automatically after 25 consecutive failures. Redirects are not followed.
- SSRF protection: HTTPS only, no credentials in URLs, no loopback, private, link-local or metadata addresses. Checked at creation and against the resolved addresses at every delivery, with the address pinned for the request.
- Reference reconciliation report (`/integration/connectors/{id}/reconciliation/references`); integration counts in the platform operations status; `integration:process-due` also sweeps webhook retries.
- Synthetic demo: an SAP S/4HANA connector for the demo manufacturer with processed and quarantined messages, and a paused webhook subscription to a `.invalid` address.
- 224 tests passing.

### 2026-10-24: Phase 5b, integration files, SFTP, SAP IDoc and exports
- File import (CSV, JSON, XML) by upload (`POST /integration/connectors/{id}/files`) or machine push (`POST /integration/inbound/files`), into the same durable inbox as the REST API. The same file content is imported only once. Unreadable files become rejected runs with the line number and appear in health.
- SFTP exchange (`integration:sftp-poll`, every 5 minutes, or on demand). The host-key fingerprint must be pinned; credentials are encrypted. File rules run in order. Imported files move to an archive folder and unreadable files to an error folder. Inventory and catalogue exports are pushed on an interval. Adds `league/flysystem-sftp-v3`.
- SAP IDoc adapter (`POST /integration/inbound/idoc`): IDoc XML from the customer's SAP middleware, with DOCNUM as the idempotent message id. MATMAS maps to product and BATMAS to batch; other message types are rejected with a reason. The platform makes no RFC or database connection to SAP.
- Mapping templates (`GET /integration/mapping-templates`, `POST …/mappings/{entity}/template`): SAP IDoc MATMAS/BATMAS, SAP table extracts (material, batch, stock), Dynamics 365 released products and Oracle EBS items. Each is a starting point to confirm against the customer's system.
- CSV exports (`GET /integration/connectors/{id}/exports/inventory|catalogue`) carry the target system's own product keys from external references, guard against formula injection, and are recorded as outbound runs and audited.
- Security: XML with a DOCTYPE is refused (XXE), SFTP connections to unverified hosts are refused, and transport errors appear in health without secrets.
- 219 tests passing.

### 2026-10-24: Phase 5a, enterprise integration gateway core
- External-system registry (`integration_connectors`): system type and version, deployment model, confirmed interface notes, inbound transport, status (draft, active, paused, disabled). Inbound keys are stored hashed, shown once and rotatable. HMAC request signing is optional, rate limits apply per connector, and credentials are encrypted and write-only. Connectors count against the plan's `integration_connectors` limit.
- Canonical Pharmaceutical Data Model covering all 17 §15.2 entities (`GET /integration/canonical-model`). Field mappings per connector and entity support trim, upper/lower, strip_leading_zeros, date formats, decimal separators and value maps, with a preview that has no side effects.
- Durable inbox (`POST /integration/inbound/messages`): records are stored before they are acknowledged. Idempotency is per `message_id`, with duplicate detection independent of key order. Processing is asynchronous, and the outcome is read back per message.
- Ingestion of products (matched to the Product Master by reference, GTIN or NAFDAC number; only the organization's catalogue item is written), batches (expiry conflicts quarantined; quarantine and rejection applied, release left to a person) and inventory snapshots (reconciliation report; stock is never moved).
- Failed-message handling: data problems are quarantined with actionable, redacted errors; unexpected errors retry with backoff and are dead-lettered after 5 attempts; manual retry, map-to-product or warehouse, and ignore with a reason. `integration:process-due` runs every minute. Per-connector health with counts.
- Permissions `integrations.view` and `integrations.manage`; the integration administrator role now has them. ADR-0013. Synthetic SAP-style sample dataset in `tests/fixtures/integration`.
- 214 tests passing.

### 2026-10-23: Phase 4c, marketplace fulfilment, invoicing, fees and disputes (Phase 4 complete)
- Shipments are posted through the supplier's sales-order delivery (FEFO, cost of goods) and carry batch numbers and expiries, plus carrier and tracking. The buyer receives into its purchase order with those batches, and short receipts are flagged.
- The supplier invoices delivered quantities; the buyer records the supplier invoice in its own books, three-way matched. Payment status is read from the supplier's receivables and labelled.
- Fees: an append-only ledger (`marketplace_fees`) charged on net invoiced value (`MARKETPLACE_FEE_PERCENT`, default 1.5%). Suppliers see a fee statement; platform staff see the ledger by supplier and can waive a fee with a reason. Amounts are never edited.
- Disputes: raised by either side, with messages. The supplier resolves (credit note in its books, returned goods restocked as quarantined; or a replacement or reasoned rejection). The buyer posts the supplier return in its own books. Either side can escalate; the platform comments but does not post.
- Order history timeline. Synthetic demo: the distributor lists two products and an order with the community pharmacy runs through receipt and invoice.
- Fix: when accepting without a chosen warehouse, the supplier now fulfils from the warehouse holding the most sellable stock of the ordered products. Found while building the demo.
- 205 tests passing.

### 2026-10-23: Phase 4b, RFQs, quotations and B2B orders
- RFQs: draft, then open to invited suppliers (who do not see each other), then closed, awarded or cancelled. Restrictions are checked per supplier and product when inviting.
- Quotations: answers to RFQ lines, or direct quotes to buyers with an approved relationship. A newer quote replaces the earlier one; quotes can be withdrawn, rejected or accepted. Comparison shows every offer per line, cheapest first. Accepting creates a draft order at the quoted prices and awards the RFQ.
- Orders from listings (minimum quantities, restrictions, approved relationship), then through submit, internal approval, supplier acceptance or rejection, and cancellation:
  - submitting raises the buyer's purchase order under the buyer's own procurement approval rules;
  - accepting creates the supplier's sales order at the agreed prices and confirms it, reserving stock FEFO;
  - a supplier rejection or cancellation cancels the buyer's purchase order, marked `[via marketplace]`;
  - each side sees only its own ERP document.
- ADR-0012 (shared records and the ERP link). 203 tests passing.

### 2026-10-23: Phase 4a, marketplace foundations
- Supplier marketplace profiles (listing switch, regions served, delivery options, minimum order) and product listings on the supplier's own catalogue item and pack. Availability is either live (read from the supplier's stock at search time, shown as a status band, never reserved) or declared (stated with its date, flagged stale after 7 days).
- Discovery by product, brand, ingredient, strength, dosage form, pack, supplier, supplier type, country, region served, delivery option, verification and availability. Every result carries the supplier's platform-verification label (not a regulator's approval), the product's restriction and whether this buyer may buy it.
- Pharmaceutical controls (`config/marketplace.php`): who may buy from whom by organization type. Prescription-only and controlled products need platform-verified parties and a licensed buyer type, and can only be listed by verified suppliers.
- Buyer/supplier relationships: the buyer requests, the supplier approves or rejects, either side suspends. Each side's ERP party (Supplier / Customer, linked to the counterpart organization) is created by that side in its own tenant.
- Permissions `marketplace.*` and `platform.marketplace.manage`. 199 tests passing.

### 2026-10-21: Phase 3c, encrypted offline app with till mode (Phase 3 complete)
- App storage is now SQLCipher (`sqflite_sqlcipher`), with a random 256-bit key per database file in Keystore/Keychain. A wipe destroys the key and the file. The schema moves to v2 with a migration.
- After sign-in the app registers the device (field or till), swaps the sign-in token for the device-bound token, and uses one database per user, organization and role.
- Sync moves to `POST /sync/batches`. Every outbox item has a stable `client_txn_id`, and per-envelope acknowledgements mark items synced, conflict, rejected or retry. Sync runs automatically every 15 s while queued work exists (backoff 2 s to 15 min with jitter) and on resume. A Sync status screen shows waiting, retrying and refused items, the last sync and the next try.
- Deauthorized devices are locked out, and erased (crypto-shred) when the administrator asked for a wipe.
- Till mode:
  - shifts open and close online (closing is refused while sales are unsent or refused);
  - selling works from the offline catalogue (`GET pos/terminals/{id}/offline-catalogue`; `GET pos/me`) by search or scan of SKU, barcode or batch;
  - cart totals are computed exactly as the ERP computes them;
  - prescription products need a pharmacist and prescription details;
  - payments: cash with change, and standalone card or transfer recorded as pending;
  - cached stock is lowered per sale, receipts are marked offline until synced, and server status and payment states are shown after sync.
- Fix: `pos_sales.client_transaction_id` widened to 64 characters for device UUIDs, found by the live end-to-end test.
- Six-hour outage scenario, server and device sides, with its workload profile, equipment, procedures and limits documented in `docs/OFFLINE_OUTAGE_PLAYBOOK.md`.

### 2026-10-21: Phase 3b, offline POS replay and stock reconciliation
- `pos.sale` sync envelopes from registered POS devices are recorded as they happened: the device's prices (variances raised for review), FEFO stock under the same locks as online, and the payments taken.
- Offline cash goes into the drawer and completes the sale, and is shown as `offline_cash_recorded` until the shift is reviewed. Standalone card and transfer payments stay pending until a supervisor confirms them. Electronic payments are never confirmed offline.
- Two tills selling the last units: the later sale stands, only existing stock is issued, and the rest is a `stock_shortfall` issue. Resolving it with `record_found_stock` receives the stock that was physically there and issues it to the sale, posting the missing cost of goods sold. No balance is overwritten or made negative.
- A batch recalled or expired while offline is still issued (it left the shop) and flagged `blocked_batch_sold`. Discount, prescription, payment-shortfall and late-shift findings become issues. Reviewed shifts and foreign terminals are refused.
- New columns: `pos_sales.captured_offline`, `client_completed_at`, `device_id`; `pos_sale_lines.unallocated_base_quantity`; `pos_payments.recorded_offline`. 193 tests passing.

### 2026-10-21: Phase 3a, device registration and the sync engine
- `devices` with device-bound API tokens (sliding expiry, `SYNC_DEVICE_TOKEN_TTL_HOURS`), registration per role (field, or POS with a terminal), and revocation by the owner or a device manager, optionally requesting a data wipe. `EnsureDeviceActive` refuses revoked devices (once with `device_revoked {wipe}`, then the token is gone) and blocks device tokens used for another organization.
- `POST /sync/batches`: envelopes are stored in `sync_inbox` before processing, processed in order in their own transactions, and acknowledged individually as synced, conflict, rejected or retry_required. Replays return the stored acknowledgement and are never applied twice; another device's transaction id is refused. Unsupported types or versions and future device clocks are rejected.
- Field-force envelope types (check-in, check-out, cancel, order capture and resubmit, follow-up completion) run through the same services and validation as the online endpoints (`FieldForceRules`), referencing records by client reference.
- Review: `sync/inbox` with resolution notes (refused work is never deleted), `sync/issues`, `sync/status`, `sync/reconciliation`. New permissions `devices.manage` and `sync.reconcile`. ADR-0011. 188 tests passing.

### 2026-10-21: Phase 2.6b, Flutter field app (Phase 2 complete)
- `mobile/field_app` (Flutter 3.47, Dart 3.13, Android first, iOS runner included).
- Sign-in with the API token in Keystore/Keychain; organization choice validated against `/field-force/me`; one local database per user and organization.
- Assigned-customer directory, cleared of customers that are no longer assigned; customer-priced catalogue per pack, with the age of the stock snapshot shown.
- Visits: one-shot location at check-in and check-out (permission, service and timeout states recorded; coordinates never guessed); notes, feedback, permission-gated competitor information, activities and follow-up date; server-decided verification shown after sync; first-use location notice.
- Orders: draft on device → waiting to sync → submitted → accepted by ERP (sales order number, stock reserved or awaiting approval) or rejected with the reason; edit and resubmit.
- Durable ordered outbox: offline work survives restarts. Connection and server errors retry later; refusals are listed under "Needs attention" together with the steps that depend on them. Replays are idempotent through `client_reference`.
- Follow-ups (offline completion), activity report for representatives and supervisors.
- Tests: 10 unit/widget tests (SQLite via FFI), a live-API end-to-end test, and a new CI job (analyze + test). The debug APK builds locally.

### 2026-10-21: Phase 2.6a, field-force API
- Territories (code, parent, fulfilment warehouse) with representative and supervisor members (limited by the `mobile_users` entitlement) and customer assignments (territory and/or representative).
- New permission `customers.view_assigned` (sales representatives, new `field_supervisor` role): customers, quotations, sales orders, invoices and statements are limited to assigned customers; hidden records return 404; organization-wide receivables aging is closed to them.
- Visits: check-in and check-out with device-reported location status, coordinates, accuracy, mock flag and device context; the server classifies the evidence (verified within the radius, outside radius, low accuracy, mock location, no location, customer location unknown), refuses contradictory reports and implausible clocks, and never edits evidence afterwards. Visit details, activities, competitor information (permission-gated), and automatic follow-ups from the follow-up date.
- Mobile orders: draft, then submit, then accepted (the ERP sales order is created and confirmed, or held for approval) or rejected with reasons inside a savepoint (no sales order or reservation left behind); edit and resubmit; indicative device prices never override ERP pricing.
- Customer-priced mobile catalogue (per sellable pack) with a stamped stock snapshot; customer directory with location-verification and visit history; follow-up tasks; supervisor activity report (visits, verification, orders, accepted value, follow-up compliance).
- Replay-safe device `client_reference` on visits, orders and follow-ups. Daily `field-force:purge-locations` removes raw coordinates after the retention period. ADR-0010.
- Synthetic demo: two distributor territories, a supervisor, a verified visit with an accepted mobile order, and a visit recorded with location permission denied. 184 tests passing.

### 2026-10-20: Web interface redesign
- Corporate navy and gold design system (Tailwind 4 tokens and components); brand colours still come from `BrandingService`, with new defaults `#0b4ea2` / `#0b1f42`. Inter and Playfair Display are self-hosted through `@fontsource` (no third-party font requests). Icons from `blade-ui-kit/blade-heroicons`.
- Header with a utility bar (support contacts, date, non-production badge, current organization), sticky main bar and hover/click/keyboard dropdown menus (Operations, Organization switcher, Administration, account menu), plus a mobile menu. Page banners with breadcrumbs; a multi-column footer.
- Split-screen sign-in with an animated supply-network illustration, icon inputs, show/hide password and a busy state; matching two-factor challenge page.
- Dashboard: greeting hero with organization summary, statistic cards with icons and count-up figures (stock, expiry, expired, low stock, stock value, purchase and sales orders, receivables, POS sales today), 7-day sales trend, expiring-soon list and platform oversight cards. Each figure appears only when the module is enabled and entitled and the member holds the view permission; stock figures respect warehouse scopes; money is shown in the organization currency only.
- `ResolveTenant` gains an optional mode (`tenant:optional`) used by the dashboard: a missing, foreign or suspended selection shows no figures instead of an error.
- Reduced-motion support; 177 tests passing.

### 2026-10-20: Phase 2.5b, POS cashier UI
- Livewire register (`/pos`): open shift, scanner-first search (SKU, GTIN or batch number + Enter adds an exact match), server-priced cart with quantities, prescription indicator, hold and resume, checkout, cash with change, standalone card-terminal entry (pending until confirmed), cancel, close shift.
- Printable 72 mm receipt (`/pos/receipts/{sale}`) with batch numbers and expiry, no prescription details.
- Tenant pages in the web app: organization from the switcher, re-validated by `ResolveTenant`; Livewire persistent middleware re-applies tenant, module and account-security checks on component updates.
- `PosCatalogue` lookup shared by the API and the register. 174 tests passing.

### 2026-10-20: Phase 2.5a, POS backend
- Terminals (limited by the `pos_terminals` entitlement); shifts with opening float, signed drawer movements, supervisor-only cash in/out, close with expected/counted/variance, and independent review.
- Sales: idempotent start by `client_transaction_id`, cart pricing (override and discount controls), scanned-batch validation, prescription-only and controlled dispensing controls, hold, checkout with FEFO or scanned-batch reservation, cancel (cash returned, reservations released).
- Payments: cash at the till; standalone terminals confirmed by a different user after settlement; provider payments confirmed only by verified, idempotent webhooks (`payment_provider_events`); a development-only fake provider. ADR-0009.
- Atomic completion through ERP services: stock out with batch evidence, COGS, `pos` invoice, receipts allocated to it.
- Refunds with independent approval, credit to the exact invoice lines, return to the original batches (quarantined by default), cash out of the open drawer or by bank transfer.
- Receipts (batch numbers and expiry, no prescription details) and POS reports (summary with gross margin, by product, cashier, payment method, terminal, refunds, shifts, batches).
- Demo terminal, shift and cash sale. 170 tests passing.

### 2026-10-19: Phase 2.4b, sales
- Price lists (customer, branch, default) with quantity breaks and validity; catalogue default price fallback; price overrides gated by `sales.price.override`.
- Effective-dated tax rates per country and category (platform configuration), with a country default category. Seeded NG categories (EXEMPT, ZERO, VAT_STANDARD 7.5% from 2020-02-01) are flagged for professional confirmation.
- Quotations (expiry; quoted prices honoured), sales orders (discount and credit-limit approval with segregation of duties, FEFO reservations per line), partial deliveries (`ReservationService::consumeQuantity`) with cost of goods sold, invoicing of delivered quantities, credit notes with returns (quarantined by default) and cost reversal, customer payments and allocation, receivables aging, customer statements.
- Fix: `ReferenceDataSeeder` no longer overwrites admin-edited country activation or settings on redeploy (it only adds missing defaults).
- Demo sales order through invoice. 159 tests passing.

### 2026-10-19: Phase 2.4a, finance foundations
- Chart of accounts per organization (template keyed by `system_key`), financial periods (opened monthly on demand; close/reopen with reason).
- `GeneralLedger`: balanced, immutable journal entries with reversals; closed periods refuse postings.
- `AccountingBridge` posts inside the document transaction: goods receipts, supplier invoices (GRNI clearing, price variance, input tax, other charges), write-offs and counts, opening-balance and other direct receipts (`DirectReceiptService`), supplier returns and supplier payments.
- Supplier payments with allocation to approved invoices (balance and currency checks, partial or paid status).
- Reports: trial balance, account ledger, profit and loss, balance sheet, payables aging.
- `finance` added to default modules for trading organization types; ADR-0008. 151 tests passing.

### 2026-10-19: Phase 2.3, procurement
- Purchase requisitions (submit, approve/reject, convert to per-supplier POs) and purchase orders (draft → pending approval → approved → sent → partially received → received → closed; reject, edit and resubmit; cancel only without receipts).
- Approval threshold and segregation of duties (`procurement.allow_self_approval` for single-person organizations); supplier price lists with validity and minimum quantities; expected date from supplier lead time.
- Goods receipts against POs with batch capture through `StockReceiptService`, over-receipt tolerance, and idempotency.
- Supplier invoices with three-way match (matched / variance / unmatched), re-matched under lock at approval, audited override, and duplicate supplier invoice protection. Supplier returns through the ledger.
- Supplier performance (on-time rate, lead time, fill rate) and purchase suggestions from reorder levels.
- `App\Support\Money` for exact half-up decimal arithmetic; `UnitConverter`; `StockQueries`.
- Demo purchase order with a partial goods receipt. 144 tests passing.

### 2026-10-19: Phase 2.2, inventory core
- Batches (expiry, quality and recall status, batch cost); append-only `inventory_movements` ledger; `inventory_balances` projection maintained only by `InventoryLedger` under ordered row locks with deadlock retry; negative stock blocked unless the organization allows it.
- FEFO allocation that excludes expired, recalled, quarantined and reserved stock, with an optional minimum remaining shelf life. Reservations (reserve, consume, release).
- Receipts with package-level conversion (Idempotency-Key required); adjustments and write-offs; transfers with FEFO dispatch, in-transit tracking and partial receipt; stock counts against live balances; bins.
- Batch quarantine, release and reject across warehouses (releases reservations; expired or recalled batches cannot be released).
- Stock levels, batch balances, ledger, expiring, low-stock and valuation endpoints; costs gated by `inventory.valuation.view`.
- Branch/warehouse access scopes enforced (`PUT /members/{id}/locations`).
- Idempotency middleware is now re-entrant (fixes a double claim when group and route both apply it).
- Two-connection locking test; synthetic demo batches with spread expiries. 133 tests passing.

### 2026-10-19: Phase 2.1, suppliers and customers
- Tenant-owned `suppliers`, `customers` and `party_contacts` with codes unique per organization, credit and payment terms, a customer location for the field force, and an optional link to a platform organization.
- API with search, filters, contacts (single primary) and audit; module-gated (`module:procurement|inventory`, `module:sales|pos|field_force`). Module middleware now accepts alternatives.
- New permissions `suppliers.*` / `customers.*`, mapped to the relevant system roles.
- `organization_products.preferred_supplier_id` now has a foreign key and is validated against the organization's own suppliers.
- Synthetic suppliers and customers in demo data.

### 2026-10-18: Phase 2.0, account security
- TOTP two-factor authentication (pragmarx/google2fa) with QR enrolment, replay protection, 8 single-use hashed recovery codes, web login challenge, self-service disable, and platform reset for lost devices.
- Email verification with signed links; self-service registration endpoint.
- Security policy (`config/security.php` + runtime admin setting). **Two-factor and email verification are OFF by default** and can be switched on in production without a redeploy. Organizations may require two-factor for their members when the platform allows it.
- Account security web page; tests for the password-reset flow.

### 2026-10-18: Phase 1 completion against the full spec (§31)
- Subscription and entitlement foundations: plans, plan entitlements, organization subscriptions (with history), overrides, `EntitlementService::guard()` with row locking; enforcement for users (including pending invitations), branches, warehouses, storage and modules; tenant and admin billing endpoints.
- Domain events after commit (`OrganizationRegistered`, `OrganizationVerificationChanged`, `ProductCreated`, `ProductUpdated`, `RegulatoryIdentifierUpdated`).
- Health readiness endpoint, operations status, failed-job listing and retry (no payloads), admin user directory, country configuration endpoint, JSON log channel.
- CI workflow (MySQL 8, Pint, tests, audits).
- Synthetic demo data per §32: 7 organizations across all chain roles, role users, branches and warehouses, 5 products with `DEMO-` NAFDAC identifiers. Synthetic identifiers can never be marked verified and are rejected in production.
- Docs re-aligned to official phases 0–10; module dependency map, forward entity model, external-credentials inventory, backup/restore and rollback procedures; ADR-0007.

### 2026-10-18: Phase 1 foundation and Product Master
- Laravel 13 modular monolith; Sanctum; Livewire 4; Tailwind 4.
- Multi-tenancy with fail-closed scope, tenant-aware jobs and files.
- Permission registry and all §5.2 roles.
- Onboarding, invitations, verification workflow.
- Product Master with NAFDAC/regulatory identifiers and governance.
- Audit log, idempotency, correlation IDs, security headers, branding, country configuration.
- Generated SQL schema; web login, dashboard, catalogue browser, verification queue.
