# ADR-0011: Device registration and the offline sync contract

- Status: Accepted (2026-10-21)

## Context
Spec §13 asks for a documented synchronization contract. It must cover:
- client-generated transaction ids;
- device identifiers;
- acknowledgements;
- conflict states;
- retries;
- "never silently discard a locally committed sale or order";
- device revocation when connectivity resumes.

Phase 2 already made field-force records idempotent through `client_reference` (ADR-0010).

## Decision
- **Devices.**
  - The app registers once per user and organization with `POST /devices` (role `field`, or `pos` with a terminal).
  - It receives a Sanctum token bound to the device (`personal_access_tokens.device_id`), which replaces the sign-in token.
  - The token's expiry slides with contact (`SYNC_DEVICE_TOKEN_TTL_HOURS`, default 72).
  - `EnsureDeviceActive` runs on every authenticated API request. It refuses tokens of revoked devices once with `401 device_revoked {wipe}`, deletes the token, and refuses a device token used for any organization other than its own.
- **Envelopes.** `POST /sync/batches` accepts up to 50 envelopes:

  ```text
  {client_txn_id, type, schema_version, client_created_at, attempt, payload}
  ```

  Types and accepted schema versions are listed in `config/sync.php`.
- **Durable before processing.** Each envelope is written to `sync_inbox` first (unique on organization + `client_txn_id`, together with device, user, client and server timestamps). It is then processed in its own transaction, in the order sent.
- **Acknowledgement per envelope**, one of:
  - `synced` (with `result`, carrying the server's ids and statuses);
  - `conflict` (a 409 domain rule: the server's state disagrees);
  - `rejected` (invalid, not permitted, unsupported type, or a device clock ahead);
  - `retry_required` (stored but not processed because of a temporary failure; a permanent failure is rejected after `SYNC_MAX_PROCESSING_ATTEMPTS`).

  A replayed `client_txn_id` returns the stored acknowledgement with `duplicate: true` and is never applied twice. The same id from another device is refused.
- **Same rules as online.** Handlers call the same services and validation as the REST endpoints (`FieldForceRules`). Records are referenced by their device `client_reference` (for example `visit_client_reference`), so a device can queue dependent steps without knowing server ids.
- **Nothing refused is discarded.** Conflicted and rejected envelopes stay in the inbox. Reviewers with `sync.reconcile` record how each was handled (`/sync/inbox/{id}/resolve`). Problems that replay finds but does not refuse, such as stock shortfalls (3b), are raised as `sync_issues`. `/sync/reconciliation` summarises both per device.

## Consequences
- The app needs one endpoint and one state machine. The server stays the only judge of acceptance.
- Envelope types are versioned, so older apps keep working while newer apps add types.
- Processing is synchronous inside the request. At 50 envelopes per batch this is fine; a queue can be added behind the same contract if volumes require it.
