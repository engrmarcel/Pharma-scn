# Offline operation and six-hour outage playbook

This covers spec §13.7 and the Phase 3 acceptance criteria. It is written for operations leads, pharmacy managers and the implementation team. It states what keeps working without power or internet, what equipment that requires, and what has actually been tested.

## What keeps working offline

| Works offline (after the device has synced once) | Needs a connection |
|---|---|
| **Till:** selling from the cached catalogue (search, barcode or batch scan); cash sales with change; card or transfer on a standalone terminal (recorded as pending); prescription capture by a pharmacist; on-screen receipts marked "offline, not yet synced" | Opening and closing a shift; cash in and out; refunds; cancelling a synced sale; confirming card payments (supervisor); refreshing prices and stock; integrated payment providers |
| **Field app:** assigned-customer directory; catalogue with indicative prices; check-in and check-out with location evidence; visit notes and activities; draft and queued orders; completing follow-ups | Refreshing customers, catalogue and order status; activity reports; the ERP's decision on orders |

Everything captured offline is written to the device's encrypted database together with its sync item, in one transaction. It survives the app being closed, the phone or tablet restarting, and a forced shutdown (battery pulled or crashed). When the connection returns, the app sends the queue in order: automatically every 15 seconds while it is open, backing off from 2 seconds up to 15 minutes after failures; also when the app comes back to the foreground, or when the user taps sync.

**What the app does not claim:**
- An order captured offline is not accepted, and its stock is not reserved, until the ERP says so.
- Cash taken offline is "offline cash recorded" until the shift is counted and reviewed.
- A card payment on a standalone terminal is pending until a supervisor checks the settlement.
- Stock and prices on the device are a snapshot, shown with the time it was taken.
- If two tills sell the last units while offline, the server records both sales and raises a stock shortfall for a supervisor to reconcile. It does not invent stock.

## Limits

| Limit | Value | Why |
|---|---|---|
| Offline catalogue per till | 5,000 products (`PosOfflineCatalogue::MAX_PRODUCTS`) | Snapshot size and device memory; larger catalogues get an error rather than a partial list |
| Device token without contact | 72 hours (`SYNC_DEVICE_TOKEN_TTL_HOURS`) | After that the user signs in again. Unsent work stays on the device and is sent afterwards |
| Oldest field capture accepted | 30 days (`FIELD_FORCE_MAX_OFFLINE_AGE_DAYS`) | Older captures are refused (kept on the server for review), not silently back-dated |
| Device clock ahead of the server | 10 minutes (`FIELD_FORCE_MAX_CLOCK_SKEW_MINUTES`) | Protects the time evidence of visits and sales |
| Sync batch | 50 envelopes (`SYNC_MAX_BATCH`) | Larger queues are sent as several batches |
| Storage per record | About 2 KB per sale and 3 KB per visit with activities | A full day of 300 sales is under 1 MB; reserve at least 500 MB free on the device |

**Not implemented yet:**
- background sync while the app is fully closed (Android WorkManager or iOS background tasks): the app must be opened for its queue to be sent;
- an iOS build: it has not been built or tested, which needs macOS with Xcode;
- receipt printing to Bluetooth printers: receipts are shown on screen.

## Equipment for a six-hour power and internet outage

These are the minimums the operation should provide. They are planning figures, not measurements from a specific site.

| Item | Minimum | Notes |
|---|---|---|
| Till tablet or phone | Android 8 or later, 3 GB RAM, 32 GB storage, battery of at least 5,000 mAh | Set the screen to dim after 1 minute; a full charge covers about 6 to 8 hours of till use with screen dimming |
| Field phone | Android 8 or later, 3 GB RAM, battery of at least 4,000 mAh, GPS | Location is read only at check-in and check-out, so battery use is modest |
| Backup power for the till | 20,000 mAh power bank (USB-C PD) per till, or a 600 VA UPS shared by the till and the receipt printer | A 20,000 mAh bank recharges a 5,000 mAh tablet about 3 times |
| Card terminal | Its own battery, charged at the start of every shift | Standalone-terminal payments are recorded as pending in the app and confirmed against the terminal's settlement later |
| Internet router or Wi-Fi | On a UPS or a 12 V DC power bank (at least 6 hours at about 10 W, so about 60 Wh), plus a mobile-data SIM as a second link | The app works without the router, but a running link lets the queue drain as soon as service returns |
| Field charging | Car charger, or a 10,000 mAh power bank per representative | |

**Charging and outage procedure:**
- **Start of shift or day.** Charge every device and power bank to 100%. Open the app, check that **Sync status** shows nothing waiting, and refresh the catalogue so the snapshot is current.
- **When the outage starts.** Keep selling and visiting as normal; the app shows "Offline". Do not close the app between sales; leaving it open lets sync start the moment the link returns.
- **During the outage.** Rotate tablets onto the power bank before they fall below 20%. Count the cash drawer as usual; offline cash is in the drawer.
- **When service returns.** Open the app and wait for **Sync status** to show nothing waiting. Supervisors then do three things:
  - confirm the pending card payments in the back office;
  - review **sync issues** (stock shortfalls, price variances, late sales);
  - only then close and review the shift, which the app refuses to close while sales are unsent.
- **If a device is lost or stolen.** An administrator revokes it (Devices, revoke, optionally with "erase local data"). At its next contact the app is locked out and, if requested, destroys its encryption key and database.

## Tested scenario and evidence

| Test | Workload | Result |
|---|---|---|
| `tests/Feature/SixHourOutageTest.php` (server, MariaDB 10.4 locally; MySQL 8 in CI) | 2 tills × 60 sales over 6 hours (every 10th sale by card terminal), 1 representative with 12 visits and 6 orders; sent in batches of 50 after the outage; one batch resent after a "lost" response | All 150 transactions synced exactly once (the 50 replays answered from the inbox); 108 cash sales completed and 12 card sales pending; stock, reservations, both drawers and the trial balance consistent; about 28 s to process locally |
| `mobile/field_app/test/outage_test.dart` (device logic, SQLite via FFI) | 1 till × 60 sales and 1 representative × 6 visits and 3 orders over 6 simulated hours; a clean restart and a forced shutdown during the outage; failed sync attempts every hour; one lost response after reconnection | Nothing lost across restarts; every item delivered exactly once in capture order; nothing pending or failed at the end; card sales shown as pending |
| `mobile/field_app/test/e2e/live_api_test.dart` (app against the running API with demo data) | A representative and a cashier | Device registration, field sync, a till sale computed on the device with the same total as the server, offline-cash state, and shift close all pass |

**Limits of this evidence.** The device tests ran on a development computer, not on a physical phone, and the "restart" is the app reopening its database files. A physical-device run (Android tablet, 6 hours in airplane mode, followed by reconnection) should be done during pilot rollout and recorded here.
