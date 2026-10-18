# Changelog

## [Unreleased]

### 2026-10-18: Phase 2.0, account security
- TOTP two-factor authentication (pragmarx/google2fa) with QR enrolment, replay protection, 8 single-use hashed recovery codes, web login challenge, self-service disable, and platform reset for lost devices.
- Email verification with signed links; self-service registration endpoint.
- Security policy (`config/security.php` + runtime admin setting). **Two-factor and email verification are OFF by default** and can be switched on in production without a redeploy. Organizations may require two-factor for their members when the platform allows it.
- Account security web page; tests for the password-reset flow.

### 2026-10-18: Phase 1 completion against the full spec (§31)
- Subscription and entitlement foundations: plans, plan entitlements, organization subscriptions (with history), overrides, `EntitlementService::guard()` with row locking; enforcement for users (including pending invitations), branches, warehouses, storage and modules; tenant and admin billing endpoints.
- Domain events after commit (`OrganizationRegistered`, `OrganizationVerificationChanged`, `ProductCreated`, `ProductUpdated`, `RegulatoryIdentifierUpdated`).
- Health readiness endpoint, operations status, failed-job listing and retry (no payloads), admin user directory, country configuration endpoint, JSON log channel.
- CI workflow (MySQL 8, Pint, tests, audits).
- Synthetic demo data per §32: 7 organizations across all chain roles, role users, branches and warehouses, 5 products with `DEMO-` NAFDAC identifiers. Synthetic identifiers can never be marked verified and are rejected in production.
- Docs re-aligned to official phases 0–10; module dependency map, forward entity model, external-credentials inventory, backup/restore and rollback procedures; ADR-0007.

### 2026-10-18: Phase 1 foundation and Product Master
- Laravel 13 modular monolith; Sanctum; Livewire 4; Tailwind 4.
- Multi-tenancy with fail-closed scope, tenant-aware jobs and files.
- Permission registry and all §5.2 roles.
- Onboarding, invitations, verification workflow.
- Product Master with NAFDAC/regulatory identifiers and governance.
- Audit log, idempotency, correlation IDs, security headers, branding, country configuration.
- Generated SQL schema; web login, dashboard, catalogue browser, verification queue.
