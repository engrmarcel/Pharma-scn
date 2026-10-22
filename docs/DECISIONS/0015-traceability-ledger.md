# ADR-0015: Traceability ledger recorded from stock movements, hash-chained and shared by involvement

- Status: Accepted (2026-10-26)

## Context
Phase 7 acceptance requires:
- a test batch can be traced through recorded supply-chain events;
- corrective events preserve history;
- the system distinguishes recorded evidence from independently verified authenticity;
- applicable regulations are reviewed (see `REGULATORY_TRACEABILITY_REVIEW.md`).

Spec §16 asks for traceability as a shared platform service. Stock already moves only through `InventoryLedger::post()`, and each organization keeps its own batch records keyed by product and manufacturer lot.

## Decision
- **Events are derived, not keyed in.**
  - `InventoryLedger::post()` calls `TraceRecorder::recordPosting()` inside the posting transaction. Every stock movement (receipt, dispatch, transfer, dispensing, quarantine, release, adjustment, count) therefore has its event, and no event exists without its movement.
  - Batch creation is recorded through the transactional event-recorder chain (with the integration outbox).
  - Counterparties come from the document: supplier or customer, and its platform organization when linked.
- **A lot is identified by product + manufacturer batch number across organizations.** Each organization's batch record stays private. The lot key lets the chain cross from a distributor's dispatch to a pharmacy's receipt.
- **Append-only and tamper-evident:**
  - events are never updated or deleted (enforced in the model);
  - a correction or reversal is a new event that references the original;
  - each organization's events form a SHA-256 hash chain (previous hash and own content), with the chain head locked while appending;
  - `GET /trace/integrity` recomputes it and reports the first broken link.

  No blockchain is used (spec §16.6).
- **Verification is separate from recording.** The levels are:

  | Level | Meaning |
  |---|---|
  | `recorded` | the default |
  | `corroborated` | the counterparty recorded the matching movement; added automatically when a receipt from a platform organization matches its dispatch |
  | `independently_verified` or `disputed` | platform staff with `traceability.verify` and stated evidence |

  These are separate append-only records, so the event itself never changes.
- **Visibility by involvement.**
  - An organization sees the events it recorded and those where it is the source or destination. It never sees where a lot went after leaving its customer, nor a counterparty's internal movements.
  - Platform staff with `traceability.view_all` see everything.
  - Counterparty names and document references of another organization's events are withheld.
- **Codes are not proof:**
  - QR codes encode only a URL with a random opaque token;
  - scans (public, member, dispense) report the recorded status (recalled, expired, revoked, dispensed, unregistered) with a fixed disclaimer;
  - public answers name no organization, price or patient;
  - scans are logged with a keyed hash of the client address, not the address.
- **Standards:** serials and logistic units follow GS1 (GTIN + serial unique platform-wide, SSCC aggregation). GLNs are recorded for organizations and locations. Events export as EPCIS 2.0 JSON-LD with CBV steps and dispositions; corrections become `errorDeclaration`s.

## Consequences
- **Coverage:** traceability covers stock that moves through the platform. Movements in an organization's external ERP appear only if they are posted here, for example through the integration gateway. Coverage statements must say so (spec §16.5).
- **Volume:** every posting writes one event per batch and warehouse leg, and the chain head serializes postings per organization. That is acceptable at current volumes; partitioning by organization keeps contention local.
- **Serial-level tracking** happens at commissioning, aggregation and dispensing. Batch-level stock movements do not list serials.
