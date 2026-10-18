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

## Regulatory integrity rules

- The platform **never impersonates NAFDAC or any regulator**. "Verified" always means *platform verification*, and every API response and screen that shows it says so.
- Storing a regulatory identifier records a **claim**. Verification is a separate, evidenced, append-only record made by someone with `regulatory.verify`, who cannot be the submitter.
- Synthetic data follows spec §32. Demo regulatory identifiers use the reserved `DEMO-` prefix (tests use `TEST-` or `ZZ-FIXTURE…`), are held by "FICTIONAL DEMO HOLDER", and belong to products described as synthetic. The service **refuses to mark any `DEMO-`/`TEST-` identifier verified** and rejects these prefixes entirely in production.

## Gaps and planned controls

| Gap | Plan |
|---|---|
| MFA not enforced | TOTP in Phase 1B; required for platform roles and optionally by organization policy |
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
