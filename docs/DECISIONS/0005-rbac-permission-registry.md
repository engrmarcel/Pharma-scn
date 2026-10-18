# ADR-0005: Own RBAC with a scoped permission registry

- Status: Accepted (2026-10-17)

## Context
Roles must be configurable per organization, platform staff must not get tenant data by default (spec §23), and permissions must be enforced in the API, jobs and exports.

## Decision
- `config/permissions.php` is the registry: each permission has a scope of `platform` or `organization`, and system role templates are defined there and synced by a seeder.
- Membership roles grant organization permissions, which are evaluated only against `TenantContext`. Platform roles grant platform permissions only.
- `Gate::before` resolves registry abilities, so controllers, Livewire actions and Blade `@can` all use one mechanism. There is no wildcard super-user.
- Custom roles are `roles.organization_id = <org>`, built only from organization-scope permissions.

## Alternatives considered
spatie/laravel-permission with teams. It was rejected because the platform/tenant split and the "no implicit super-admin" rule would need overrides anyway.
