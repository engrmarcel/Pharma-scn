# Enterprise integration gateway (Phase 5)

**Status:** 5a built (registry, canonical model, mappings, durable inbox, product, batch and stock ingestion, quarantine and retry, health, inventory reconciliation; ADR-0013). 5b built (file import, SFTP exchange, SAP IDoc adapter, mapping templates, CSV exports). 5c built (transactional outbox, signed webhooks, reference reconciliation, demo connector). Phase 5 complete.

Positioning: *keep your existing ERP and connect your pharmaceutical supply chain to the network.*

## Pipeline

```
External ERP → Connector/Adapter → AuthN + schema validation → integration_inbox (durable)
  → transform to canonical model → validate + dedupe → domain services (Product Master, Inventory, Orders, Traceability)
Domain events → integration_outbox → delivery worker → webhook/SFTP/API target (retries, dead-letter)
```

- A source is **acknowledged only after the inbox write commits**. Processing then happens asynchronously, and a status endpoint and callbacks report the business outcome.
- **Delivery guarantee:** at-least-once delivery with idempotent consumers and reconciliation reports. Universal exactly-once delivery is not promised.
- There is no direct database access to customer ERPs.

## Canonical model (minimum)

Product, ProductIdentifier, RegulatoryIdentifier, Organization, Facility, Warehouse, Batch, SerializedItem, InventorySnapshot, InventoryMovement, PurchaseOrder, SalesOrder, Shipment, ShipmentItem, Receipt, TraceabilityEvent, RecallEvent.

The Phase 1 Product Master and regulatory identifier tables are already the canonical product representation. External codes map through an `external_references` table (`connector_id, entity_type, external_id → internal id`). An external product code is never assumed to equal our product id.

## Connector types

REST/JSON, SOAP, webhooks, SFTP + CSV/XML, scheduled import/export, EDI (X12/EDIFACT via adapter), and SAP through whatever the customer's landscape supports (OData, IDoc via middleware, or BAPI/RFC through an approved secured adapter), plus Oracle and Dynamics adapters. The interface is chosen per customer after confirming product, version, deployment model and authorization.

## Tables

| Table | Holds | Status |
|---|---|---|
| `integration_connectors` | The registry: credentials encrypted, inbound key hashed | built (5a) |
| `integration_field_mappings` | Source-to-canonical field mappings | built (5a) |
| `integration_external_references` | External ids mapped to our records | built (5a) |
| `integration_runs` | One row per intake call or file | built (5a) |
| `integration_inbox` | Every inbound record. Quarantined and failed rows are the failed-message store. | built (5a) |
| `integration_inventory_snapshots` | Stock positions reported by external systems | built (5a) |
| `integration_outbox`, `webhook_subscriptions`, `webhook_deliveries` | Outbound events and their delivery | built (5c) |

## Connecting a system (5a)

1. **Confirm the interface.** Record it in `interface_notes` when registering the connector: product, version, deployment model, the interface the customer's landscape actually offers (REST middleware, IDoc, files) and who authorizes access. Never assume two SAP installations offer the same interface.
2. **Register the connector.** `POST /integration/connectors` (needs `integrations.manage`; counts against the plan's `integration_connectors` limit).
3. **Secure it.**
   - Issue the inbound key with `POST /integration/connectors/{id}/key`. It is shown once; store it in the source system's secret store.
   - Optionally require signed requests with `POST …/signing {enabled: true}`.
   - Credentials the platform needs for outbound connections go to `PUT …/credentials`. They are write-only.
4. **Map the fields.** Set `PUT …/mappings/{entity}` for each entity the system sends, then check sample records with `POST …/mappings/{entity}/preview`. The preview writes nothing. Without mappings, records must already be canonical. `GET /integration/canonical-model` lists every field, its type and whether it is required.
5. **Activate and send.**
   - Activate the connector with `POST …/activate`.
   - The source system sends records with `POST /integration/inbound/messages` (`Authorization: Bearer ck_…`), up to 500 per call. Each record needs a `message_id` that stays the same when the record is resent.
   - Read the outcome of each record with `GET /integration/inbound/messages/{message_id}`.
6. **Review and reconcile.**
   - `GET /integration/health` shows connector status; `GET /integration/messages?status=quarantined` lists records that need attention.
   - For each quarantined record: fix the source data or the mapping and `retry`, `map` it to a product or warehouse, or `ignore` it with a reason.
   - `GET …/reconciliation/inventory` compares the source system's stock with platform stock.

### Files and SFTP (5b)
- **Upload or push.** Upload with `POST /integration/connectors/{id}/files` (multipart). Systems push with `POST /integration/inbound/files?entity_type=product&format=csv` (raw body).
- **Formats.**
  - CSV: header row; delimiter from `settings.csv_delimiter`, or detected from `,` `;` tab `|`; BOM allowed.
  - JSON: a list, or `{"records": [...]}`.
  - XML: `settings.xml_record_element` names the element holding one record.
- **Message ids.** Each row gets the value of `settings.message_id_field` if set, else `<file hash>:<row>`. Identical file content is imported only once.
- **SFTP settings** (`settings.sftp`):
  - connection: `host`, `port`, `root`, and `host_fingerprint` (required);
  - folders: `inbound_path`, `archive_path`, `error_path`;
  - file rules: `files: [{pattern, entity_type, format?}]`, applied in order, so list product files before batch and stock files;
  - exports: `exports: [{dataset: inventory|catalogue, path, interval_minutes}]`.
  - Credentials go in `sftp_username` plus `sftp_password` or `sftp_private_key`/`sftp_passphrase`.
- **Polling.** The scheduler runs `integration:sftp-poll` every five minutes, or trigger it with `POST …/sftp/poll`.

### SAP connectivity
Confirm the customer's SAP product, release, deployment and middleware first. The options, in order of preference:
1. **IDoc XML over HTTP**, built: SAP sends MATMAS and BATMAS IDocs to `POST /integration/inbound/idoc` through its middleware (SAP Integration Suite/Cloud Integration, PI/PO, or an XML-HTTP port). DOCNUM is the idempotent message id, so SAP's own resends are recognized. Apply the `sap_idoc_matmas` and `sap_idoc_batmas` templates, then check the segments against the customer's IDoc definitions (WE60) with the mapping preview.
2. **OData or REST through middleware**: the middleware calls S/4HANA APIs and pushes the results to `POST /integration/inbound/messages` as JSON. Use the `sap_table_*` templates as a starting point.
3. **Scheduled extracts over SFTP**: material, batch and stock extracts as CSV files.
4. **BAPI/RFC** only through the customer's approved, secured integration middleware. The platform never opens RFC or database connections to SAP.

### Exports
`GET /integration/connectors/{id}/exports/inventory|catalogue` returns a CSV, and SFTP exports push the same file. Product rows carry the target system's own key (from external references) next to our SKU and GTIN. Every export is an outbound run in the audit trail.

### Outbound events (5c)
- **Subscribe.** `POST /integration/webhooks {name, url, event_types[], connector_id?}` returns the signing secret once. `GET /integration/webhooks/event-types` lists the catalogue.
- **Transactional outbox.** An event is written in the same database transaction as the change it describes. It is never lost after a commit and never sent for a change that rolled back. Organizations without subscribers write nothing.
- **Receiving.**
  - Verify `X-Webhook-Signature` over `X-Webhook-Timestamp + "." + body`.
  - Deduplicate on `X-Webhook-Id`: delivery is at least once.
  - Answer 2xx quickly and process asynchronously.
  - Payloads carry SKU, GTIN and, when the subscription is linked to a connector, that system's own product key (`product_external_id`).
- **Failures.** Retries back off over about 18 hours, then the delivery is dead and can be redelivered from `…/deliveries`. A receiver that keeps failing is paused; its events wait and go out when it is resumed.

### Reconciliation reports
- `…/reconciliation/inventory`: source-system stock against platform stock per warehouse, product and batch.
- `…/reconciliation/references`: catalogue items the source has never sent, references to products no longer in the catalogue, warehouse mappings, and open quarantine by error code.

### Order of records
Send product records before the batch and stock records that refer to them. A batch or stock record for an unknown product key is quarantined as `product_reference_unknown`; retry it once the product record has been processed or mapped. A GTIN on the record resolves the product directly.

### Sample
`tests/fixtures/integration/sample_erp_dataset.json` is a **synthetic**, SAP-style export: material, batch and stock fields with invented GTINs and registration numbers. `tests/Concerns/InteractsWithIntegrations::sapMappings()` shows the mapping for it, including SAP conventions: leading-zero material numbers, `YYYYMMDD` dates where `00000000` means no date, comma decimals and status codes.

## Operations

Health status per connector, received/processed/rejected counts, manual retry, a failed-message review screen with redacted payload previews, rate limits, and audit of configuration changes. Tokens, keys and full payloads are never logged.
