# Security and privacy

## Implemented controls (Phase 1)

| Area | Control | Where |
|---|---|---|
| Authentication | Bcrypt-hashed passwords; Sanctum tokens hashed at rest with 30-day expiry; per-device revoke; password reset revokes all tokens | `AuthController` |
| Brute force | 5 attempts/min per email+IP and 30/min per IP (API and web) | `AppServiceProvider` rate limiters |
| Account suspension | Blocks login; deletes API tokens and web sessions immediately | `PlatformAdminController::suspendUser`, `EnsureUserIsActive` |
| Authorization | Server-side registry permissions via `Gate::before`; every controller action and Livewire action authorizes; no hidden-button security; no super-user bypass of tenant data | `AccessControl`, controllers, Livewire components |
| Tenant isolation | Fail-closed global scope; membership-validated tenant resolution; create-time tenant guard; tenant-scoped validation rules (`exists`/`unique` with `organization_id`); tenant-aware jobs; same 403 for unknown and foreign organizations (no enumeration) | `Support/Tenancy`, `ResolveTenant`, `TenantIsolationTest` |
| Privilege separation | Conflict-of-interest guards: reviewers cannot decide on their own organization's verification or change requests, and cannot verify identifiers they submitted. Only owners can grant or remove the owner role; the last owner is protected | services |
| Mass assignment | State fields guarded; silent discards throw outside production | models, `AppServiceProvider` |
| Input validation | Validation on every endpoint; regex-constrained identifiers; numeric money with scale limits | controllers, `ProductRules` |
| SQL injection | Query builder bindings only; LIKE wildcards escaped in catalogue search | `Product::scopeSearch` |
| CSRF / XSS | Laravel CSRF on web forms; Blade auto-escaping | framework |
| Files | Extension and MIME allow-list (pdf/jpg/png/webp), 10 MB limit, generated file names, private disk, tenant prefix, SHA-256 recorded, served only through authorizing controllers; platform access to tenant documents is audited | `AttachmentService` |
| Secrets in logs | Audit logger redacts password/token/secret/key fields recursively; `mfa_secret` is an encrypted cast and hidden from serialization; invitation tokens stored only as SHA-256 | `AuditLogger`, `User` |
| Headers | `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, HSTS on HTTPS | `SecurityHeaders` |
| Errors | Domain errors return stable codes without stack traces; correlation id returned for support | `bootstrap/app.php` |
| Audit | Append-only audit log of privileged and business events | `AuditLogger` |
| Entitlements | Plan limits and module access enforced in services and middleware under a row lock; direct API calls cannot bypass them | `EntitlementService`, `EnsureModuleEnabled` |
| Operations data | Failed-job listings omit payloads; the health endpoint returns pass/fail only | `OperationsController` |
| Field-force location | Location read only at check-in and check-out (no background tracking); the server classifies the evidence and never invents coordinates; raw coordinates are purged after `FIELD_FORCE_LOCATION_RETENTION_DAYS` (default 365); visits are visible only to the representative, supervisors of their territories and `field_force.view_all` (ADR-0010) | `LocationEvidence`, `FieldForceScope`, `field-force:purge-locations` |
| Representative privacy | `customers.view_assigned` limits representatives to their territories' customers and those customers' documents; hidden records return 404 | `FieldForceScope` |

## Regulatory integrity rules

- The platform **never impersonates NAFDAC or any regulator**. "Verified" always means *platform verification*, and every API response and screen that shows it says so.
- Storing a regulatory identifier records a **claim**. Verification is a separate, evidenced, append-only record made by someone with `regulatory.verify`, who cannot be the submitter.
- Synthetic data follows spec §32. Demo regulatory identifiers use the reserved `DEMO-` prefix (tests use `TEST-` or `ZZ-FIXTURE…`), are held by "FICTIONAL DEMO HOLDER", and belong to products described as synthetic. The service **refuses to mark any `DEMO-`/`TEST-` identifier verified** and rejects these prefixes entirely in production.

## Two-factor authentication and email verification (OFF by default)

Both controls ship **disabled**, at the owner's request, and are switched on deliberately after deployment.

| Setting | Values | Default |
|---|---|---|
| `mfa_mode` | `off` · `optional` (users may enrol; organizations may require it for their members) · `required_for_platform_staff` · `required_for_all` | `off` |
| `email_verification_required` | `true` / `false` | `false` |

There are two ways to switch them:
- Set `SECURITY_MFA_MODE` and `SECURITY_EMAIL_VERIFICATION_REQUIRED` in the environment.
- Change them **at runtime without a redeploy**: `PUT /api/v1/admin/security-settings` (needs `platform.settings.manage`). This is audited and overrides the environment values.

Behaviour:
- **Two-factor login** uses RFC 6238 time-based codes (any authenticator app):
  - The secret is encrypted at rest. A code is accepted only once (the last-used time step is tracked under a row lock), with ±30 s tolerance for clock drift.
  - There are 8 single-use recovery codes, stored as SHA-256 hashes inside an encrypted column.
  - Setup requires the account password. Turning it off requires the password plus a code, and is refused when policy requires two-factor for the user.
- **Login challenge:**
  - API: `POST /auth/tokens` returns `401 mfa_required` until `mfa_code` or `recovery_code` is supplied.
  - Web: after the password step, a separate challenge page is valid for 5 minutes.
- **When the platform mode is `off`, nobody is challenged.** Enrolments are kept, so turning it back on resumes challenges.
- **Unenrolled users under a mandatory mode** can only reach their profile, sign-out and two-factor setup (`403 mfa_enrollment_required` elsewhere).
- **Organization requirements:**
  - An organization requirement (`PATCH /organization/security`) applies only while acting in that organization, and only when the platform mode is not `off`.
  - The person enabling a requirement must be enrolled themselves, to avoid lock-out.
- **Lost device:** platform staff reset a user's two-factor with `POST /admin/users/{id}/mfa-reset` (reason required, audited, all tokens revoked).
- **Email verification:**
  - Sign-up (`POST /auth/register`) sends a signed verification link only when verification is required.
  - Unverified users can then only view their profile, resend the link and sign out (`403 email_unverified`).

## Integration gateway (Phase 5a)

- External systems authenticate with a connector key, not a user token. The key is shown once, stored as a SHA-256 hash, compared in constant time and rotatable (the old key stops working at once). It reaches only `/integration/inbound/*`.
- Optional request signing: HMAC-SHA256 over `timestamp.body`, with a 5-minute window against replay.
- Rate limits apply per connector, and the organization's integrations module and subscription are checked on every request.
- Connector credentials and signing secrets are encrypted at rest (`APP_KEY`) and write-only. The API shows only credential names, and the audit trail records only names.
- Payload previews and error details are redacted by key name (`config/integrations.php` `redact_keys`: passwords, tokens, keys, card and bank numbers, patient identifiers, contact details).
- Unexpected exception text is logged and never stored on the message or returned to the source system.
- Files and IDocs: XML with a DOCTYPE is refused and XML loads with no network access (protection against XXE and entity expansion). Files have size and record limits (20 MB, 10,000 records).
- SFTP: the server's host-key fingerprint must be pinned (`settings.sftp.host_fingerprint`), and connections to unverified hosts are refused. Credentials are stored encrypted.
- CSV exports neutralize cells that a spreadsheet would execute as a formula (`=`, `+`, `@`, and `-` not followed by a number).
- Webhooks:
  - **SSRF protection.** URLs must be HTTPS, carry no credentials, and point at public addresses: no loopback, RFC 1918, link-local/cloud metadata or reserved ranges, and no `localhost` or `.internal` names. This is checked at creation and at every delivery against the resolved addresses, and the checked address is pinned for the connection. Redirects are not followed. `WEBHOOKS_ALLOW_HTTP` and `WEBHOOKS_ALLOW_PRIVATE_NETWORKS` exist for local development only.
  - **Signing.** Payloads are signed (HMAC-SHA256 over timestamp and body) with a per-subscription secret, which is encrypted at rest and shown once.
  - **Data in the delivery log.** Only the first 300 characters of a failing response are kept.

## CRM (Phase 6a, ADR-0014)

- **Visibility.** Field and CRM records are visible to their owner, and to team members only where the viewer can also see the customer (supervisors stay within their territories).
- **Contacts.** Professional contacts hold work data only. Consent to direct contact is recorded and enforced. Anonymization is irreversible and keeps account history, and contact changes are audited by field name, not value.
- **Adverse events.** Possible adverse events are routed to pharmacovigilance; the CRM keeps no patient data.
- **Detailing.** Only approved key messages are recorded, with four-eyes approval.

## Traceability (Phase 7a, ADR-0015)

- **Ledger integrity.** Events are append-only in the application and hash-chained per organization, so tampering at database level is detected by the integrity check.
- **Visibility.** Events are visible only to the organizations involved. Another organization's counterparty names and document references are withheld. Platform-wide access needs `traceability.view_all`.
- **Public scans** return no organization, price or patient data, and say that a scan does not prove authenticity. QR tokens are random and unguessable. Scan logs keep a keyed hash of the client address, never the address.
- **Dispensing events** record no patient or customer data.

## Recalls (Phase 7b, ADR-0016)

- **Who acts.** Recalls are prepared by the brand owner or platform compliance and activated by a second person.
- **Writes into other organizations.** Activation is the only write into other organizations' books: batches are marked recalled and available stock is quarantined, which only makes stock less available. It runs in each holder's context and appears in each holder's audit and trace chain. Cancelling never releases stock automatically.
- **What each party sees.** The exposure report is for the issuer and platform compliance. The names of parties outside the platform are shown to platform staff only; the issuer sees counts. Holders see only their own customers.

## Countries (Phase 9a, ADR-0021)

- **Activation is gated and audited.** Only `platform.countries.manage` changes country configuration; every change is audited, and a country goes live only when its checklist passes, including completed tax, regulatory and legal reviews with references. The platform does not claim compliance for a country before that.
- **Onboarding by country.** Organizations can record identifiers only with their own country's regulators; identifiers start unverified, as before.

## Analytics (Phase 8a, ADR-0018)

- **Tenant boundary.** Analytical queries use the query builder on a separate connection, not tenant-scoped models, so each one filters on the current organization explicitly; tests check that another organization's figures never appear.
- **Locations and permissions.** Location-restricted members see their warehouses only. Organization-wide dashboards need access to all locations. Each area needs its own view permission, and stock value needs the valuation permission. What a viewer may not see is listed as withheld, not shown as zero.
- **Personal data.** Dashboards show customer and supplier names and cashier names to members who can already see those records; no patient data is used.

## Reporting assistant (Phase 8c, ADR-0020)

- **Read-only by construction.** The assistant can only run catalogue reports, each through the dashboard service with the asker's permissions and locations. Plans naming anything else are rejected and logged. Change requests are declined.
- **External AI is off by default** and needs both the platform operator's configuration (`ANALYTICS_AI_PROVIDER`, API key) and the organization administrator's audited consent with an acknowledgement.
- **Data minimisation.** Even then only the question is sent, with e-mail addresses, phone numbers and long numbers removed. Report results, product lists and records never leave the platform; answers are written locally. Names in a question cannot be detected, which the consent text states.
- **Logs.** Questions are stored redacted and deleted after 180 days.

## Forecasts and recommendations (Phase 8b, ADR-0019)

- **No autonomous action.** The recommendation engine writes only recommendation rows; a test confirms that generation leaves requisitions, orders, stock movements, journals, adjustments, trace events and incidents unchanged.
- **Human decision under the person's own rights.** Accepting a reorder creates a draft requisition as the accepting person, checked against `procurement.requisition` and their warehouse access; approval and ordering keep their normal rules. Other types record the decision only.
- **Data stays local.** The built-in forecaster runs on the platform. Another provider that sends data out would fall under the external-AI controls (8c).

## Logistics (Phase 7c, ADR-0017)

- **Shared shipments.** A shipment is visible to its shipper and its assigned carrier only. The carrier records execution (dispatch, tracking, temperatures, proof of delivery); planning, cancellation and excursion assessment stay with the shipper. Drivers must be active members of the shipper or the carrier.
- **No writes to the books.** Shipments never post stock or money, so a carrier cannot change another organization's stock.
- **Proof of delivery.** Signature and photo are stored as attachments of the shipper's organization. Receiver names and locations are personal data kept with the delivery record.

## Gaps and planned controls

| Gap | Plan |
|---|---|
| No upload malware scanning | ClamAV (or a cloud scanner) via a queued job that updates `scan_status`; block downloads of `infected` files |
| Content-Security-Policy not set | Add once the asset pipeline is final (Livewire needs nonce support) |
| Dependency scanning | `composer audit` and `npm audit` in CI |
| Secrets management | Production secrets come from the host's secret store, never `.env` in the repo; `APP_KEY` rotation procedure goes in the deployment guide |
| Encryption at rest | Managed database and disk encryption; encrypted backups |
| Support access to tenant data | Time-boxed, reason-logged "break-glass" membership workflow, to design before production |

## Privacy

- Nigerian Data Protection Act 2023 (NDPA) and the equivalent laws of expansion countries apply. Legal review is required before launch.
- Data minimization: field-force GPS is collected only at check-in and check-out events, never continuously (spec §12.2).
- Personal data of pharmacy customers and patients is out of scope for Phase 1. Any later collection needs a documented lawful basis and retention rules.
- No data is sent to external AI services without explicit authorization (spec §19).
