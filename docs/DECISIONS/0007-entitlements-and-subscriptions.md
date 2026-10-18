# ADR-0007: Entitlements enforced in services; plans as data

- Status: Accepted (2026-10-18)

## Context
Spec §21 asks for configurable plans, entitlements enforced by backend services (not hidden UI), and that legally required records are never disabled or deleted when a subscription lapses.

## Decision
- `config/entitlements.php` defines the vocabulary: limit keys (users, branches, warehouses, storage, …) and feature keys (`module.*`, advanced reporting, AI). Plans and their values are database rows edited by `platform.billing.manage`. The seeder creates the starting catalogue once and never overwrites commercial edits. Prices are left NULL ("by agreement") until set.
- `EntitlementService::guard()` checks the subscription state and the limit **while holding a row lock on the organization**, then runs the creation in the same transaction, so concurrent requests cannot both take the last slot.
- Modules need both platform enablement (`organization_modules`) and a `module.*` entitlement.
- In non-operational states (`suspended`, `cancelled`, `expired`), adding new consumption is blocked, while every existing record stays readable.
- Organization overrides support negotiated enterprise terms, and every change is audited.

## Consequences
Invoicing, renewal, payment collection and grace-period automation build on `organization_subscriptions` later. Usage counters for transaction-based limits are added with the modules that generate transactions (POS, marketplace).
