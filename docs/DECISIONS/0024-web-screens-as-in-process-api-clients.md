# ADR-0024: Web screens are in-process clients of the API

- Status: Accepted (2026-10-22)

## Context
Every module works through the API (about 350 endpoints), but the web application had screens for only a few of them: dashboard, catalogue browser, POS register, field force dashboard, analytics, account security and parts of the administration panel. Spec §6 and §8 ask for Livewire screens for the ERP and administration work, and spec line 291 says: "Do not duplicate core business rules across the web application, POS and mobile applications."

Validation rules, permission checks, location scopes, entitlement limits and idempotency live in the API controllers and middleware. Rebuilding those in each Livewire component would create a second copy that drifts from the first.

## Decision
- **Screens call the API in-process.** `App\Support\Web\InternalApi` sends a request to `/api/v1/...` through the HTTP kernel, as the signed-in user and for the organization chosen in the switcher (`X-Organization-Id`).
  - The request passes the same middleware, validation, policies and services as a mobile app or integration.
  - Writes carry an idempotency key, one per open form, so a double-submitted form is applied once.
  - Web requests are marked so the per-user API rate limit allows for pages that make several reads.
- **Errors come back in the screen's terms.**
  - 422 validation errors become field errors under the form's name (for example `warehouse.code`).
  - Any other refusal becomes an `ApiError` carrying the API's message, code, status and correlation id, shown as a toast.
  - When an exception handler rethrows instead of rendering (test harnesses do), the client renders the exception into the same JSON response itself.
- **The page's state is restored after each call**: request, default guard, tenant context, current route and correlation id.
- **Base class.** `App\Livewire\WebPage` declares the page's route. The navigation registry (`App\Support\Web\Navigation`) supplies its permissions, which are checked on every Livewire request. The base class also provides `attempt`, `submit` and `read` helpers and display lookups (warehouse labels, product names, batch labels).
  - Display lookups may read models directly: tenant-scoped models stay fail-closed, and the shared product master is public within the platform. They never decide anything.
- **One navigation registry.** Sections, pages, modules and permissions are listed once. The menus, page banners and section tabs are built from it, and pages whose routes do not exist yet stay hidden.
- **Shared form pieces.** A product picker (the organization's catalogue first, then the product master) and a product master form are reused across modules.

## Consequences
- A screen holds no business rule, and a rule changed in the API applies to the web at once.
- Every web action is covered by the API's tests. The screen tests check that the screens reach the API and display what it returns.
- The web sees what the API returns. Where a payload carries only ids, the screen looks up names for display.
- An in-process call costs one pass through the middleware stack. That is negligible next to a database round trip; Phase 10 measurements will show whether it matters anywhere.
- The existing POS register and older screens that call services directly keep working. They can move to the client when they are next changed.
