# Project Master Specification — index

The full build specification is **`docs/source/MASTER_SPEC_v1.0.md`** ("MASTER CLAUDE CODE PROMPT — Pharmaceutical Supply Chain Network Platform", v1.0). It is the source of truth. This file records the binding rules and where each requirement is tracked.

## Product principle

Operate with our ERP · Connect through integrations · Transact through the network · Track products through the supply chain.

Three participation models: platform ERP users, connected enterprises (keep SAP/Oracle/Dynamics), and lightweight participants (web, mobile, POS or API).

## Non-negotiable rules

| Rule (spec) | How it is applied |
|---|---|
| Inspect before implementing (§2.1) | Environment inventory recorded in `IMPLEMENTATION_STATUS.md` |
| Incremental, tested phases (§2.2, §31) | Official phases 0–10; the §35 definition of done is applied before any phase is marked complete |
| No destructive actions (§2.3) | No production resets; reversible migrations; demo seeder refuses production |
| Single source of truth (§2.4) | The documents below are kept current with the code |
| Synthetic data clearly labelled (§29.2, §32) | "Demo …" organizations, `@demo.test` users, `DEMO-` identifiers that are never verifiable and rejected in production |
| Proceed without asking routine questions (§36) | Safe defaults chosen and recorded in `docs/DECISIONS/` |

## Requirement map

| Spec section | Topic | Tracked in · phase |
|---|---|---|
| §3 | Configurable branding | SYSTEM_ARCHITECTURE · P1 ✅ |
| §5 | Organizations, roles, tenancy | SECURITY_AND_PRIVACY, ADR-0003/0005 · P1 ✅ |
| §6–7 | Stack, logical architecture | SYSTEM_ARCHITECTURE, ADR-0001 |
| §8 | Auth, onboarding, platform admin | P1 ✅ (MFA → P2.0) |
| §9 | Product Master, NAFDAC identifiers | DATABASE_DESIGN, ADR-0004 · P2 (done early) ✅ |
| §10–12 | ERP, POS, field force | IMPLEMENTATION_ROADMAP · P2 |
| §13 | Offline-first | OFFLINE_FIRST_DESIGN · P3 |
| §14 | Marketplace | P4 |
| §15 | Integration gateway | ENTERPRISE_INTEGRATIONS · P5 |
| §16, §18 | Traceability, recall, logistics | TRACEABILITY_DESIGN · P7 |
| §17 | Advanced CRM | P6 |
| §19 | Analytics and AI | P8 |
| §20 | Multi-country | SYSTEM_ARCHITECTURE · foundations P1, P9 |
| §21 | Subscriptions and entitlements | ADR-0007 · foundations P1 ✅, billing lifecycle later |
| §22 | Database + generated SQL | DATABASE_DESIGN, `database/sql/…schema.sql` ✅ |
| §23–24 | Security, privacy, audit | SECURITY_AND_PRIVACY ✅ (ongoing) |
| §25 | API | API_SPECIFICATION ✅ (OpenAPI pending) |
| §26 | Event-ready architecture | SYSTEM_ARCHITECTURE §Events · foundations P1 ✅, outbox P5 |
| §27, §28 | Scalability, observability | DEPLOYMENT_GUIDE · health and JSON logs P1 ✅, load tests P10 |
| §29 | Testing | TESTING_STRATEGY, roadmap §29.2 map |
| §30 | UI/UX | Livewire UI, grows per phase |
| §32 | Seed data and demo workflows | `DemoSeeder` (P1 portion) ✅ |
| §33 | Deployment and DevOps | DEPLOYMENT_GUIDE, `.github/workflows/ci.yml` |
| §34–35 | Deliverables, definition of done | IMPLEMENTATION_STATUS |

## Document set

`docs/PRODUCT_REQUIREMENTS.md` · `docs/SYSTEM_ARCHITECTURE.md` · `docs/DATABASE_DESIGN.md` · `docs/API_SPECIFICATION.md` · `docs/SECURITY_AND_PRIVACY.md` · `docs/OFFLINE_FIRST_DESIGN.md` · `docs/ENTERPRISE_INTEGRATIONS.md` · `docs/TRACEABILITY_DESIGN.md` · `docs/DEPLOYMENT_GUIDE.md` · `docs/TESTING_STRATEGY.md` · `docs/IMPLEMENTATION_ROADMAP.md` · `docs/DECISIONS/` · `IMPLEMENTATION_STATUS.md` · `CHANGELOG.md`
