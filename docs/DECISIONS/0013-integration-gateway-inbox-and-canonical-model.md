# ADR-0013: Integration gateway: a durable inbox, a canonical model and external references

- Status: Accepted (2026-10-24)

## Context
Spec §15 and Phase 5 ask large organizations to connect their existing ERP (SAP, Oracle, Dynamics and others) instead of replacing it. Phase 5 acceptance requires that:
- a sample ERP dataset maps into a canonical model;
- invalid data is rejected or quarantined with an actionable error;
- retries never duplicate business transactions;
- health and failures are visible;
- credentials stay protected.

External systems retry unpredictably, send data we cannot match yet, and cannot be told "processed" before we have processed it (§15.5).

## Decision
- **Connector keys authenticate external systems, not user tokens.**
  - Each connector gets an inbound key (`ck_<prefix>_<secret>`), shown once and stored only as a SHA-256 hash. It can reach only `/integration/inbound/*`, which is rate-limited per connector.
  - Request signing (HMAC-SHA256 over `timestamp.body`, with a 5-minute window) can be required per connector.
  - Credentials for outbound connections and the signing secret are encrypted at rest and write-only through the API.
- **Inbox first, then acknowledge.**
  - Every record is written to `integration_inbox` before the response is sent.
  - The per-record acknowledgement is `accepted` (stored, queued), `duplicate` (the stored status) or `rejected` (refused at intake and not stored: malformed envelope, unknown or not-yet-ingestible entity).
  - The business outcome is read from `GET /integration/inbound/messages/{message_id}`; intake never reports a record as processed.
- **Idempotency by message_id; idempotent handlers underneath.**
  - `message_id` is unique per connector. The same record resent under the same id returns the stored status. A different record under a used id is refused (`message_id_reused`), so changed data is never silently merged into an old message.
  - Handlers are upserts (catalogue item, batch record, latest stock position). The same record under a new `message_id` therefore produces `unchanged`, not a second business record.
- **Asynchronous processing that separates data problems from system problems.**
  - Each message is processed in a queued job and in its own transaction. The processor first claims the message, so duplicate jobs do nothing.
  - **Data problems** are quarantined with a stable code, an actionable message and redacted details: validation against the canonical model, an unmatched or conflicting reference, or a business rule. They wait for a person to retry, map or ignore them.
  - **Unexpected errors** are retried with backoff (1, 5, 15 then 60 minutes), then dead-lettered as `failed` after 5 attempts. Their exception text goes to the log only.
  - A scheduled sweep (`integration:process-due`) re-queues due retries and messages stuck in processing.
- **Canonical model in code.**
  - `CanonicalModel` defines all 17 entities of §15.2: fields, types, requirements and descriptions. It is served at `/integration/canonical-model` and used for validation.
  - Any entity can be mapped and previewed now. Only `product`, `batch` and `inventory_snapshot` are applied in 5a; the others are refused at intake until their handlers exist, never stored and ignored.
- **Field mappings per connector and entity.** Each mapping takes a source path, an optional transform (trim, upper, lower, strip_leading_zeros, date with format, decimal with separators, value map) and an optional default. A connector without mappings sends canonical records.
- **External ids are never our ids.**
  - `integration_external_references` maps a connector's keys (material numbers, plant/storage locations) to our records.
  - A product record must agree across all its signals: the recorded reference, its GTIN (GTIN-13 and GTIN-14 forms are treated as one) and its registration number. Disagreement is quarantined as `product_match_conflict`, as a possible counterfeit or data error.
  - A person can map an unmatched record; manual references are never overwritten by automatic matches.
- **The shared Product Master is never written by an integration.** Products resolve to existing canonical products. An integration upserts only the organization's own catalogue item (SKU, local name, prices). Unmatched products go to a person, who maps them or proposes them through catalogue governance.
- **Stock positions are for reconciliation, not posting.**
  - Inventory snapshots record the latest quantity the source system reports per warehouse, product and batch, and feed the reconciliation report (match, mismatch, not on platform, not reported by source).
  - Platform stock moves only through its documented receipts, issues, adjustments and counts.
- **Quality changes go one way.** A quarantine or rejection reported by the source system is applied, because it only makes stock less available. A release is reported back as a warning and left for a person.

## Consequences
- The guarantee is at-least-once delivery with idempotent outcomes, plus reconciliation. There is no claim of exactly-once delivery across systems (§15.5).
- Organizations keep their ERP as the system of record for what it owns. Nothing in the platform requires replacing it, and there is no direct database access to it.
- Adding an entity means a handler and its tests. The canonical definition, mapping, validation, inbox, retry and monitoring already apply to it.
- Phase 5b adds file and SFTP intake, the outbound outbox with webhooks, the SAP IDoc adapter and further reconciliation, all on this same inbox and canonical model.
