# Regulatory review: pharmaceutical traceability in Nigeria

- **Reviewed:** 2026-10-26, for Phase 7 (spec §16, §31: "Applicable official regulatory requirements are reviewed and documented").
- **Reviewer:** the engineering team, as a design input. This is not legal advice. Compliance must be confirmed by the organization's regulatory affairs function and, where needed, with NAFDAC.

## Sources read

| Source | What it is | How it was read |
|---|---|---|
| **NAFDAC (Pharmaceutical Products (Traceability)) Regulations, 2024**, S.I. No. 49 of 2024, Federal Republic of Nigeria Official Gazette No. 224, Vol. 111, 30 October 2024 (commencement 16 October 2024) | The binding regulation | Full text of the gazette PDF on nafdac.gov.ng (`/wp-content/uploads/Files/Resources/Regulations/REGULATIONS_2021/NAFDAC-Pharmaceutical-Products-Traceability-Regulations-2024.pdf`) |
| NAFDAC traceability page, https://nafdac.gov.ng/traceability/ (content dated 11 April 2024) | Programme overview | Read. It states the aim of "full visibility over all products moving within the pharmaceutical supply chain", targets operation by the end of 2024, and refers to a 5-year implementation plan, the Nigeria National Traceability Strategy Policy (Federal Ministry of Health, May 2020) and the Africa Strategy for Pharmaceutical Traceability. It names no technical standard. |
| NAFDAC Track & Trace master-data portal, https://traceability.nafdac.gov.ng/master-data | Submission portal | Not readable as text: it is a web application. Its data formats and interfaces were **not** reviewed. |
| NAFDAC Guidance on Master Data Attributes for Pharmaceutical Products (DGO-GDL-001-00, effective 20 April 2022) | Guidance | Located through search but **not** read for this review. To read before implementing a master-data submission. |
| Draft Traceability of Pharmaceutical Products Regulations 2022 | Superseded draft | Not relied on. |

## Requirements and how the platform addresses them

| Reg. | Requirement (summary) | Platform | Status |
|---|---|---|---|
| 4 | Trade items carry a unique identifier assigned by the brand owner when the item is created. Co-packing gets a new identifier. | The platform records identifiers assigned by the brand owner (GTINs on products; serials registered per GTIN). It does not assign GTINs: those come from the brand owner's GS1 membership. | Supported (recording) |
| 5(1)–(3) | The identifier follows the GS1 General Specifications. Primary/secondary packs: GTIN, batch or lot, expiry and serial. Tertiary: GTIN, batch and expiry. GTIN + serial is unique. | GTIN check digits are validated. Serials follow the GS1 character set and length (≤ 20). GTIN + serial is unique across the whole platform, so duplicates are refused. Identifiers expose the GS1 element string (`(01)…(17)…(10)…(21)…`). | Supported |
| 5(7)–(10), 13 | Logistic units are identified by SSCC. Parent-child relationships between packaging levels are captured and aggregated up to the logistic unit. | SSCC identifiers (18 digits); aggregation of serials and units into a parent; revoking a parent revokes its children. | Supported |
| 6–7 | GS1 DataMatrix on primary/secondary packs; DataMatrix or GS1-128 on tertiary. **7(1): multiple two-dimensional barcodes on one pack for identification or authenticity verification are prohibited.** 7(2): an additional GS1 barcode for dispensing or point of sale is allowed. | The scanner reads GS1 DataMatrix content (raw with group separators, bracketed element strings, and GS1 Digital Link). **Platform QR codes issued by Nigerian organizations carry a notice not to print them on packs** (`traceability.single_2d_code_countries`); they are for documents and shelf or bin labels. On the pack, the DataMatrix is what gets scanned. The platform does not print labels or measure print quality. | Supported for scanning; printing is the manufacturer's process |
| 8–10 | Print quality and readability, placement, human-readable interpretation. | Out of scope: these are packaging-line controls. The GS1 element string is exposed for human-readable text. | Not applicable |
| 11(1) | Brand owners share product master data with NAFDAC. | The Product Master holds the data. **Submission to NAFDAC is not implemented**: the portal's interface is not published. | Gap |
| 11(2)–(4) | Stakeholders share legal, functional and location master data and obtain a GLN for the organization and its locations, which must hold a Pharmacy Council of Nigeria licence where the item is a medicine. | GLN fields on organizations and warehouses, check-digit validated, carried on traceability events and in the EPCIS export. Premises licences are recorded in organization verification (Phase 1), as declared. | Supported (data); submission is a gap |
| 12(1) | Brand owners share unique-identifier data with NAFDAC before the product is placed on the market. | Serial registration records the data; **transmission to NAFDAC is not implemented.** | Gap |
| 12(2)–(3) | Stakeholders electronically capture and share events when products are manufactured, received, distributed or dispensed, with date, time and time zone, location, source, destination, GSRN where available, business step and disposition. | Events are captured automatically from every stock movement (production, receipts, dispatches, transfers, dispensing, quarantine, release, recall) with source and destination organizations, locations and GLNs, and business and recording times. The **EPCIS 2.0 export** (`GET /trace/export/epcis`) maps them to CBV business steps and dispositions with the time-zone offset. GSRN is not captured. **Automatic sharing with NAFDAC is not implemented**; the export is what a stakeholder would submit. | Capture supported; sharing is a gap |
| 12(4)(a) | Keep a record of the linkage between products created, received, processed or dispatched. | Events link to the originating document (goods receipt, sales-order line, transfer, POS sale line) and to the counterparty's corroborating event. | Supported |
| 12(4)(b) | Keep records for at least one year after the product's expiry date. | Traceability events are append-only and never purged. Stock movements, documents and audit logs are kept. | Supported (retention policy: no deletion) |
| 12(4)(c) | Transmit records at the Agency's request. | Lot history, event export (EPCIS) and the platform-level view for compliance staff. | Supported (manual) |
| 12(4)(d), 12(7), 14 | Report identifier-data errors within 48 h, inability to scan within 48 h, products without the required identifier within 48 h, and stolen or lost products within 24 h. | Errors are corrected through correction and reversal events (history kept). **A regulatory incident log with these deadlines is planned for Phase 7b.** Reporting to NAFDAC itself is outside the platform. | Partly; due in 7b |
| 12(5) | The unique identifier appears in accompanying documents and messages. | Marketplace shipments carry batch numbers and expiries; webhook payloads carry GTIN and batch. Serial-level lists on delivery documents are **not** produced. | Partly |
| 12(6) | Data capture is done by scanning the data carrier. | Scan endpoints (public, member, dispense). Stock movements are still keyed by batch; scan-driven receiving is not yet wired into the receiving screens. | Partly |
| 15 | Products without traceability features may not be manufactured, imported, sold, distributed or used (with exemptions). | The platform does not block products that lack GTINs or serials, because legacy stock and exempted products exist. **A product-level check could flag them.** | Gap (policy decision) |
| 16–17 | Offences and penalties (individuals up to ₦800,000 and/or 1 year; bodies corporate up to ₦5,000,000; forfeiture). | Informational. | — |
| 19 | "Verification" means checking that the identifier on a pack corresponds to the one the manufacturer assigned. | Scans report whether a serial is **registered on the platform**, dispensed, revoked or recalled. Neither the scan nor the platform claims authenticity (spec §16.3). | Supported, with that limit stated |

## Design consequences adopted

1. **Event model:** chain-of-custody events are recorded from actual stock movements (ADR-0015), with business and recording times, locations, GLNs, counterparties and dispositions mappable to EPCIS CBV.
2. **No second 2D code on packs in Nigeria:** platform QR codes are positioned for documents and labels, and the DataMatrix is scanned on the pack.
3. **GS1 alignment:** GTIN, serial (≤ 20), SSCC, GLN and the element strings and Digital Link are read and produced as the GS1 General Specifications describe. The platform does not issue GS1 keys.
4. **Retention:** traceability events are never deleted.

## Open items for compliance owners

- **Submission interfaces:** confirm the NAFDAC submission interfaces (master data, unique-identifier data, events), their format, frequency and the accreditation of solution providers, then build the connector. The integration gateway's outbound side (Phase 5) is the intended path.
- **Master data attributes:** read DGO-GDL-001-00 (master data attributes) and map it to the Product Master.
- **Transition dates:** confirm them for each obligation and actor, since the regulation's commencement date does not say whether obligations phase in.
- **Untraceable products:** decide whether to block products without GTINs or serials, or only flag them (reg. 15).
- **GSRN:** decide whether GSRN capture for persons handling products is expected in practice.
