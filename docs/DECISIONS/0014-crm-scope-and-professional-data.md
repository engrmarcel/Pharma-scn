# ADR-0014: CRM visibility and professional data boundaries

- Status: Accepted (2026-10-25)

## Context
Phase 6 extends the field force (ADR-0010) into a CRM. Its acceptance criterion: supervisors review authorized field activities, visits, orders and sales outcomes without violating tenant or territory permissions.

Spec §17 also asks for doctor and pharmacist profiles, detailing and feedback, while keeping boundaries around sensitive professional and personal data.

Phase 2.6 let a supervisor see every record of the representatives in the territories they supervise. A representative who also works another territory therefore exposed that territory's customers to a supervisor who does not cover it.

## Decision
- **One activity scope for all field and CRM records** (`FieldForceScope::scopeActivity`):
  - viewers always see their own records;
  - another team member's record is visible only when its customer is also visible to the viewer;
  - `field_force.view_all` sees everything.

  It applies to visits, mobile orders, follow-ups, interactions, feedback, detailing, the activity report, the customer timeline and offline follow-up sync. Hidden records return 404, not 403, so their existence is not disclosed.
- **The customer timeline adds no visibility.** It merges each source through that source's own scope. ERP sales orders and invoices appear only for holders of `sales.view`.
- **Professional contacts hold work data only:**
  - role, specialty, professional registration, work e-mail and phone, preferred contact method;
  - there are no fields for home address, date of birth or other personal data.
- **Consent is recorded and enforced.** Consent to direct contact is stored with its time and source. Outbound calls, e-mails and messages to a contact who withdrew consent are refused. Visits to the institution remain possible.
- **Anonymization is irreversible.** It erases a contact's personal fields and keeps the account history. Audit entries for contact changes record field names, not values.
- **Possible adverse events go to pharmacovigilance.** Feedback can be flagged as a possible adverse event, and the API then tells the recorder to report it to pharmacovigilance. The CRM stores no patient details. Such feedback is resolved only with the safety case reference.
- **Detailing uses approved material only:**
  - key messages are drafted, then approved by someone other than their author;
  - a representative can record only approved, unexpired messages of the product detailed, against their own visit;
  - samples are recorded with quantity and batch for accountability, but are not issued from stock by the CRM.

- **Planning and selling (6b) follow the same boundary:**
  - a visit plan is approved only by someone who can see every customer in it;
  - supervisors are not shown planned calls outside their reach;
  - target achievement shown to anyone other than the target's representative (without `view_all`) is limited to the viewer's customers and labelled `partial`;
  - team leads without a customer are visible within the lead's territory.

## Consequences
- **Phase 2.6 behaviour narrows.** Supervisors no longer see a representative's activity outside the supervisor's customer visibility. Organization-wide reviewers need `field_force.view_all` (or `customers.view` combined with team visibility).
- **Contact data now stays in the CRM.** Personal data entered in free-text fields (summaries, notes) is the user's responsibility. The forms say so, and anonymization does not rewrite free text.
- **Sample stock is not reconciled automatically** against the inventory; that would be a separate sample-stock workflow.
