# ADR-0006: ULID primary keys and idempotency keys

- Status: Accepted (2026-10-17)

## Context
Offline POS and field devices must create records before the server sees them (spec §13.3). Retries must not create duplicate orders, invoices or payments (spec §10.5).

## Decision
- Domain tables use ULID primary keys. Clients may generate them, and the sync engine (Phase 3) will use the client's ULID as the server record id.
- `users` keeps a bigint key (Sanctum's token morph uses bigint).
- Unsafe API requests honour `Idempotency-Key`. Keys are scoped by user and tenant, only successful responses are stored for replay, and a body mismatch is rejected. Offline sync envelopes will derive their key from `device_id + client_txn_id`.

## Consequences
ULIDs are 26-character keys, which makes indexes slightly larger than bigint ones. This is accepted for the offline and integration benefits.
