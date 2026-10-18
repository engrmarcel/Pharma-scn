# Product requirements

Working name is configurable (spec §3). Initial market: Nigeria; architecture ready for further African markets.

## Problem

Pharmaceutical supply in Nigeria runs across many disconnected systems: manufacturers on SAP or Oracle, distributors on spreadsheets, pharmacies on standalone POS or paper. Product identity is inconsistent, batch and expiry visibility stops at each organization's boundary, recalls are slow, and falsified products exploit the gaps.

## Participation models

1. **Powered by our ERP:** the organization runs inventory, procurement, sales, finance and POS on the platform.
2. **Connected enterprise:** the organization keeps SAP, Oracle or Dynamics and exchanges canonical data through the integration gateway.
3. **Lightweight participant:** the organization uses the web app, mobile app, POS or API without a full ERP.

## Personas and primary jobs

| Persona | Primary jobs |
|---|---|
| Pharmacy owner / pharmacist | Stock the right products, sell compliantly at POS, avoid expired or recalled stock, reorder |
| Distributor / wholesaler ops | Manage multi-warehouse stock, FEFO dispatch, serve pharmacy and hospital orders, field sales |
| Manufacturer / importer | Publish canonical product data, see channel stock, run recalls, connect their ERP |
| Hospital procurement | Find verified suppliers, run RFQs and approvals, receive with batch capture |
| Sales representative | Visit assigned customers (GPS-verified), capture orders offline |
| Platform compliance officer | Verify organizations and regulatory identifiers with evidence; govern the catalogue |
| Platform operations | Onboard and support organizations, manage modules, monitor integrations and sync |

## Phase 1 acceptance criteria (met; see IMPLEMENTATION_STATUS.md)

- A user can register an organization of any spec §5.1 type in an active country and becomes its owner. The organization starts **unverified**.
- The owner uploads documents and submits for verification. A compliance officer approves, rejects or requests changes; every step is recorded and auditable.
- Members are invited by email with roles. Permissions are enforced server-side; cashier-level roles cannot manage locations or members.
- No user can read or modify another organization's data through any endpoint, bound route, validation rule or job.
- Catalogue staff create canonical products with ingredients, a package hierarchy (with conversion factors), GTINs, jurisdiction classifications and regulatory identifiers such as the NAFDAC Registration No. Identifiers start unverified; verification is a separate, evidenced act.
- Verified organizations propose catalogue additions and corrections, which reviewers approve or reject. Stale proposals are detected by version.
- Organizations maintain their own SKU, prices (exact decimals, local currency) and reorder settings per canonical product.
- The brand name, colours and contacts can be changed by an administrator without a code change.

## Out of scope for Phase 1

ERP transactions, POS, mobile apps, offline sync, marketplace, integrations, traceability events, billing and analytics. Each is scheduled in `IMPLEMENTATION_ROADMAP.md`.

## Non-functional targets (initial)

- API p95 < 300 ms for catalogue reads at 1M products, using indexed search (OpenSearch is evaluated beyond that).
- Zero cross-tenant data exposure, guarded by automated tests on every build.
- Every material change is auditable with actor, time, correlation id and before/after values.
