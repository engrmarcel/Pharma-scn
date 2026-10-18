# ADR-0001: Modular monolith on Laravel 13

- Status: Accepted (2026-10-17)

## Context
The spec (§6) asks for a modular monolith first, with no early microservices. The domain is transaction-heavy, and POS, mobile and integrations must share the same business rules.

## Decision
Use a single Laravel 13 application (PHP 8.3) with domain modules under `app/Domain/*`. Services own all writes and are the only path shared by web, API, POS, mobile and integrations. Asynchronous work goes through Laravel queues. The API is versioned REST (`/api/v1`), and the web UI uses Livewire 4 with Tailwind 4.

## Consequences
- One deployable unit, with transactions spanning modules where needed (for example, a sale plus its inventory movements).
- Module boundaries are kept by convention and code review, and later by architecture tests. A module is extracted only when a measured bottleneck justifies it.
