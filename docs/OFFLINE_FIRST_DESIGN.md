# Offline-first design (Phase 2 to 3)

Status:
- **Server sync engine: built (3a; ADR-0011).** Covers devices, device-bound tokens, revocation, `sync_inbox`, per-envelope acknowledgements, conflict and rejected states, the review endpoints and the reconciliation report.
- **Offline POS replay: built (3b).** Covers the `pos.sale` envelope, offline cash, pending card payments, stock shortfalls and their reconciliation.
- **The app: built (3c).** Covers the SQLCipher database, device registration, batch sync with backoff, sync status, revocation and wipe, the till mode and the outage tests. Operating limits and the six-hour scenario are in [OFFLINE_OUTAGE_PLAYBOOK.md](OFFLINE_OUTAGE_PLAYBOOK.md).

Phase 1 already provides the foundations this design depends on: ULID keys (clients can generate them), idempotency keys scoped by user and tenant, and correlation ids.

## Principles

1. A locally committed sale or order is **never silently discarded**.
2. The device records *intent and evidence*; the **server decides** stock allocation, payment confirmation and acceptance.
3. Stock balances are never overwritten from the client. There is no last-write-wins: the server replays device transactions through the same `InventoryLedger` used online.
4. The UI states clearly whether data is captured offline, pending sync, accepted, in conflict or rejected.

## Client storage (Flutter, Android first)

- SQLite database. Target: the database key is held in Android Keystore / iOS Keychain (`flutter_secure_storage`), with SQLCipher encryption for the database file.
  - **Built in 3c** (`mobile/field_app`):
    - SQLCipher via `sqflite_sqlcipher`, with a random 256-bit key per database file in Keystore/Keychain (`DbKeyStore`); deleting the key crypto-shreds the data on a wipe request.
    - Tables: `customers`, `catalogue_items`, `visits`, `orders`, `follow_ups`, `pos_catalogue`, `pos_sales` and the ordered `outbox`, each outbox item carrying a stable `client_txn_id`. The schema is versioned (v2) with migrations.
    - One database per user, organization and role. The device-bound token is in Keystore/Keychain; the sign-in token is deleted after registration.
  - **Not done:** background sync while the app is closed.
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

Sync types: `field.visit.check_in|check_out|cancel`, `field.order.capture|resubmit`, `field.follow_up.complete`, `pos.sale`, and (Phase 6) `crm.detailing.record`, `crm.feedback.record` and `crm.interaction.log`. CRM records reference their visit by `visit_client_reference`; capture order guarantees the check-in is processed first.

Retry uses exponential backoff with jitter (2 s doubling to 15 min), runs when connectivity returns, and uses WorkManager on Android for background runs. The app shows pending counts, the last successful sync time and failed items.

## Inventory conflicts

- During server-side replay, the server allocates with the same row locks (FEFO, `ReservationService`) as online sales, in the order the envelopes arrive.
- **When two terminals sold the last units:**
  - The second sale is still recorded and invoiced, because cash was taken.
  - Only the stock that exists is issued; balances never go negative and are never overwritten.
  - The unissued quantity is kept on the line (`unallocated_base_quantity`) and raised as a high-severity `stock_shortfall` sync issue.
  - A supervisor (`sync.reconcile`) resolves it in one of two ways:
    - **acknowledge** it;
    - **record found stock**: a found-stock receipt into a batch (existing, or a new number with an expiry) at an optional unit cost, issued straight to the sale line, which posts the missing cost of goods sold.
- **A scanned batch that was expired, recalled or quarantined while the till was offline** is still issued, because it is what physically left. It raises a high-severity `blocked_batch_sold` issue for recall follow-up. Replay does not refuse the sale.
- **Other replay findings become issues instead of refusals:**
  - prices differing from the list (`price_variance`);
  - discounts above the limit;
  - prescription controls not met;
  - payments below the total;
  - sales that sync after their shift was closed (`late_offline_sale`).
- **Refused outright:**
  - a shift that has already been reviewed (`conflict shift_reviewed`, kept in the inbox for a supervisor);
  - a shift on another terminal;
  - a non-POS device.

## Offline payments

Only **cash** can be recorded offline. It goes into the drawer and counts toward completing the sale, and it is shown as `offline_cash_recorded` (`pos_payments.recorded_offline`) until its shift has been counted and reviewed. Card, transfer and mobile-money payments taken on a standalone terminal arrive `pending` and are confirmed only by a supervisor's settlement check. The sale stays `awaiting_payment` with its stock reserved until then. Integrated-provider payments cannot be taken offline (built in 3b; `PosPayment::presentationState`).

## Limits (stated to users)

There is a device storage cap for the catalogue snapshot, a maximum offline duration before re-authentication is required (configurable, default 72 h), and some operations are online-only: new customer credit, refunds of electronic payments, transfers between organizations.
