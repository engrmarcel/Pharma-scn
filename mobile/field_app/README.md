# Pharma Network Field and Till (Flutter)

This is the Android-first offline app for field representatives and POS tills (spec §12 and §13; Phases 2.6b and 3c). It talks to `/api/v1` (see [`docs/API_SPECIFICATION.md`](../../docs/API_SPECIFICATION.md)) through the sync contract in ADR-0011. Operating limits and the six-hour outage scenario are in [`docs/OFFLINE_OUTAGE_PLAYBOOK.md`](../../docs/OFFLINE_OUTAGE_PLAYBOOK.md).

## Sign-in and device registration

1. The user signs in with email and password. The app then picks the organization, and the role if the user has both field-force and POS access.
2. For POS, the app also picks the terminal.
3. The device registers (`POST /devices`) and receives a **device-bound token**, which replaces the sign-in token. The token is kept in Keystore/Keychain and expires after 72 hours without contact.
4. An administrator can revoke the device. At its next contact the app locks out; if a wipe was requested, it erases its local data by destroying the database key.

## Field mode

- The app shows assigned customers only. Customers that are no longer assigned are removed at the next sync.
- The catalogue shows indicative customer prices and a stock snapshot with its age.
- Visits:
  - Location is read once at check-in and once at check-out. When permission or a fix is missing, the status is recorded and no coordinates are sent.
  - The representative records notes, activities, competitor information (when permitted) and a follow-up date.
  - Whether the visit is verified is decided by the server and shown after sync.
- Orders show their status, from **draft on this device**, through **waiting to sync** and **submitted**, to **accepted by ERP** (sales order number, and whether stock is reserved or awaiting approval) or **rejected** with the reason; a rejected order can be edited and resubmitted.
- Follow-ups and the activity report.

## Till mode

- Opening and closing a shift needs a connection. Closing is refused while any sale is unsent or refused.
- The **offline catalogue** (`GET pos/terminals/{id}/offline-catalogue`) is cached for the terminal. Search works by name, SKU, barcode or batch number; a scanner code followed by Enter adds the exact match.
- Cart totals use `D4`, fixed four-decimal arithmetic with half-up rounding, the same as the ERP, so the device total matches the server's.
- Prescription-only and controlled products need a pharmacist (`pos.dispense.prescription`) and the prescription details.
- Payments:
  - **cash** with change: shown as *offline cash recorded* until the shift is counted and reviewed;
  - **card or transfer on a standalone terminal**: recorded with the approval code and *pending* until a supervisor confirms the settlement. The app never marks an electronic payment confirmed.
- Each sale lowers the till's cached stock so it does not oversell its own snapshot. The server reconciles shortfalls across tills.
- Receipts are on screen. They are marked **offline receipt · not yet synced** until the server returns the sale number.

## Offline storage and sync

- **SQLCipher** database (`sqflite_sqlcipher`), with a random 256-bit key per database file in Keystore/Keychain (`DbKeyStore`). There is one database per user, organization and role. The schema is versioned (v2), with migrations.
- Every action is written to its table **and** to the ordered `outbox` in one transaction. It therefore survives app restarts and forced shutdowns.
- Sync sends the outbox in order as envelopes (`POST /sync/batches`, up to 50 at a time), each with a stable `client_txn_id`. Each acknowledgement is handled on its own:

  | Acknowledgement | What the app does |
  |---|---|
  | `synced` | Records the server's result (numbers, verification, ERP status, payment states, warnings) and removes the item |
  | `conflict` / `rejected` | Keeps the item with the server's reason, marks the record as needing attention, and fails the later steps of the same record |
  | `retry_required` / offline / server error | Keeps the item and backs off: 2 s doubling to 15 min, with jitter |

  A lost response is harmless: resending the same `client_txn_id` returns the stored acknowledgement, and the server never applies it twice.
- Sync runs automatically every 15 seconds while work is queued, and when the app returns to the foreground. The **Sync status** screen shows the last sync, items waiting and retrying, refused items, and the next automatic try.

**Not done yet:**
- background sync while the app is closed (WorkManager / BGTasks);
- an iOS build (needs macOS with Xcode);
- Bluetooth receipt printing;
- a physical-device outage run.

## Run

```bash
flutter pub get
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1   # Android emulator -> host machine
```

Debug builds allow plain HTTP to a development server; release builds require HTTPS.

The Laravel demo data (`php artisan migrate:fresh --seed`) has these accounts, all with the password `Password123!` and all synthetic:

| Role | Accounts |
|---|---|
| Field | `dist.rep1@demo.test`, `dist.rep2@demo.test`, `dist.supervisor@demo.test` |
| Till | `ph1.cashier@demo.test` (cashier), `ph1.pharmacist@demo.test` (can dispense) |

## Test

```bash
flutter analyze
flutter test                                       # unit, widget, till and six-hour outage tests (SQLite via FFI)
FIELD_E2E_BASE_URL=http://127.0.0.1:8000/api/v1 flutter test test/e2e   # against a live, demo-seeded API
```
