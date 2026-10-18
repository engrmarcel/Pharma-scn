# Enterprise integration gateway (Phase 5) — status: designed, not implemented

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

## Planned tables

`connector_configurations` (credentials encrypted, per tenant), `field_mappings`, `external_references`, `integration_inbox`, `integration_outbox`, `integration_runs`, `failed_messages`, `webhook_subscriptions`, `delivery_attempts`.

## Operations

Health status per connector, received/processed/rejected counts, manual retry, a failed-message review screen with redacted payload previews, rate limits, and audit of configuration changes. Tokens, keys and full payloads are never logged.
