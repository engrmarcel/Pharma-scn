# ADR-0020: The reporting assistant plans read-only reports; data stays on the platform

- Status: Accepted (2026-10-22)

## Context
Spec §19.2 asks for natural-language business reporting. It also says:
- AI outputs must be labelled as generated explanations;
- no AI may take financial, inventory or regulatory actions;
- confidential enterprise or patient data must not go to external AI services without explicit authorization, agreements and data protection controls.

## Decision
- **Plan, check, run, write.**
  - A question becomes a plan: a list of reports from a fixed catalogue (`ReportCatalog`).
  - The plan is checked: catalogue reports only, at most five, periods clamped to today and at most two years.
  - The reports run as the asking user through the same services as the dashboards, so permissions and locations apply. A report the user may not see is reported as not shown.
  - The answer is written on the platform from the report results, quoting the figures.
- **No actions.** The catalogue has no write report. Requests to approve, create, order, pay, adjust, write off and so on are listed as declined, and the answer says the assistant can only report. A test confirms that requisitions, orders, movements, journals, adjustments and recommendations are unchanged.
- **Local by default.** The built-in planner (`rules-v1`: keywords and plain-English periods) runs on the platform.
- **External planning only with two keys.**
  1. The platform operator sets `ANALYTICS_AI_PROVIDER=anthropic` with an API key.
  2. The organization's administrator (`organization.manage`) enables external processing with an explicit acknowledgement. This is stored on the organization and audited.

  Then `ClaudePlanner` uses the official Anthropic PHP SDK (default model `claude-opus-5-5`, low effort, structured output whose schema only allows catalogue report names).
  - It receives only the question, after e-mail addresses, phone numbers and long numbers are removed, plus today's date and the catalogue descriptions. It never receives report data, product lists or records.
  - Its plan is checked like any other, and names outside the catalogue are rejected and logged.
  - On a refusal, error or timeout, the built-in planner answers and the answer says so.
- **Labelled and logged.** Every answer has `kind: generated_explanation`, a label telling the user to check figures in the dashboards, caveats (estimates, predictions) and the reports used.
  - Questions are logged in redacted form with the planner, the reports run, their outcome and any rejected names.
  - Logs are kept for 180 days (`analytics:purge-assistant-log`).

## Consequences
- **Names are not detected.** The redactor removes identifiers it can recognise. A question that names a patient would still be sent once external processing is enabled, which is why consent is explicit and the acknowledgement says so.
- **Rule-based limits.** The built-in planner understands keywords, not open phrasing; unrecognised questions get the overview (sales, stock, expiry). The external planner understands more, but the answers are the same templated reports.
- **Live API not exercised in tests.** Tests use a stand-in planner. The SDK call follows the SDK's documented signature but has not been run against the live API here.
