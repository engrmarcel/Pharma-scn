# Offline-first design (Phase 2 to 3)

Status:
- **Server sync engine: built (3a; ADR-0011).** Covers devices, device-bound tokens, revocation, `sync_inbox`, per-envelope acknowledgements, conflict and rejected states, the review endpoints and the reconciliation report.
- **Offline POS replay (3b) and the app (3c): in progress.**

Phase 1 already provides the foundations this design depends on: ULID keys (clients can generate them), idempotency keys scoped by user and tenant, and correlation ids.

## Principles

1. A locally committed sale or order is **never silently discarded**.
2. The device records *intent and evidence*; the **server decides** stock allocation, payment confirmation and acceptance.
3. Stock balances are never overwritten from the client. There is no last-write-wins: the server replays device transactions through the same `InventoryLedger` used online.
4. The UI states clearly whether data is captured offline, pending sync, accepted, in conflict or rejected.

## Client storage (Flutter, Android first)

- SQLite database. Target: the database key is held in Android Keystore / iOS Keychain (`flutter_secure_storage`), with SQLCipher encryption for the database file.
  - **Built in Phase 2.6b** (`mobile/field_app`): plain `sqflite` with the tables `customers`, `catalogue_items`, `visits`, `orders`, `follow_ups` and an ordered `outbox`. Records are keyed by device `client_reference` (UUID v7); the API token is stored in Keystore/Keychain; there is one database file per user and organization.
  - **Not yet encrypted.** SQLCipher, device registration and background sync are Phase 3 work.
- Cached data per device role:
  - **POS:** the branch's sellable catalogue and prices, batch availability snapshots with an `as_of` time, recent customers, and open shift state.
  - **Field force:** assigned customers only, the authorized catalogue, applicable price lists, visit forms, and pending drafts.
- No passwords or payment secrets are stored. The device holds a refreshable, revocable, short-lived token bound to its device registration.

## Sync contract

Each outbound record is an envelope:

```json
{
  "client_txn_id": "01J…ULID",          // primary key on the server too
  "idempotency_key": "device:<id>:<client_txn_id>",
  "device_id": "…", "organization_id": "…", "user_id": 42,
  "type": "pos.sale", "schema_version": 1,
  "client_created_at": "2026-…Z", "attempt": 3,
  "payload": { … },
  "evidence": { "batch_allocations": [ … ], "stock_snapshot_as_of": "…" }
}
```

States: `LOCAL_DRAFT → PENDING_SYNC → SYNCING → SYNCED | RETRY_REQUIRED | CONFLICT | REJECTED → REVIEW/CORRECTION`.

The server endpoint `POST /api/v1/sync/batches` accepts up to 50 envelopes `{client_txn_id, type, schema_version, client_created_at, attempt, payload}`, stores each durably in `sync_inbox`, and acknowledges each one with `{client_txn_id, status: synced|conflict|rejected|retry_required, result?, warnings?, error?, duplicate?}`. Processing is idempotent on `client_txn_id`. The device and user come from the device-bound token, and the tenant comes from the device's organization (built in 3a).

Retry uses exponential backoff with jitter (2 s doubling to 15 min), runs when connectivity returns, and uses WorkManager on Android for background runs. The app shows pending counts, the last successful sync time and failed items.

## Inventory conflicts

- During server-side replay, the server allocates with row locks in transaction order. If two terminals sold the last units, the second sale is still recorded (cash was taken), but its shortfall becomes an **inventory discrepancy**: a negative-availability exception routed to supervisor reconciliation. It is never silently dropped.
- Offline batch selections are validated: an expired, recalled or quarantined batch produces a `CONFLICT` for review.

## Offline payments

Only **cash** can be recorded offline, as `offline_cash_recorded`. It is not reconciled until the shift is closed and counted. Card, transfer and mobile-money payments stay `pending` until the provider confirms them, unless a provider explicitly supports offline confirmation.

## Limits (stated to users)

There is a device storage cap for the catalogue snapshot, a maximum offline duration before re-authentication is required (configurable, default 72 h), and some operations are online-only: new customer credit, refunds of electronic payments, transfers between organizations.
