# ADR-0009: POS payment integrity and sale completion

- Status: Accepted (2026-10-20)

## Context
Spec §11.3 says an electronic payment must never be treated as successful because the client says so; providers' callbacks must be verified and protected against duplicates and replay. §11.4 says a completed sale must atomically update stock, invoice and finance through the ERP services.

## Decision
- **Payments are separate from the sale.** Each POS payment has a status:
  - **cash** is confirmed at the till;
  - **standalone card/transfer terminals** (`manual_terminal`) stay `pending` until a different user with `pos.payments.confirm` confirms them against the terminal's settlement;
  - **integrated providers** stay `pending` until a provider webhook arrives whose signature (or documented status lookup) the adapter verifies, with matching amount and currency.
- **Webhooks:**
  - every event is stored once (`payment_provider_events`, unique on provider + event id), and duplicates are acknowledged with no effect;
  - events are applied under a row lock on the payment;
  - a mismatched amount fails the payment.
- **Completion** happens only when confirmed payments cover the total. In one transaction it:
  - consumes the FEFO reservations made at checkout and records the batch evidence;
  - posts cost of goods sold;
  - issues a sales invoice (`source = pos`) through `FulfilmentService`;
  - turns each confirmed payment into a finance receipt allocated to that invoice.
- **Retries:** client-generated `client_transaction_id` values are unique per organization, so a retried or offline-synchronized sale is never created twice. Phase 3 builds on this.
- **Development provider:** a `fake` provider with HMAC-signed webhooks exists for development and tests only. It cannot be resolved in production. Real adapters (Paystack, Flutterwave, Moniepoint and so on) implement `PaymentProvider` once credentials are available.

## Consequences
A sale paid by a pending electronic payment stays `awaiting_payment`, with its stock reserved, until confirmation, or until a supervisor cancels it. The POS UI must show this state clearly.
