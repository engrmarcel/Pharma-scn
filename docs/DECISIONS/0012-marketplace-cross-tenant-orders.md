# ADR-0012: Marketplace records shared between two organizations, and the ERP link

- Status: Accepted (2026-10-23)

## Context
Spec §14 asks for B2B ordering between organizations on the platform. Phase 4 also requires that "orders connect to the ERP transaction model", that restricted products and customers are controlled, and that records are auditable. Until now every business record belonged to exactly one tenant (ADR-0003).

## Decision
- **Shared records carry both parties.** Relationships, RFQs, quotations and marketplace orders store `buyer_organization_id` and `supplier_organization_id`. They are not tenant-scoped; every query goes through `involving(org)` or `visibleTo(org)`. Anything else returns 404, so the records of uninvolved organizations are never disclosed. Suppliers do not see which other suppliers an RFQ invited.
- **Each side creates its own ERP records, in its own tenant, as its own users:**
  - the buyer creates its Supplier party when it requests a relationship, and its purchase order when it submits an order;
  - the supplier creates its Customer party when it approves the relationship, and its sales order when it accepts.

  Each side sees only its own ERP document on the shared order.
- **One approval workflow.** The buyer's procurement policy (approval threshold, segregation of duties) decides whether the marketplace order needs internal approval. The marketplace approval is the purchase-order approval, so there is no second workflow to keep in step.
- **Agreed prices.** Listing or quotation prices are carried to both ERP documents. The supplier's sales order honours them as quoted prices (no price-override permission needed), and its ERP adds tax.
- **Cross-tenant writes, limited to consequences.** When the supplier rejects or cancels an order, the buyer's open purchase order must not stay open. That one consequence is applied in the buyer's tenant (`TenantContext::runAs`) and recorded in the buyer's audit trail with the reason prefixed `[via marketplace]`. No other cross-tenant write happens.
- **Controls run at every step, not just at discovery.** Participation, the trading matrix and restricted-product rules (`MarketplaceAccess`) are re-checked when inviting, quoting, ordering and accepting, because verification status can change in between.

## Consequences
- The ERP stays the system of record on both sides. Reporting, stock reservations and books need no marketplace special cases.
- Both organizations must use the procurement and sales modules for marketplace orders to reach their ERPs; the marketplace module alone is not enough.
- Fulfilment, receipts, invoices and disputes (4c) follow the same rule: each side posts its own documents.
