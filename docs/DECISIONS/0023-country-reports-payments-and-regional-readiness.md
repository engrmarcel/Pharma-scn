# ADR-0023: Country-aware reports, payment methods per country, and regional deployment readiness

- Status: Accepted (2026-10-22)

## Context
Phase 9 also lists local payment adapters, country-aware reports and regional deployment readiness. Spec §6.5 names regional deployment as an extension point, routing tenants to per-country infrastructure.

## Decision
- **Country-aware platform reports.**
  - The platform view breaks organizations, verification, active subscriptions, monthly recurring revenue and marketplace value down by country (marketplace value is counted by the buyer's country).
  - Money stays per currency. With `reporting_currency`, totals are also presented converted at exchange rates recorded by platform staff (`/admin/exchange-rates`, effective-dated, with a source), directly or inverted. The rates used are listed.
  - An amount without a rate is listed as unconverted, never estimated. Conversion is for presentation only; tenant books are never converted.
- **Payment methods per country.** `GET /organization/payment-methods` lists cash, the standalone terminal (confirmed by a settlement check), and the electronic providers available in the organization's country and currency (ADR-0021).
  - A real provider (for example a card acquirer or mobile money operator) is added by implementing `PaymentProvider`, with the provider's documented signature check. It is then declared in `config/payments.php` with its countries and currencies, and enabled in the country profile.
  - No real adapter is included. Each needs a contract, credentials and the provider's current documentation.
- **Regional readiness.**
  - Every organization has a `data_region`, taken at registration from its country's `data.residency_region`, else the deployment's region.
  - A deployment knows its own region (`PLATFORM_REGION`). With `PLATFORM_REGION_ENFORCE`, it refuses requests for organizations hosted elsewhere with `421 organization_in_other_region` and that region's address (`PLATFORM_REGION_URLS`), so a front door or client can redirect.
  - The health check reports the region.

## Consequences
- **What exists now.** A single deployment serves everything (the default, enforcement off), and the data model and guard are ready for several.
- **What a multi-region rollout still needs.**
  - A deployment and database per region.
  - Replication of the shared platform data (catalogue, reference data, users and memberships) or a global service for it.
  - Region-aware login routing.
  - A way to move an organization between regions.

  These are Phase 10 infrastructure work, to be justified by a residency requirement or by measurements.
- **Cross-region marketplace.** Trading between organizations in different regions would need the shared marketplace records to live where both parties can reach them. This is not designed yet.
