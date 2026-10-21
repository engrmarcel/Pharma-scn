# Enterprise integration gateway (Phase 5)

**Status:** 5a built (registry, canonical model, mappings, durable inbox, product, batch and stock ingestion, quarantine and retry, health, inventory reconciliation; ADR-0013). 5b planned: file/SFTP intake, outbox and webhooks, SAP IDoc adapter, further reconciliation.

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
| `integration_outbox`, `webhook_subscriptions`, `delivery_attempts` | Outbound events and their delivery | planned (5b) |

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

### Order of records
Send product records before the batch and stock records that refer to them. A batch or stock record for an unknown product key is quarantined as `product_reference_unknown`; retry it once the product record has been processed or mapped. A GTIN on the record resolves the product directly.

### Sample
`tests/fixtures/integration/sample_erp_dataset.json` is a **synthetic**, SAP-style export: material, batch and stock fields with invented GTINs and registration numbers. `tests/Concerns/InteractsWithIntegrations::sapMappings()` shows the mapping for it, including SAP conventions: leading-zero material numbers, `YYYYMMDD` dates where `00000000` means no date, comma decimals and status codes.

## Operations

Health status per connector, received/processed/rejected counts, manual retry, a failed-message review screen with redacted payload previews, rate limits, and audit of configuration changes. Tokens, keys and full payloads are never logged.
