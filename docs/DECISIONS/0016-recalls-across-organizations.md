# ADR-0016: Recalls reach every holder; recovery is derived, coverage is never claimed complete

- Status: Accepted (2026-10-26)

## Context
Phase 7 acceptance:
- authorized users can identify participating locations holding affected batches;
- recalled stock is blocked by business rules.

Spec §16.5 asks for notifications, acknowledgement tracking, returned and unaccounted quantities, closure, and never claiming complete coverage. Every organization keeps its own batch records and books (ADR-0003), so a recall must act in many organizations at once.

## Decision
- **Initiation.**
  - Platform compliance (`recalls.manage`) can recall any product.
  - An organization (`recalls.initiate`) can recall only products whose manufacturer record points to it, as brand owner.
  - Activation is done by a different person from the creator.
- **Activation acts in each holder's own books.**
  - The holders are every organization with a batch record for the lots, so also those that hold none now.
  - For each holder, inside its tenant context: the batches are marked recalled, open reservations released, and available stock moved to quarantine through its own ledger (reason `recall_quarantine`). Its trace chain therefore records a `recall` event.
  - The holder gets a notice with its holding at that moment and its chain position, an e-mail to owners and members with `recalls.respond`, and a `recall.notice_issued` webhook event.
  - These are the only writes into other organizations, and they only make stock less available. Release after a cancelled recall is a person's decision in each organization.
- **Recovery is derived, not declared.**
  - From the holder's own events after its chain position at notice: written off (negative adjustments), returned to supplier, recovered from customers, still held, and anything sold, dispensed or shipped after the recall (which the rules should have prevented).
  - Unaccounted = held at notice + recovered − still held − written off − returned − left after the recall. Count corrections that find less stock therefore show as unaccounted.
  - The chain position, not a timestamp, separates before from after, so movements in the same second as the recall are classified correctly.
- **Exposure and coverage.**
  - The report (issuer and platform staff) lists each holder with what it received, what it passed to platform organizations, to parties outside the platform and to patients before the recall, plus its recovery.
  - Names of parties outside the platform are visible to platform staff only; the issuer sees counts. Each holder sees its own customers to contact them.
  - A coverage statement says the report covers stock recorded on the platform only.
- **Closure.** All notices must be acknowledged, or the closure carries an override reason. Batches stay recalled after closure.
- **Regulatory incidents.** NAFDAC reg. 12 and 14 deadlines (24 h for stolen or lost; 48 h for identifier errors, unscannable carriers and missing identifiers) are computed on a per-organization incident log. Submission happens through the regulator's channel and is recorded by reference.

## Consequences
- **Scope:** recalls work at lot level. A serial-level recall (a subset of a lot) is not modelled. Scans of serials of a recalled lot report "recalled".
- **Batch-number matching:** holders are found by product + manufacturer batch number. A holder that recorded a lot under a mistyped batch number is not reached. Expiry-mismatch checks and corrections reduce this but cannot rule it out, which is one more reason coverage is never claimed complete.
