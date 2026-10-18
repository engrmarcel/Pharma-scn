# ADR-0003: Shared-schema multi-tenancy with a fail-closed scope

- Status: Accepted (2026-10-17)

## Context
The spec (§5.3) asks for strong isolation from day one, server-side tenant validation, tenant-aware jobs and files, and a path to dedicated databases.

## Decision
- Tenant-owned tables have `organization_id`. The `BelongsToOrganization` trait adds `TenantScope`, which applies `organization_id = current` and **matches nothing when no tenant is set**. On create it fills the column from context and refuses a mismatch.
- `ResolveTenant` turns `X-Organization-Id` (API) or the session choice (web) into `TenantContext`, but only for an active membership in an active organization. It runs before route-model binding.
- Cross-tenant queries must call `Model::acrossTenants()`, and are allowed only behind platform permissions.
- Validation rules that touch tenant tables (`exists`, `unique`) always constrain `organization_id`.
- Jobs use the `TenantAware` trait.

## Alternatives considered
Database-per-tenant from the start (rejected: operational cost before product-market fit) and a third-party tenancy package (rejected: the scope logic is small, and owning it keeps the fail-closed behaviour explicit and testable).

## Consequences
Isolation depends on every tenant model using the trait, which is enforced by review and by per-model isolation tests. A move to a dedicated database later is a connection-resolution change in `TenantContext`.
