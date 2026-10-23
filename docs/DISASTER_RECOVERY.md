# Disaster recovery (Phase 10c)

For the platform operations team. It states what is backed up, how each kind of failure is recovered, what has been tested and measured, and what has not. The day-to-day offline behaviour of tills and the field app is in OFFLINE_OUTAGE_PLAYBOOK.md.

## 1. Targets

| Target | Value | Basis |
|---|---|---|
| Recovery point (RPO), database | 5 minutes with point-in-time recovery; 24 hours from the nightly logical backup alone | Managed database PITR (DEPLOYMENT_GUIDE.md); `ops:backup` daily |
| Recovery time (RTO), database | 1 hour to a restored, checked database | Measured restore rates (section 4) leave a wide margin at current sizes; re-measure as data grows |
| Transactions captured offline | No loss | Devices keep unsent work in their encrypted queue until the server acknowledges it; replays are idempotent |

These are the targets the procedures are designed for. Only the parts marked *tested* below have been exercised.

## 2. What is protected, and how

| Data | Protection | Tested |
|---|---|---|
| Database | Managed snapshots and point-in-time recovery (primary). `ops:backup`: an independent daily logical copy (consistent snapshot, compressed, SHA-256 and a manifest of row counts and schema version) on its own disk, retained `BACKUP_RETENTION_DAYS` (14) with at least 3 kept | Logical backup and restore: **yes**, automatically (section 3). Managed PITR: by the hosting provider's own procedure, to be drilled on staging |
| Attachments (verification documents, proofs of delivery) | Object storage with versioning and cross-region replication | No: provider feature, to be drilled on staging |
| Work captured offline on tills and phones | Device queue, replayed in order with idempotent client references | Yes: six-hour outage test (OFFLINE_OUTAGE_PLAYBOOK.md) |
| Outbound events to integrations | Written in the same transaction as the business change (transactional outbox); deliveries are retried with backoff, lost or interrupted ones are picked up every minute, abandoned ones are kept and can be redelivered | Yes: integration tests |
| Inbound integration messages | Stored before processing; quarantined or failed messages are kept and can be reprocessed; messages stuck in processing are released | Yes: integration tests |

## 3. The restore drill

`php artisan ops:restore-drill` restores a backup (the newest, or `--file=`) into a throwaway database whose name must end in `_restore_drill`, never the live one, and checks it:

1. **Checksum**: the file is byte for byte what was written.
2. **Tables**: every table of the backup is present, and no durable table has fewer rows than the manifest recorded just before the snapshot. Volatile tables (cache, sessions, queue, idempotency keys) are not compared.
3. **Schema**: this release knows every migration in the backup (a newer backup needs a newer release); migrations of this release that the backup does not have yet are listed, because they will run after a restore.
4. **Consistency**: the same invariants as `ops:consistency`: stock equals its ledger, nothing negative or over-reserved, reservations match, journals balance, completed sales are paid and invoiced, document numbers are unique, every traceability hash chain verifies.

It records the outcome in `recovery_drills` with the measured recovery time and the age of the data, drops the drill database (unless `--keep`), and opens a critical operational alert if the drill fails. With `BACKUP_ENABLED=true` the scheduler runs a backup every night (02:15) and a drill on the first of every month (04:00), and the monitor raises an alert when the last good backup is more than 26 hours old or the last passed drill more than 100 days old.

The automated test suite takes a real backup with the client tools and restores it on every CI run (`BackupRestoreDrillTest`), including a damaged backup that must fail the drill and raise an alert, and a refusal to restore over a database not named for drills.

## 4. Measured (test machine, 2026-10-23)

| Database | Rows | Backup | Compressed size | Restore and checks | Of which import |
|---|---|---|---|---|---|
| Development demo data | about 2,000 | 5 s | 0.1 MB | 18 s | 17 s |
| Load-test data (docs/PERFORMANCE_AND_LOAD_TESTING.md) | 133,633 | 8 s | 6.5 MB | 167 s | 161 s |

The test machine commits slowly (section 2 of the load-testing document), so the import rate here, about 830 rows a second, is a floor. Production restores should be re-measured on staging with production-sized data; the drill records the figures each month.

## 5. Procedures

### 5.1 Database lost or corrupted
1. Put the platform in maintenance (`php artisan down --secret=<token>`). Tills and field apps keep working offline and queue their work.
2. Restore the managed database to the latest point before the incident (PITR) into a new instance. If PITR is unavailable, restore the newest good logical backup: create the database and pipe the `.sql.gz` into `mysql`, after checking its SHA-256 against the manifest.
3. Point `DB_*` at the restored instance, run `php artisan migrate --force` (only this release's newer migrations run) and `php artisan ops:consistency`. Do not reopen if it fails; investigate first.
4. `php artisan up`. Devices sync their queues; replays are idempotent, so work already on the server is not applied twice.
5. Work committed between the restore point and the incident, and not captured offline, is lost: list it from integration partners (their webhooks and messages carry ids) and from the audit log of the old instance if it is readable.

### 5.2 A bad release
Switch to the previous release and run `php artisan queue:restart` (DEPLOYMENT_GUIDE.md, Rollback). Migrations are additive, so the previous release runs on the newer schema. If a release corrupted data, restore the affected rows from a drill restore (`ops:restore-drill --keep`) rather than restoring the whole database over newer work.

### 5.3 Queue or scheduler stopped
The operations monitor raises alerts within minutes (heartbeats). Restart workers or the scheduler. Nothing is lost: integration messages, webhook deliveries and offline sync batches are stored first and picked up when processing resumes; failed jobs are listed under Administration → Operations and can be retried.

### 5.4 Region or data-centre outage
Each region is a separate deployment with its own database (ADR-0023): an outage affects only the organizations hosted there. Recover the region's database from its PITR or its logical backups into another zone of the same region (data residency), then follow 5.1. Cross-region failover is deliberately not automatic, because moving an organization's data out of its region needs the organization's agreement.

### 5.5 Accidental deletion or a tampered record
Restore the newest backup from before the change with `ops:restore-drill --file=<backup> --keep`, copy the affected records back through the application (so the audit log and the ledgers stay consistent), then drop the drill database. Traceability events are never edited in place: a wrong event is corrected or reversed with a new event, and `ops:consistency` reports any chain that no longer verifies.

## 6. Not yet done

- Managed PITR, object-storage versioning and cross-zone restore have not been drilled; they are the hosting provider's procedures and need a staging exercise.
- Restore times are from a slow test machine and small data; they must be re-measured on staging hardware with production-sized data.
- Automatic failover between instances of the database is a hosting choice (a managed MySQL with a standby), not part of the application.
