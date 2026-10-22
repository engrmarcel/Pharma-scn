# Traceability, QR and recall (Phase 7)

**Status:** 7a built: event ledger, hash chain, corrections, verification levels, QR, serials and SSCC, scanning, GLNs, EPCIS export (ADR-0015). 7b built: recalls, notices, recovery and exposure with coverage statement, regulatory incident log (ADR-0016). 7c (logistics) is in progress. Regulatory review: `REGULATORY_TRACEABILITY_REVIEW.md`. Built differences from the design below:
- **Events are recorded automatically from every stock posting**, not entered by hand.
- **Verification is a separate, append-only record.**
- **On packs in Nigeria, the GS1 DataMatrix is scanned rather than a platform QR code** (reg. 7(1)).

Reference: NAFDAC traceability programme, https://nafdac.gov.ng/traceability/. It is a starting point only: current official NAFDAC guidance and the GS1 standards it adopts must be reviewed before any compliance claim is made.

## Event model

`traceability_events` is append-only. Corrections are new events that reference the event they correct (`corrects_event_id`) or reverse (`reverses_event_id`).

| Field | Notes |
|---|---|
| id (ULID), event_type | production, quality_release, dispatch, import_receipt, stock_receipt, transfer, custody_transfer, pharmacy_receipt, quarantine, recall, dispensing_or_sale, correction |
| product_id, batch_id, serialized_item_id? | From Product Master and batches (Phase 1B) |
| quantity, unit_code | Base units plus the package level used |
| source/destination organization and location | |
| related_type/id | Order, shipment, POS sale, … |
| event_time, recorded_at | Business time versus system time |
| actor_user_id / source_system | Who or what recorded the event |
| country_code, verification_status | A recorded event is **not** proof that it happened; verification is separate |
| evidence attachment ids, hash_prev? | Hash chaining (tamper evidence) can be enabled per tenant later; blockchain is not required |

The model represents the events that actually happened rather than an assumed fixed route.

## QR and serialization

- A QR code carries a **signed opaque reference**, or a GS1 Digital Link where the regulatory scheme mandates one. It never carries supplier, pricing or patient data.
- A scan resolves through an authorized endpoint and logs `scan_events`. It reports the batch status (recalled, expired, unknown). Scanning does not in itself prove a product is authentic, and the UI says so.
- Duplicate serials, revoked identifiers and packaging aggregation (unit, case, pallet) follow the Phase 1 package hierarchy.

## POS linkage

Every sale line records the batch it was allocated (FEFO), and expired or recalled batches are blocked at sale. Offline sales keep the batch evidence locally and are validated at sync.

## Recall workflow

Recall (reason, reference, severity, scope by product, batch or serial) → affected-stock search across participating tenants → notifications → quarantine instructions → acknowledgement tracking → returned and recovered quantities → unaccounted quantity report → closure, with an audit trail throughout.

Coverage reports state explicitly which organizations or transactions have **no data**. The system never claims complete coverage.
