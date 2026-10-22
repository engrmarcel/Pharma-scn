# ADR-0021: Countries are configuration, activated only when their checklist passes

- Status: Accepted (2026-10-22)

## Context
Spec §20 asks for Nigeria first, with expansion to at least five African countries. Currency, time zone, locale, formats, tax, invoicing, regulatory identifiers, product classification, payment providers, verification requirements, documents, delivery, language and addresses must all be country-aware. No Nigerian rule may be hard-coded into shared logic, and no compliance may be claimed until reviewed.

Phase 9 acceptance: adding a country does not require hard-coding country-specific assumptions throughout the application.

Before this phase, countries already had a currency, a time zone, a locale and a settings field. Tax rates were already effective-dated per country, and regulators were listed per country. Some rules were still in code:
- the NAFDAC incident reporting deadlines;
- the list of countries that allow only one 2D code on a pack;
- a fallback to the naira;
- NAFDAC as the default authority in an integration template.

## Decision
- **One country profile.** `CountryProfile` reads a country's settings over documented defaults. Business code reads countries only through it, and no country code appears in business logic. The profile holds:
  - formats (date, first day of week);
  - phone rules (national number pattern, trunk prefix);
  - address rules (region label, fields required for verification, postal code pattern);
  - tax identity and conventions (default category, registration label and pattern, prices including tax);
  - the business registration label and pattern;
  - verification requirements: documents, plus documents and regulatory identifiers per organization type;
  - regulatory settings: product registration authority, incident reporting deadlines per type, single 2D code on packs;
  - payment providers enabled in the country;
  - the data residency region;
  - reviews (tax, regulatory, legal), each with a status and a reference.
- **The hard-coded rules moved into the profile.**
  - Incident deadlines come from `regulatory.incident_deadline_hours`; a country without them records incidents without a due date.
  - The 2D-code notice comes from `regulatory.single_2d_code_on_packs`.
  - Opportunities fall back to the organization country's currency.
  - Mapping templates use the placeholder `{country.product_registration_authority}`, filled from the profile when applied.
- **Regulatory identifier types are data.** `regulatory_identifier_types` lists, per authority, each identifier type, what it identifies (product, organization, manufacturer), its name, and an optional format pattern with an example. Identifiers are accepted only for configured types that apply to the subject and match the format. Formats stay open until confirmed against the regulator.
- **Country-specific onboarding.**
  - `OnboardingRequirements` lists what the organization's country and type require: documents, identifiers (for example a Nigerian pharmacy's PCN premises licence), and address fields with the country's labels.
  - Organizations add their own identifiers, only with their own country's regulators. Submission for verification enforces the list.
  - Registration and profile updates validate phone, tax and registration numbers against the country's patterns.
- **Activation is checked.** Platform staff with `platform.countries.manage` edit the profile, add tax rates and add identifier types; all changes are validated and audited. `readiness` reports:
  - blocking checks: currency (with minor units in `config/currencies.php`), time zone, locale, tax rates in force and the default category, regulator, product registration identifier types, configured identifiers for any requirement, verification documents, and completed tax, regulatory and legal reviews with references;
  - warnings: incident deadlines, electronic payments, phone validation.

  Activation is refused until no blocking check fails. The older endpoint that could switch a country on directly was removed. A country's currency cannot change once organizations exist there.
- **Nigeria.** Nigeria stays active (launch country). Its profile carries the traceability rules reviewed in Phase 7 and its pharmacy requirements. Its checklist openly shows the tax and legal reviews as pending and the regulatory review as partial.
- **Payment providers per country.** Each provider declares the countries and currencies it supports technically, and the country profile enables it there. The development provider is available everywhere outside production; others must be enabled per country.

## Consequences
- Adding Ghana, for example, is profile settings, tax rates, identifier types and reviews through the API, then activation. `CountryConfigurationTest` does exactly this.
- The other seeded countries (Ghana, Kenya, South Africa, Rwanda, Egypt) carry only directory data: currency, time zone, locale, regulators and region labels. Their tax rates, formats and reviews are left for platform staff and must be confirmed before activation.
- Currency rounding, local dates and times, country-aware reports and regional deployment follow in 9b and 9c.
