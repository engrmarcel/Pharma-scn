# ADR-0008: Operational double-entry ledger posted in the document transaction

- Status: Accepted (2026-10-19)

## Context
Spec §10.6 asks for auditable finance foundations with balanced double entry, and §11.4 for sales and inventory to be posted atomically. External accounting systems may remain the statutory book of record for many customers.

## Decision
- There is one journal (`journal_entries` / `journal_lines`) per organization. It is written only by `GeneralLedger`, entries are immutable, corrections are reversals, and closed periods are enforced.
- Operational documents post through `AccountingBridge` **inside the same database transaction**, so a document and its journal entry commit or fail together. If the period is closed, the document is refused.
- Inventory is valued at batch cost (specific identification), and the receipt and invoice use identical per-base-unit cost arithmetic so GRNI clears exactly. Rounding residue lands in purchase price variance.
- The ledger is maintained regardless of the `finance` module, so enabling the module later shows correct balances.
- The default chart is created on first use and identified by `system_key`. Country charts can be swapped in without code changes.

## Consequences
- These are management books, **not certified statutory accounts**. Export to the customer's statutory system goes through the integration gateway (Phase 5).
- Tax is recorded as input and output tax balances only; filing and returns are out of scope.
