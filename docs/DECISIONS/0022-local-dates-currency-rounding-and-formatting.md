# ADR-0022: Local business dates, currency rounding and locale formatting

- Status: Accepted (2026-10-22)

## Context
Spec §20 lists currency, time zone, locale, number and date formatting and tax rules as country-aware. Before this phase:
- "Today" was the UTC date everywhere: document dates, expiry checks, due dates and report periods.
- Line amounts kept four decimal places whatever the currency. A Rwandan franc invoice could carry fractions of a franc.
- Prices were always treated as excluding tax.
- The receipt printed "TIN" and UTC times.

## Decision
- **Business dates.**
  - `BusinessCalendar` gives the local date where the organization operates: its own time zone setting, else its country's.
  - `business_today()` replaces `today()` in application code (95 places); timestamps stay UTC.
  - Report periods use `dayStart` and `dayEnd`, the UTC instants that bound a local day, for timestamp columns, and the local date strings for date columns. POS hour-of-day figures are shown in local hours.
  - Outside an organization (platform work) the application time zone applies.
  - `BUSINESS_TIMEZONE` forces one zone; the test suite sets UTC for determinism, and `LocalizationTest` releases it to check Lagos around midnight UTC.
- **Currency rounding.**
  - `config/currencies.php` gives each currency's minor units, and `Money::forCurrency` rounds half-up to them.
  - Sales and POS line amounts, credit note shares, and purchase order and supplier invoice lines are rounded per line to the document currency, so header totals are sums of rounded lines.
  - Unit prices keep four decimal places.
- **Tax-inclusive prices.**
  - Where the country profile sets `tax.prices_include_tax`, unit prices and discounts include tax.
  - The stored subtotal and discount are the ex-tax shares, and tax is the remainder, so subtotal − discount + tax = total = the price paid. Revenue and tax therefore post exactly as before.
- **Formatting.** `Localizer` formats for people: money with the currency's decimals and the locale's separators and symbol (ICU), dates in the country's format or the locale's, and timestamps in local time.
  - The POS receipt uses it, together with the country's label for the tax number.
  - The API keeps returning raw values; `GET /organization` adds a `locale` block for clients.
- **Organization overrides.** `PATCH /organization {timezone, locale}`, for organizations operating outside their country's default zone or language.

## Consequences
- **Earlier documents.** Amounts are not rewritten when the rounding rule changes. New documents are rounded; earlier ones keep four places.
- **Daily demand facts.** These are still grouped by UTC date (at most a few hours' difference in African time zones). The analytical store can move to local days if that proves material.
- **Not done.** Cash rounding (no coins for small amounts) and translations of interface text are not built.
