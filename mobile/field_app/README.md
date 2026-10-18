# Pharma Network Field (Flutter)

This is the Android-first field-force app for sales representatives (spec §12, Phase 2.6b). It uses the `/api/v1/field-force` endpoints described in [`docs/API_SPECIFICATION.md`](../../docs/API_SPECIFICATION.md) and ADR-0010.

## What it does

- **Sign in.** The app signs in with an API token, which is kept in Android Keystore / iOS Keychain via `flutter_secure_storage`. The user then picks an organization; the server confirms the field-force module and the user's permissions.
- **Customers.** Only the customers assigned to the representative are shown. Customers that are no longer assigned are removed from the device at the next sync.
- **Visits.**
  - The representative checks in, logs notes, feedback, competitor information (only when permitted), activities, the order opportunity, the next action and a follow-up date, and then checks out.
  - Location is read **once**, at check-in and at check-out. There is no background tracking.
  - When permission is denied, location services are off, or no fix arrives in time, the app records that status and sends **no coordinates**.
  - Whether a visit is "verified" is decided by the server and shown only after sync.
- **Orders.**
  - The catalogue shows the indicative price for the customer per pack and a stock snapshot stamped with the time it was read.
  - The order screen always shows which of these states an order is in:
    - Draft on this device
    - Waiting to sync
    - Submitted
    - **Accepted by ERP** (sales order number; whether stock is reserved or awaiting approval)
    - Rejected (with the ERP's reason; the order can be edited and resubmitted)
- **Follow-ups.** The app lists open follow-up tasks and lets the representative complete them offline.

## Offline design (ready for Phase 3)

Every action is written to a local SQLite database together with an **outbox** item, in one transaction:

| Table | Purpose |
|---|---|
| `customers`, `catalogue_items` | Cached directory and catalogue (with `stock_as_of`) |
| `visits`, `orders`, `follow_ups` | Device records keyed by `client_reference` (UUID v7); server ids and ERP status are only ever set from server responses |
| `outbox` | Durable ordered queue (`pending`/`failed`, attempts, last error) |

Sync works through the outbox in order:

- A dropped connection or a server error stops the run and keeps the item for later.
- A refusal (a business rule returning 4xx) marks the item, and the later steps for the same record, as **needs attention**, with the server's message.
- Because the server de-duplicates by `client_reference`, retrying after a lost response never creates duplicates.
- There is one database file per user and organization. Signing out keeps unsent work for the next sign-in to the same organization.

Not done yet (Phase 3): an encrypted local database (SQLCipher), background sync scheduling, device registration, conflict resolution for server-side edits, and attachments.

## Run

```bash
flutter pub get
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1   # Android emulator -> host machine
```

Debug builds allow plain HTTP for local development. Release builds require HTTPS.

The Laravel demo data (`php artisan migrate:fresh --seed`) includes the field-force accounts `dist.rep1@demo.test`, `dist.rep2@demo.test` and `dist.supervisor@demo.test`. All demo accounts use the password `Password123!`, and all of their data is synthetic.

## Test

```bash
flutter analyze
flutter test                                       # unit + widget tests (SQLite via sqflite_common_ffi)
FIELD_E2E_BASE_URL=http://127.0.0.1:8000/api/v1 flutter test test/e2e   # against a live, demo-seeded API
```

## iOS

The project includes the iOS runner and the location usage text (`NSLocationWhenInUseUsageDescription`). Building it needs macOS with Xcode, which this project has not done yet.
