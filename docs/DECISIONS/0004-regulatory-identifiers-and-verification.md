# ADR-0004: Regulatory identifiers as claims with separate verification evidence

- Status: Accepted (2026-10-17)

## Context
The spec (§9.2) requires an explicit NAFDAC Registration No. and support for other jurisdictions. A registration number existing does not prove the registration is currently valid, and the platform must never appear to speak for the regulator.

## Decision
- `regulatory_identifiers` is polymorphic (product, manufacturer or organization) and references `regulatory_authorities`, which are per jurisdiction. There are no country-specific columns.
- The submitter's `declared_status` and dates are kept separate from the platform's `verification_status`.
- `regulatory_verifications` is append-only evidence (method, source, result, notes, evidence attachment, verifier). It can only be recorded by `regulatory.verify` holders who did not submit the identifier.
- The reported status becomes `expired` once the declared expiry date passes, without rewriting the record.
- Every API and UI surface labels this as **platform verification** and states that it is not a regulator statement.
- Synthetic identifiers (spec §32) use the reserved `DEMO-`/`TEST-` prefixes. They can never be recorded as verified, and they are rejected in production.

## Consequences
When a regulator API becomes available, it plugs in as another verification `method` (`regulator_api`) with no schema change.
