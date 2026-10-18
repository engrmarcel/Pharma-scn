# ADR-0010: Field-force location evidence, visibility and mobile orders

- Status: Accepted (2026-10-21)

## Context
Spec §12 requires several things:
- event-based GPS check-in and check-out with truthful verification ("record the condition rather than inventing coordinates or falsely claiming a verified visit");
- no continuous tracking;
- retention controls;
- privacy between representatives (§12.5);
- a clear distinction between a captured offline order and an order the central ERP has accepted (§12.4).

Phase 3 will add a full sync engine, so Phase 2 records must already be safe to replay.

## Decision
- **Location is event-based only.** The API accepts a location only as part of a check-in or check-out. There is no endpoint for location pings.
- **The server classifies the evidence; the device does not claim it.** The device reports what it actually had: permission or service status, coordinates if it had a fix, accuracy, the platform's mock-location flag, and device context. The server then stores one of these results:
  - `verified`: a real fix, accurate to within `max_accuracy_m`, inside `verification_radius_m` of the customer's recorded location;
  - `outside_radius`;
  - `low_accuracy`;
  - `mock_location`;
  - `no_location`;
  - `customer_location_unknown`.
- **Contradictory or implausible reports are refused.** These include:
  - "granted" with no coordinates;
  - coordinates with any status other than "granted";
  - a device clock ahead of the server;
  - a capture older than the offline limit.
- **Evidence is immutable.** Only the representative who checked in can change a visit. Check-in and check-out evidence is never edited afterwards.
- **Retention.** `field-force:purge-locations` runs daily. After `location_retention_days` it clears raw coordinates, accuracy and device context, but keeps the verification result and the distance.
- **Visibility.**
  - Customers: `customers.view` sees every customer. `customers.view_assigned` sees only customers assigned to the user or in a territory where the user is a member. Hidden customers return 404.
  - Field activity: users see their own records, plus those of representatives in territories they supervise (`field_force.team.view`), or everything (`field_force.view_all`).
  - Restricted members are also limited to visible customers on quotations, sales orders, invoices and statements, and are closed out of organization-wide receivables aging.
- **Mobile orders are requests.**
  - Statuses: draft, then submitted, then accepted or rejected. A rejected order can be edited back to draft.
  - "Accepted" means the ERP created a sales order and either confirmed it (stock reserved earliest-expiry first) or is holding it for approval.
  - Validation runs inside a savepoint, so a rejected order leaves no sales order and no reservations behind.
  - Prices shown on the device are indicative; the ERP sets the actual price.
- **Replay safety.** Visits, orders and follow-ups carry a device-generated `client_reference`, unique per organization. Replaying a request returns the stored record (HTTP 200), and another user cannot reuse the reference.
- **Plan limit.** The `mobile_users` limit counts distinct territory members and is enforced under the organization row lock.

## Consequences
- "Verified" means something specific that supervisors can rely on. Unverified visits stay visible and are counted in reports.
- Representatives no longer see unassigned customers or their documents, so organizations must assign territories before representatives can work.
- Phase 3 can build its sync inbox on `client_reference` without changing these tables.
