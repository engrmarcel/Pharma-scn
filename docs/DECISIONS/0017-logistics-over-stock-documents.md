# ADR-0017: Shipments record how goods travel; the stock documents move the books

- Status: Accepted (2026-10-22)

## Context
Spec §18 asks for shipments, carrier/driver/vehicle, dispatch, tracking events, proof of delivery, partial and failed delivery, return to origin, reconciliation, temperature records, excursion alerts and delivery confirmation. Stock and money already move through documents: sales deliveries, stock transfers, marketplace receipts and credit notes (Phases 2–4). If a shipment also posted stock, the same goods could be moved twice.

## Decision
- **Execution layer, no postings.**
  - A shipment is created from a source document (`sales_delivery`, `stock_transfer`) or manually, and copies its items by batch (product, batch number, expiry, base quantity).
  - Delivery, failure and return record quantities on the shipment only. Corrections go through the source document's own flow: a credit note for goods that came back on a sales delivery, the transfer receipt for what arrived. The shipment shows this as `next_steps`.
  - One open shipment per source document; a cancelled one can be replaced.
- **Shipper and carrier share the shipment.**
  - The shipper plans, assigns, cancels and assesses excursions (`logistics.manage`).
  - A carrier on the platform (an active logistics or cold-chain logistics provider) and its drivers see the shipment and record dispatch, tracking, temperatures, proof of delivery, failure and return (`logistics.deliver`). Other organizations get 404.
  - Off-platform carriers and drivers are recorded by name.
- **Cold chain only where a product needs it.**
  - A shipment needs cold chain when any item's product does; the range is the strictest across the items. Ranges that do not overlap are refused.
  - Dispatch of a cold-chain shipment needs a refrigerated vehicle or a temperature logger.
  - Readings are idempotent per shipment, time and device. Consecutive out-of-range readings form one excursion. A new excursion sends an e-mail to the shipper, adds a shipment event and raises the `logistics.temperature_excursion` webhook event.
  - Every excursion is assessed by the shipper (product usable, quarantine or destroy). Proof of delivery records whether one was still unassessed.
- **History.** Shipment events are append-only. Assignment changes and delivery outcomes are audited.
- **Reconciliation.** Per period for the shipper: shipped, delivered and returned quantities, unaccounted quantities per shipment, on-time rate against the planned time, failures and cold-chain excursions.

## Consequences
- **Assessment does not move stock.** An excursion assessed as quarantine or destroy is acted on through the existing quality and adjustment flows, by a person.
- **Marketplace shipments.** Marketplace orders already have their own shipments and receipts (Phase 4). They are not yet mirrored as logistics shipments; `marketplace_shipment` is reserved as a source type.
- **Devices.** Readings arrive through the API (logger upload, manual entry, IoT gateway). No device vendor integration exists yet.
- **Mobile.** The driver flow is available through the API; screens in the field app are not built yet.
