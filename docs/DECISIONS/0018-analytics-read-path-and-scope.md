# ADR-0018: Analytics read through their own path, under the viewer's permissions and locations

- Status: Accepted (2026-10-22)

## Context
Spec §19 asks to separate analytical workloads from transactional operations, and to show users analytics only for the organizations and locations they are authorized for. Phase 8 acceptance: analytics respect permissions and tenant boundaries.

Until now reports were computed in each module at request time (CRM analytics, POS reports, finance reports). An executive view spans every area, so it must apply each area's rules, not one blanket permission.

## Decision
- **Separate read path.**
  - Every analytical query goes through `AnalyticsDb`, which uses the connection named in `ANALYTICS_DB_CONNECTION` (for example `analytics_replica`, a read replica). Unset, it uses the default connection.
  - A small analytical store, `analytics_daily_demand` (net units issued by sale per organization, warehouse, product and day), is rebuilt from the inventory ledger by `analytics:refresh` (nightly, last 7 days; `--full` for the history window). The ledger stays the source of truth, and a refresh replaces its day range, so reruns are safe. Dashboards that use it show when it was last refreshed.
- **Scope, in `AnalyticsScope`.**
  - Organization: every query filters on the current tenant explicitly. These queries bypass the Eloquent tenant scope, so this is enforced by code review and tests.
  - Locations: a member limited to some branches or warehouses gets figures for those warehouses only, and may narrow to one of them. Dashboards that cannot be split by warehouse (marketplace, integrations, synchronization, recalls) need access to all locations (`analytics_requires_all_locations`).
  - Permissions: each dashboard needs its area's view permission (`sales.view`, `procurement.view`, `inventory.view`, `pos.reports.view`, `field_force.reports.view`, marketplace, `integrations.view`, `sync.reconcile`, recall permissions). Stock value needs `inventory.valuation.view`.
- **Withheld, never zero.** The executive view (`analytics.view`) is assembled from the area dashboards. A section the viewer may not see is listed as withheld with the reason (permission or location scope). Withheld figures, like stock value, are marked as withheld instead of being set to zero.
- **Money per currency.** Amounts are never added across currencies.
- **Estimates are labelled.** Days of cover and expiry risk carry `kind` (`estimate` or `prediction`) and their basis.
  - Expiry risk applies the average net daily demand of the last 90 days to each product's batches, earliest expiry first (FEFO).
- **Platform view.** Platform staff with `platform.billing.manage` see subscription counts and monthly recurring revenue at list price (annual ÷ 12, trials excluded), organizations and marketplace totals. No tenant's books.

## Consequences
- **Freshness.** Demand-based figures lag by up to a day. Stock, sales and operations figures are read live from the read path (replica lag, when one is used).
- **Scale.** A dedicated warehouse (spec §6.5) can later replace `AnalyticsDb` and the fact table without changing the dashboards' contracts.
- **Recommendations.** Forecasts and recommendations (8b) and the reporting assistant (8c) build on the same scope.
