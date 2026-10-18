# MASTER CLAUDE CODE PROMPT
## Pharmaceutical Supply Chain Network Platform
### Interoperable ERP + POS + Mobile Field Force + B2B Marketplace + Traceability + Enterprise Integrations

**Version:** 1.0 — Master Build Specification  
**Product name:** Configurable working name; final branding is pending  
**Initial market:** Nigeria, with architecture for expansion across Africa  
**Product type:** Multi-tenant pharmaceutical SaaS, enterprise integration platform and digital supply-chain network  
**Implementation approach:** Phased, test-driven, API-first, security-focused and production-minded

---

# 1. YOUR ROLE AND MISSION

Act as a principal software architect, senior Laravel engineer, database architect, mobile application architect, enterprise integration engineer, DevOps engineer, cybersecurity specialist, pharmaceutical supply-chain domain analyst, QA engineer and technical project manager.

Your responsibility is to plan, design, implement, test, document and prepare for deployment a comprehensive Pharmaceutical Supply Chain Network Platform.

Do not build a basic CRUD application, a collection of attractive but non-functional dashboards, or a simple pharmaceutical marketplace.

Build a connected platform that enables pharmaceutical manufacturers, importers, distributors, wholesalers, pharmacies, hospitals, healthcare facilities, logistics providers and other authorized supply-chain participants to operate their businesses, exchange authorized data, conduct transactions and trace pharmaceutical products across the supply chain.

The platform must support three participation models:

1. **Powered by our ERP:** Organizations use our pharmaceutical ERP to operate their businesses.
2. **Connected enterprise:** Organizations retain SAP, Oracle, Microsoft Dynamics or another existing ERP and connect to our platform through an integration gateway.
3. **Lightweight network participant:** Smaller organizations use the web application, mobile application, POS or APIs to participate without adopting a full ERP.

The core business principle is:

**Operate with our ERP. Connect through integrations. Transact through the network. Track products throughout the supply chain.**

The platform must be commercially viable, secure, extensible, multi-tenant and capable of scaling without requiring an unnecessary rewrite when transaction volume increases.

You must implement the system in clearly defined phases. Each phase must produce working software, database migrations, tests, documentation and a report of what has actually been completed.

Do not attempt to implement every phase in a single unverified code-generation pass.

---

# 2. NON-NEGOTIABLE DEVELOPMENT RULES

Follow these rules throughout the project.

## 2.1 Inspect before implementing

Before modifying the project:

- Inspect the current directory and repository structure.
- Identify existing applications, frameworks, dependencies, environment files, database configuration and tests.
- Determine whether this is a new project or an existing project.
- Preserve existing working code unless a justified migration is necessary.
- Identify missing tools and dependencies.
- Create an implementation plan based on the actual environment.
- Do not claim that a command, test, integration or deployment succeeded unless it was actually executed and verified.

If the project is empty, initialize the recommended architecture.

If the project already exists, adapt the plan to its actual state.

## 2.2 Work incrementally

For each implementation phase:

1. Explain the objective and dependencies.
2. Define the architecture and data model.
3. Implement migrations and backend logic.
4. Implement interfaces and API endpoints.
5. Add authorization and validation.
6. Implement automated tests.
7. Run available tests, linters and build checks.
8. Fix failures before proceeding.
9. Update documentation and progress reports.
10. Summarize completed, incomplete and blocked items.

Do not mark a feature complete because its interface exists. It is complete only when its business logic, persistence, permissions, error handling and relevant tests work.

## 2.3 Avoid destructive actions

Never delete existing databases, production data, environment files, migrations or user files without explicit authorization.

Do not run destructive production migrations or reset commands automatically.

Use safe, reversible migrations where possible.

## 2.4 Maintain a single source of truth

Maintain these documents:

- `PROJECT_MASTER_SPEC.md`
- `docs/PRODUCT_REQUIREMENTS.md`
- `docs/SYSTEM_ARCHITECTURE.md`
- `docs/DATABASE_DESIGN.md`
- `docs/API_SPECIFICATION.md`
- `docs/SECURITY_AND_PRIVACY.md`
- `docs/OFFLINE_FIRST_DESIGN.md`
- `docs/ENTERPRISE_INTEGRATIONS.md`
- `docs/TRACEABILITY_DESIGN.md`
- `docs/DEPLOYMENT_GUIDE.md`
- `docs/TESTING_STRATEGY.md`
- `docs/IMPLEMENTATION_ROADMAP.md`
- `docs/DECISIONS/` for significant architecture decisions
- `IMPLEMENTATION_STATUS.md` for actual implementation progress
- `CHANGELOG.md` for significant changes

Update these documents whenever implementation changes the agreed design.

---

# 3. PRODUCT IDENTITY AND BRAND CONFIGURATION

The final product name has not been permanently established.

Do not permanently hard-code a brand name into the application, source code, database schema, mobile application, email templates, receipts or API responses.

Create configurable platform branding supporting:

- Product display name
- Logo
- Favicon
- Primary and secondary colors
- Email sender identity
- Support email and phone
- Website URL
- Organization-specific branding where permitted
- Country-specific contact information
- Environment-specific branding

Use a neutral internal code identifier, such as `pharma_network`, only where a stable technical identifier is required.

Provide an administrator-controlled branding configuration and a safe default theme.

---

# 4. PRODUCT OBJECTIVES

The platform must accomplish six major objectives.

## 4.1 OPERATE

Provide pharmaceutical ERP, retail POS, inventory, purchasing, sales, finance, warehouse management and mobile field-force tools.

## 4.2 CONNECT

Integrate with external enterprise systems such as SAP, Oracle, Microsoft Dynamics and other ERP, warehouse, manufacturing and distribution systems.

## 4.3 TRANSACT

Support B2B product discovery, supplier connections, quotations, procurement, orders, fulfilment and approved payment workflows.

## 4.4 TRACK

Support product identity, regulatory information, batch and expiry tracking, QR-code association, serialized product identifiers where applicable, shipment events, custody transfers, recalls and traceability history.

## 4.5 OPERATE OFFLINE

Allow pharmacies and field representatives to continue supported operations during internet outages, store transactions safely on devices and synchronize them when connectivity returns.

## 4.6 PROVIDE INTELLIGENCE

Deliver business analytics, demand forecasting, stock and expiry intelligence, procurement recommendations and AI-assisted reporting.

---

# 5. USERS, ORGANIZATIONS AND ACCESS CONTROL

Implement multi-tenant organization management from the beginning.

## 5.1 Organization types

Support at least:

- Pharmaceutical manufacturer
- Importer
- National distributor
- Regional distributor
- Wholesaler
- Pharmacy
- Pharmacy chain
- Hospital
- Healthcare facility
- Medical or institutional procurement organization
- Logistics provider
- Cold-chain logistics provider
- Authorized service provider
- Individual consumer, when the consumer-facing phase is enabled

Each organization must have a configurable profile, type, country, operating locations, contact details, verification status and enabled modules.

## 5.2 User roles

Provide configurable roles, including:

- Platform super administrator
- Platform operations administrator
- Compliance/verification officer
- Organization owner
- Organization administrator
- Finance manager
- Accountant
- Inventory manager
- Warehouse officer
- Procurement officer
- Sales manager
- Sales representative
- Pharmacist or authorized dispensary user
- POS cashier
- POS supervisor
- Hospital procurement officer
- Logistics coordinator
- Driver/delivery officer
- Integration administrator
- Auditor/read-only user

Implement role-based access control and, where appropriate, attribute- and organization-scoped permissions.

Do not rely on hidden buttons for security.

Enforce permissions on the server, in APIs, background jobs and export functions.

## 5.3 Multi-tenancy

Use a shared application architecture with strong tenant isolation initially.

Every tenant-owned record must be scoped to its organization or authorized tenant context.

Implement:

- Tenant identification and resolution
- Organization membership
- Branch-level access
- Warehouse-level access
- Tenant-scoped queries
- Tenant-aware background jobs
- Tenant-scoped file access
- Tenant-aware audit logs
- Tenant-specific settings and integrations
- Automated cross-tenant isolation tests

Never trust a tenant ID supplied by the client without authorization checks.

Platform-wide shared data, such as the canonical product catalogue, must have explicit ownership and editing rules separate from tenant-owned data.

Document a future migration path to stronger database isolation for large enterprise customers when justified.

---

# 6. RECOMMENDED TECHNICAL STACK

Use a modular monolith first, with clearly defined module boundaries and asynchronous processing.

Do not begin with dozens of microservices.

## 6.1 Core backend

- PHP with a currently supported, stable Laravel release
- Laravel routing, validation, policies, jobs, events, notifications and migrations
- Laravel Sanctum for appropriate API authentication
- MySQL 8.x or a currently supported compatible MySQL release
- Redis for caching, rate limiting and queues where supported
- Laravel queue workers for asynchronous processing
- Tailwind CSS for the interface
- Livewire for server-driven administrative and ERP interfaces
- Blade components for reusable UI elements

Verify compatibility among framework, PHP, database and package versions before installation.

## 6.2 Mobile applications

Use Flutter for Android and iOS mobile applications where the project requirements justify a shared codebase.

Prioritize Android for the initial Nigerian field-force and pharmacy deployment, while preserving the iOS architecture.

Use:

- A supported local relational database such as SQLite
- Platform-appropriate secure storage for credentials and encryption keys
- An API client with retry and timeout handling
- A durable local synchronization queue
- Background synchronization where the operating system permits it
- Device and application version management

Select and document maintained packages compatible with the chosen Flutter release.

## 6.3 Web and API clients

The initial ERP, POS web interface and platform administration interface should use the selected Laravel/Livewire stack.

Expose versioned REST APIs for Flutter applications, enterprise integrations and future clients.

Do not duplicate core business rules across the web application, POS and mobile applications.

## 6.4 Infrastructure

Design for:

- Linux-based production hosting
- Reverse proxy/load balancer
- Multiple stateless application instances
- Managed or properly operated MySQL
- Redis
- Separate queue workers
- Object storage for documents and images
- Centralized logs
- Monitoring and alerting
- Encrypted backups
- CI/CD
- Secrets management

Local XAMPP development may be supported where convenient, but it must not dictate the production architecture.

A shared hosting environment may be suitable for a prototype, but the target enterprise deployment must support background workers, reliable queues, secure storage and independent scaling.

## 6.5 Technology evolution

Keep Laravel and MySQL as the initial transactional core.

Prepare documented extension points for:

- Event streaming through Kafka or Redpanda when justified
- OpenSearch or another dedicated search engine when justified
- ClickHouse or a data warehouse for analytics at scale
- Regional infrastructure
- Specialized services where measured bottlenecks warrant decomposition

Do not add these systems simply to make the architecture look sophisticated.

---

# 7. HIGH-LEVEL ARCHITECTURE

Implement the following logical architecture.

```text
USERS AND ORGANIZATIONS
        |
        +-- ERP WEB APPLICATION
        +-- POS INTERFACE
        +-- MOBILE FIELD-FORCE APP
        +-- PHARMACY MOBILE/POS CLIENT
        +-- ENTERPRISE ERP CONNECTORS
        +-- PARTNER API CLIENTS
        |
        v
VERSIONED API AND APPLICATION LAYER
        |
        +-- Identity / Organizations / Permissions
        +-- Pharmaceutical Product Master
        +-- ERP / Sales / Procurement / Finance
        +-- POS / Cashier / Shift Management
        +-- Inventory / Warehouse / Batch
        +-- Field Force / Light CRM
        +-- B2B Marketplace / RFQ / Orders
        +-- Traceability / Recall
        +-- Logistics / Delivery
        +-- Enterprise Integration Gateway
        +-- Offline Synchronization Services
        +-- Billing / Subscriptions
        +-- Analytics / AI
        |
        v
BUSINESS EVENTS AND ASYNCHRONOUS PROCESSING
        |
        +-- Notifications
        +-- Integration delivery
        +-- Traceability projections
        +-- Analytics pipelines
        +-- Audit and monitoring
        |
        v
DATA LAYER
        +-- MySQL transactional data
        +-- Redis cache and queues
        +-- Object/document storage
        +-- Local mobile databases
        +-- Future analytical/search stores
```

The diagram represents logical responsibilities, not a requirement to deploy every component as a separate service.

---

# 8. PLATFORM FOUNDATION

Implement the following foundational capabilities.

## 8.1 Authentication

- Secure login and logout
- Password reset
- Email verification where appropriate
- Optional or policy-enforced multi-factor authentication
- Session management
- Device/session revocation
- Login throttling
- Secure API authentication
- Organization invitations
- Account suspension and reactivation
- Optional CAPTCHA integration for public-facing authentication flows

## 8.2 Organization onboarding

Create an onboarding workflow:

1. Register organization.
2. Select organization type and country.
3. Provide organization identity and contact details.
4. Upload relevant documents.
5. Submit for verification where required.
6. Review and approve, reject or request corrections.
7. Assign initial administrator.
8. Enable eligible modules.
9. Configure branches, warehouses and users.

Verification status must be explicit and auditable.

Do not automatically label an organization, product or document as regulator-approved merely because information has been submitted.

## 8.3 Platform administration

Build a functional administrative interface for:

- Organizations
- Users
- Organization verification
- Product catalogue governance
- Subscription plans
- Feature entitlements
- Country configurations
- Integration monitoring
- Failed jobs
- Offline synchronization monitoring
- Audit events
- Platform settings
- Support and incident management

Require additional protection for privileged operations.

---

# 9. PHARMACEUTICAL PRODUCT MASTER

The Product Master is a foundational platform service and must be designed before ERP inventory, POS, procurement and traceability modules depend on it.

## 9.1 Product identity

Include:

- Internal canonical product ID
- Internal SKU
- Generic name
- Brand/trade name
- Active ingredient(s)
- Strength and strength unit
- Dosage form
- Route of administration where applicable
- Pack size
- Unit of measure
- Manufacturer
- Marketing authorization holder where applicable
- Country of origin
- Product category
- Product description
- Product images
- Storage requirements
- Temperature range where applicable
- Prescription classification where applicable
- Status: active, inactive, discontinued or under review
- Product identifiers such as GTIN where available
- Product-to-package hierarchy

Support multiple package levels, for example:

```text
Individual unit
    -> Blister or inner pack
        -> Retail pack
            -> Carton
                -> Shipping case
```

The exact hierarchy varies by product.

Maintain explicit conversion factors between units. Do not assume all medicines use the same unit structure.

## 9.2 NAFDAC and regulatory identity

Include structured fields for:

- **NAFDAC Registration Number (NAFDAC No.)**
- Regulatory jurisdiction
- Registration/authorization status
- Applicable registration dates
- Expiry or review dates where applicable
- Marketing authorization holder
- Manufacturer regulatory identifiers
- Importer details where applicable
- Regulatory document references
- Verification source
- Verification date
- Verification officer or process
- Last verification result
- Regulatory history

A product may have multiple regulatory identifiers across jurisdictions.

Design a jurisdiction-aware regulatory identifiers table rather than embedding every country's regulatory fields into one universal column.

For Nigerian products, NAFDAC registration information must be represented explicitly.

Do not assume that the existence of a NAFDAC number proves current validity. Store verification evidence and status separately.

Do not invent registration numbers or treat test data as genuine regulatory data.

## 9.3 Product ownership and governance

Distinguish between:

- The canonical product record
- Tenant-specific SKU
- Tenant-specific purchase cost
- Tenant-specific selling price
- Tenant-specific tax treatment
- Tenant-specific preferred supplier
- Tenant-specific reorder settings

Only authorized users may propose or approve changes to shared product records.

Keep an audit trail for material changes.

## 9.4 Product Master database design

Design normalized tables for product identities, ingredients, manufacturers, brands, pack levels, regulatory identifiers, documents, product identifiers, tenant mappings and product audit history.

Use foreign keys, indexes, unique constraints and validation rules appropriately.

Document which fields are globally shared and which are organization-specific.

---

# 10. ERP MODULE

Build a modular pharmaceutical ERP.

## 10.1 Organization and branch management

- Multiple branches
- Warehouses
- Retail outlets
- Departments
- Cost centres where needed
- Branch-level permissions
- Inter-branch stock transfers
- Branch-specific price lists
- Branch-specific stock levels

## 10.2 Inventory and warehouse

- Goods receipt
- Supplier delivery receipt
- Stock adjustments
- Stock transfers
- Stock reservation
- Available, reserved, quarantined and damaged stock
- Reorder levels
- Purchase suggestions
- Cycle counts
- Stocktaking
- Warehouse/bin locations
- Inventory valuation
- Stock movement history
- Low-stock alerts
- Inventory reconciliation

All stock-changing operations must be transactional and auditable.

Use an inventory movement ledger as the authoritative record of stock movements, with appropriately maintained balances or projections.

Prevent negative stock by default unless an authorized policy explicitly allows it.

## 10.3 Batch and expiry

- Batch/lot number
- Manufacturer
- Manufacturing date
- Expiry date
- Quantity received
- Current quantity
- Warehouse/location
- Supplier/source
- Quality status
- Quarantine status
- Recall status
- Batch cost
- Receipt and movement history

Implement configurable FEFO (First Expiry, First Out) allocation.

Prevent expired, recalled or quarantined stock from being sold through normal workflows.

Record authorized overrides as audited exceptions where legally and operationally permissible.

## 10.4 Procurement

- Suppliers
- Purchase requisitions
- Approval workflows
- Purchase orders
- Goods received notes
- Supplier invoices
- Supplier returns
- Outstanding orders
- Supplier price lists
- Lead times
- Minimum order quantities
- Purchase history
- Supplier performance

## 10.5 Sales and invoicing

- Customers
- Quotations
- Sales orders
- Sales invoices
- Credit notes
- Returns
- Discounts
- Taxes
- Payment records
- Receivables
- Customer statements
- Approval limits
- Sales history

Implement idempotent operations where repeated requests could create duplicate orders, invoices or payments.

## 10.6 Finance foundations

Implement auditable financial records and configurable accounting foundations:

- Chart of accounts
- Journal entries
- Accounts receivable
- Accounts payable
- Payment allocation
- Cashbook
- Financial periods
- Financial reports

Use balanced double-entry accounting if a general ledger is implemented.

Do not claim the system is certified for statutory accounting or tax compliance without appropriate validation.

---

# 11. PHASE 2 POS MODULE — MANDATORY

The POS module must be a first-class ERP module in Phase 2.

It must not be an isolated point-of-sale application with a separate inventory database.

## 11.1 POS interface

Build a usable cashier interface optimized for keyboard, mouse, touchscreen and barcode scanners.

Include:

- Fast product search
- Barcode scanning
- Cart management
- Quantity adjustments
- Customer selection
- Customer lookup
- Authorized price and discount handling
- Batch selection
- FEFO stock allocation
- Stock availability
- Tax calculation
- Multiple payment methods
- Split payments
- Cash payment recording
- Receipt/invoice generation
- Returns and refunds
- Held/suspended sales
- Sale cancellation with permission checks
- Cashier identity
- Branch and terminal identification
- Transaction history

Prescription-related workflows must be configurable for the relevant jurisdiction and product classification. Do not implement unlawful dispensing or bypass required professional controls.

## 11.2 Cashier and shift management

Implement:

- POS terminal registration
- Cashier assignment
- Shift opening
- Opening cash balance
- Cash sales
- Cash in/out with reasons and permissions
- Shift closing
- Expected cash calculation
- Actual cash count
- Variance calculation
- Supervisor review
- Cashier and shift audit history

## 11.3 Payment integrity

Separate payment initiation, payment confirmation, payment allocation, refund and reconciliation.

Support configured payment providers through adapters.

Never mark an electronic payment as successful solely because the client says it succeeded.

Verify provider callbacks or transaction status using the provider's documented mechanism.

Protect against duplicate payment callbacks, replay attacks and duplicate payment allocation.

## 11.4 POS-to-ERP integration

Use the same underlying ERP business services for:

- Sales
- Inventory
- Batch allocation
- Invoicing
- Customer accounts
- Payments
- Returns
- Financial posting
- Audit events

The intended flow is:

```text
POS Interface
    -> POS Application Service
        -> ERP Sales Engine
            -> Batch and Inventory Engine
            -> Invoice and Payment Services
            -> Financial Posting
            -> Audit/Event Layer
            -> Analytics
```

A completed sale must atomically persist the sale and its associated inventory movements where appropriate.

Do not allow an invoice to claim that stock was deducted if the inventory transaction failed.

## 11.5 POS reporting

Include:

- Daily sales
- Sales by product
- Sales by category
- Sales by cashier
- Sales by branch
- Sales by payment method
- Returns and refunds
- Gross margin where cost data is reliable
- Shift reconciliation
- Cash variance
- Batch and expiry sales reporting

---

# 12. PHASE 2 MOBILE FIELD-FORCE APP — LIGHT CRM

Build an Android-first mobile application for pharmaceutical sales representatives, with a documented path to iOS.

The application must connect to the central ERP and CRM services through versioned APIs.

## 12.1 Customer and pharmacy directory

Include authorized profiles for:

- Pharmacies
- Hospitals
- Healthcare facilities
- Wholesalers and distributors
- Doctors and pharmacists where appropriate

Support:

- Customer name
- Organization type
- Address
- Contact details
- Assigned representative
- Territory
- Account status
- Authorized customer identifiers
- GPS coordinates
- Location verification history
- Visit history
- Order history
- Follow-up tasks

Apply appropriate privacy controls to personal contact information.

## 12.2 GPS check-in and check-out

Provide event-based GPS verification:

1. Representative selects an assigned customer.
2. Representative initiates check-in.
3. The app captures location permission status, coordinates where available, accuracy, timestamp and device context.
4. The representative records visit activities.
5. The representative checks out.
6. The visit is stored locally when offline and synchronized later.

Do not implement continuous background location tracking by default.

GPS data must be collected transparently, with appropriate notice, permissions, retention controls and access restrictions.

If GPS is unavailable or inaccurate, record the condition rather than inventing coordinates or falsely claiming a verified visit.

## 12.3 Visit logging

Include:

- Visit objectives
- Visit type
- Discussion notes
- Products discussed
- Customer feedback
- Competitor information where authorized
- Order opportunity
- Visit outcome
- Next action
- Follow-up date
- Supporting attachments where permitted
- Check-in and check-out timestamps
- Synchronization status

## 12.4 Mobile order capture

Representatives must be able to:

- Search the authorized product catalogue
- View applicable customer-specific prices
- Check cached stock information with its last-updated timestamp
- Create draft orders
- Add products and quantities
- Capture customer purchase requests
- Submit orders for validation
- View order status
- View order history
- Record follow-up actions

Clearly distinguish a captured offline order from an order accepted by the central ERP.

An offline order must not be represented as confirmed stock allocation until the server has validated it.

## 12.5 Supervisor features

Provide:

- Assigned territories
- Customer assignments
- Visit completion summaries
- Order capture summaries
- Follow-up compliance
- Representative activity reports
- Authorized team visibility

Do not expose one representative's restricted customer information to another representative without authorization.

---

# 13. PHASE 3 OFFLINE-FIRST COMMERCE

The architecture for Phase 2 POS and mobile field-force features must anticipate offline operation. Phase 3 implements the robust synchronization engine.

Offline operation is a core requirement, not a cosmetic feature.

## 13.1 Offline POS

Support configured operations during extended connectivity loss, including:

- Local product lookup
- Cached customer information
- Local sales capture
- Local receipts
- Offline visit or transaction history
- Offline order capture where permitted
- Durable transaction queue
- Synchronization status
- Recovery after application restart
- Retry after connectivity restoration

## 13.2 Local data storage

Use a local relational database for durable offline records.

Protect sensitive local data using appropriate platform security and encryption controls.

Do not store passwords, payment secrets or long-lived privileged credentials in plaintext.

Define the minimum data that should be cached on each device.

Support remote session revocation and device deauthorization when connectivity resumes.

## 13.3 Synchronization protocol

Implement a documented synchronization contract with:

- Unique client-generated transaction IDs
- Idempotency keys
- Device identifiers
- Tenant and user identity
- Client creation timestamps
- Server receipt timestamps
- Payload schema versions
- Sync attempts
- Last successful synchronization
- Error codes
- Retry strategy
- Conflict states
- Acknowledgement from the server

Suggested states:

```text
LOCAL_DRAFT
    -> PENDING_SYNC
        -> SYNCING
            -> SYNCED
            -> RETRY_REQUIRED
            -> CONFLICT
            -> REJECTED
                -> REVIEW / CORRECTION
```

Never silently discard a locally committed sale or order.

## 13.4 Inventory conflicts

Inventory cached on a device may become stale.

Implement explicit server-side validation and reconciliation.

Use database transactions, row locking or suitable concurrency controls when allocating shared inventory.

Define what happens when two terminals attempt to sell the last available units.

Support device-level transaction identifiers and reconciliation reports.

Do not allow blind last-write-wins overwrites of inventory balances.

## 13.5 Offline payments

Distinguish clearly between:

- Confirmed payment
- Offline cash recorded
- Payment pending
- Payment failed
- Refund pending
- Refund confirmed

Do not claim that a card, transfer or mobile-money payment was completed offline unless the relevant payment provider has explicitly confirmed a supported offline payment mechanism.

Offline cash recording does not itself prove that the cash was reconciled.

## 13.6 Field-force offline support

Cache only the information required for assigned work:

- Assigned customer directory
- Authorized product catalogue
- Applicable price lists
- Visit forms
- Draft orders
- Reference data
- Pending transactions

Record GPS check-ins and visit activities locally.

Automatically synchronize when connectivity returns and the device is allowed to communicate.

Use exponential backoff or another documented retry strategy.

Show pending counts, last-sync time and failed items.

## 13.7 Power resilience

Document device and infrastructure requirements for six-hour power/internet outages, including battery capacity, backup power, local network equipment and charging procedures.

The application must recover from forced shutdowns and device restarts without losing locally committed transactions.

Do not claim unlimited offline capability: explain cache limits, device-storage constraints and unsupported online-only operations.

---

# 14. B2B MARKETPLACE AND PROCUREMENT NETWORK

Implement this after the core ERP/POS and offline foundations.

## 14.1 Supplier discovery

Support discovery of verified or appropriately labelled organizations by:

- Product
- Brand
- Generic ingredient
- Strength
- Dosage form
- Pack size
- Supplier
- Country
- Location
- Availability information
- Delivery options
- Organization type

Do not display stale inventory as guaranteed live availability.

Show the timestamp or status of inventory information where appropriate.

## 14.2 B2B transactions

Implement:

- Supplier product listings
- Buyer enquiries
- Requests for quotation
- Supplier quotations
- Quote comparison
- Purchase requests
- Sales orders
- Order approval
- Order acceptance
- Order cancellation
- Fulfilment status
- Delivery tracking
- Returns and disputes
- Invoice references
- Payment status
- Transaction history

Use configurable permissions and approval workflows.

## 14.3 Pharmaceutical controls

Restrict restricted products and transactions according to applicable jurisdiction, customer authorization and organization type.

Do not allow public consumers to purchase regulated prescription products through an unrestricted general marketplace.

The platform must not bypass required licences, professional oversight or regulatory controls.

---

# 15. ENTERPRISE INTEGRATION GATEWAY

This is a strategic, first-class platform capability.

Large pharmaceutical manufacturers and enterprises must be able to participate without replacing their existing ERP.

The platform's message is:

**Keep your existing ERP. Connect your pharmaceutical supply chain to our network.**

## 15.1 Integration methods

Design connector interfaces for:

- REST APIs
- SOAP services
- SAP-supported APIs
- SAP IDoc integration where applicable
- SAP BAPI/RFC through an approved, properly secured integration adapter where appropriate
- Oracle and Microsoft Dynamics integration
- Webhooks
- EDI
- SFTP
- CSV
- XML
- JSON
- Scheduled imports and exports
- Event-driven integrations

Do not assume every SAP installation supports the same interface. Confirm the customer's product, version, deployment model, available interfaces and authorization requirements before selecting a connector.

Do not require direct database access to an enterprise ERP.

## 15.2 Canonical Pharmaceutical Data Model

Create a normalized canonical model for data exchanged between participants.

At minimum, model:

- Product
- Product identifier
- Regulatory identifier
- Organization
- Facility
- Warehouse
- Batch/lot
- Serialized trade item where applicable
- Inventory snapshot
- Inventory movement
- Purchase order
- Sales order
- Shipment
- Shipment item
- Receipt
- Traceability event
- Recall event

Each connector must map its source fields into the canonical model and map permitted outbound fields to the target system.

Document mappings, required fields, optional fields and validation rules.

Do not assume an external product code is identical to our canonical product ID.

## 15.3 Integration architecture

```text
EXTERNAL ERP
    |
    v
CONNECTOR / ADAPTER
    |
    v
AUTHENTICATION + VALIDATION
    |
    v
DURABLE INTEGRATION INBOX
    |
    v
TRANSFORMATION + CANONICAL MAPPING
    |
    v
VALIDATION + DEDUPLICATION
    |
    v
APPLICATION BUSINESS SERVICES
    |
    +--> Product Master
    +--> Batch / Traceability
    +--> Inventory
    +--> Orders
    +--> Analytics
```

Use asynchronous processing for large or unreliable transfers.

Implement an integration outbox for reliable outbound event delivery where appropriate.

## 15.4 Integration operations

Provide:

- Connector configuration
- Credentials stored securely
- Field mappings
- Import schedules
- Manual retry
- Failed-message review
- Idempotency
- Duplicate detection
- Rate limits
- Audit logs
- Integration health status
- Received/processed/rejected counts
- Error details with sensitive information redacted
- Dead-letter or failed-message handling
- Data reconciliation reports

Never log passwords, access tokens, private keys or sensitive payloads unnecessarily.

## 15.5 Integration guarantees

Do not promise universal exactly-once delivery across arbitrary external systems.

Use durable processing, idempotent operations, acknowledgement, retries and reconciliation to produce reliable business outcomes.

A source system must not be told that a message was successfully processed before the relevant processing guarantee has been satisfied.

---

# 16. BATCH TRACEABILITY, QR AND RECALL MANAGEMENT

Implement end-to-end pharmaceutical traceability as a shared platform service.

Reference for Nigerian traceability context:

https://nafdac.gov.ng/traceability/

Review current official NAFDAC material and other applicable official regulatory guidance before finalizing regulatory compliance requirements. Treat the reference as a starting point, not as an exhaustive technical specification.

## 16.1 Traceability objective

Track the authorized chain of custody and movement:

```text
MANUFACTURER
    -> IMPORTER, WHERE APPLICABLE
        -> DISTRIBUTOR
            -> WHOLESALER
                -> PHARMACY / HOSPITAL
                    -> DISPENSING / SALE EVENT
```

Represent actual events rather than assuming every product follows exactly the same route.

## 16.2 Traceability event model

Each event should capture the applicable fields:

- Event ID
- Event type
- Product ID
- Batch/lot ID
- Serialized identifier where applicable
- Quantity and unit
- Source organization
- Destination organization
- Source location
- Destination location
- Related order, shipment or transaction
- Event timestamp
- Recording timestamp
- Actor or originating system
- Country and jurisdiction
- Verification status
- Source reference
- Evidence attachments where appropriate
- Correction or reversal reference
- Audit metadata

Support event types such as:

- Production/batch creation
- Quality release
- Shipment dispatch
- Export/import receipt
- Stock receipt
- Stock transfer
- Ownership/custody transfer
- Pharmacy receipt
- Quarantine
- Recall
- Dispensing or sale
- Authorized correction

Do not equate a recorded event with independent verification of its truth.

## 16.3 QR codes and serialization

Support:

- QR-code generation
- QR-code association
- QR scanning
- Batch-level identifiers
- Product-level identifiers
- Serial-level identifiers where required
- Packaging hierarchy
- Identifier validation
- Duplicate identifier detection
- Revoked or recalled identifiers
- Scan history

A QR code may contain a signed, opaque identifier or a secure reference to a traceability record.

Do not expose confidential supplier, pricing or patient information in publicly readable QR payloads.

Do not assume that a QR code by itself establishes product authenticity.

Where applicable, design for compatible use of GS1 standards and relevant regulatory serialization requirements, after verifying the applicable specification.

## 16.4 Traceability at POS

At receipt and sale:

- Associate inventory with the correct batch.
- Apply FEFO rules where appropriate.
- Record the batch allocated to the sale.
- Prevent sale of expired or recalled batches.
- Record inventory movement and sale linkage.
- Support batch-level recall searches.
- Provide authorized staff with traceability history.

For offline sales, preserve the local batch allocation evidence and validate it during synchronization.

## 16.5 Recall and quarantine

Implement:

- Recall creation
- Recall reason and reference
- Affected product/batch/serial identifiers
- Scope and severity
- Organization notifications
- Quarantine instructions
- Inventory search
- Shipment and customer exposure reports
- Acknowledgement tracking
- Returned/recovered quantity
- Unaccounted quantity
- Closure workflow
- Audit history

Support rapid tracing of affected stock throughout participating organizations.

Do not claim complete recall coverage for organizations or transactions that have not supplied reliable data.

## 16.6 Traceability integrity

Preserve event history.

Do not silently edit or delete historical traceability events.

Use corrective or reversal events, versioned records and access-controlled audit history.

Design the event model so that tamper-evident controls can be added where justified.

A blockchain is not required by default.

---

# 17. CRM AND ADVANCED FIELD-FORCE MANAGEMENT

Phase 2 delivers Light CRM in the mobile field-force app.

Phase 6 expands this into a comprehensive CRM.

Include:

- Customer segmentation
- Territory planning
- Doctor/pharmacist/customer profiles
- Account assignment
- Visit scheduling
- Visit plans
- Follow-up management
- Leads and opportunities
- Sales targets
- Sales representative performance
- Product detailing records
- Order conversion
- Customer interaction history
- Supervisor approvals
- Customer feedback
- Route planning where appropriate
- CRM analytics

Maintain appropriate boundaries around sensitive professional and personal data.

---

# 18. LOGISTICS AND DELIVERY

Implement logistics after the core supply-chain and transaction services are established.

Include:

- Shipment creation
- Shipment items
- Carrier assignment
- Driver assignment
- Vehicle information
- Dispatch
- Tracking events
- Proof of delivery
- Partial delivery
- Failed delivery
- Return-to-origin
- Shipment reconciliation
- Cold-chain requirements
- Temperature records where applicable
- Temperature excursion alerts
- Delivery confirmation
- Audit history

Do not assume every shipment requires cold-chain monitoring. Apply handling rules according to product requirements.

---

# 19. ANALYTICS AND AI

Separate transactional business operations from analytical workloads.

## 19.1 Core analytics

Build dashboards for:

- Sales
- Purchases
- Inventory
- Stock movement
- Expiry risk
- Batch traceability
- Supplier performance
- Customer performance
- POS performance
- Field-force performance
- Marketplace transactions
- Integration health
- Offline synchronization
- Recall status
- Subscription revenue

Ensure users only see analytics for authorized organizations and locations.

## 19.2 AI capabilities

Plan for:

- Demand forecasting
- Reorder recommendations
- Overstock and stockout risk
- Expiry risk
- Procurement recommendations
- Sales trend analysis
- Field-force activity summaries
- Natural-language business reporting
- Integration anomaly detection
- Traceability anomaly detection

AI outputs must be clearly identified as recommendations, predictions or generated explanations.

Do not let an AI model independently authorize purchases, alter inventory, approve financial transactions or override regulatory restrictions.

Use validated data, explainable assumptions and human approval for material business actions.

Do not send confidential enterprise or patient data to external AI services without explicit authorization, appropriate agreements and data protection controls.

---

# 20. MULTI-COUNTRY ARCHITECTURE

Design for Nigeria first while supporting expansion to at least five African countries.

Implement country-aware configuration for:

- Currency
- Time zone
- Locale
- Number/date formatting
- Tax rules
- Invoice rules
- Regulatory identifiers
- Product classification
- Payment providers
- Organization verification requirements
- Country-specific documents
- Delivery workflows
- Language
- Address formats

Do not hard-code Nigerian tax, currency or regulatory rules into shared business logic.

Use effective-dated configuration and versioned rules where necessary.

Country expansion must not require rewriting the core Product Master or traceability engine.

Do not claim regulatory compliance in any country until the applicable requirements have been reviewed and the implementation validated.

---

# 21. SUBSCRIPTIONS AND MONETIZATION

Design a configurable SaaS billing system.

## 21.1 Subscription plans

Support plans such as:

- Starter
- Pharmacy
- Professional
- Distributor
- Enterprise
- Custom enterprise

The names and prices must be configurable.

## 21.2 Entitlements

Plan features may include:

- Number of users
- Number of branches
- Number of warehouses
- POS terminals
- Monthly transaction allowance
- Storage allowance
- Mobile users
- Integration connectors
- API rate limits
- Advanced reporting
- AI features
- Support level

Use entitlements enforced by backend services, not merely hidden UI controls.

## 21.3 Revenue channels

Support future monetization through:

- ERP subscriptions
- POS subscriptions
- Mobile field-force subscriptions
- Enterprise integration fees
- API access
- Advanced analytics and AI
- Marketplace transaction fees
- Logistics integrations
- Enterprise support and onboarding

Make fees, currencies, taxes and billing periods configurable.

## 21.4 Billing lifecycle

Implement:

- Trial
- Active subscription
- Renewal
- Upgrade/downgrade
- Grace period
- Failed payment handling
- Suspension
- Cancellation
- Invoice generation
- Payment reconciliation
- Subscription history

Do not disable access to legally required records or make dangerous data-loss actions when a subscription expires.

---

# 22. DATABASE DESIGN REQUIREMENTS

Create a normalized, documented MySQL schema with migrations and appropriate indexes.

Use migrations as the primary mechanism for managing schema changes.

Also generate:

`database/sql/pharmaceutical_supply_chain_schema.sql`

This file must be generated from the actual implemented schema or maintained and tested to match it. Do not create a disconnected SQL file that describes tables which do not exist in the application.

Document how to initialize the database and how to update the schema safely.

## 22.1 Required logical domains

At minimum, cover:

**Platform**
- Users
- Organizations
- Organization memberships
- Roles
- Permissions
- Branches
- Warehouses
- Subscriptions
- Entitlements
- Country configurations

**Product Master**
- Products
- Ingredients
- Brands
- Manufacturers
- Product identifiers
- Package levels
- Unit conversions
- Regulatory identifiers
- Regulatory documents
- Product verification history
- Tenant product mappings

**ERP**
- Suppliers
- Customers
- Purchase orders
- Purchase order items
- Goods receipts
- Sales orders
- Sales order items
- Invoices
- Invoice items
- Returns
- Payments
- Inventory balances or projections
- Inventory movements
- Stock reservations
- Stock adjustments
- Transfers
- Batches/lots
- Batch allocations
- Price lists

**POS**
- POS terminals
- Cashier sessions
- POS sales
- POS sale items
- Sale payments
- Shift records
- Cash movements
- Refunds
- Receipt records
- Offline transaction references

**Field Force**
- Representatives
- Territories
- Customer assignments
- Visits
- GPS check-in/check-out records
- Visit activities
- Follow-up tasks
- Mobile orders
- Device registrations
- Synchronization records

**Marketplace**
- Listings
- RFQs
- Quotations
- B2B orders
- Order items
- Fulfilment
- Disputes
- Supplier ratings where appropriate

**Traceability**
- Serialized identifiers where applicable
- Traceability events
- Batch genealogy
- Shipment events
- Custody transfers
- Recall records
- Recall items
- Recall acknowledgements
- Quarantine records
- Scan events

**Integration**
- Connector configurations
- External system references
- Field mappings
- Integration inbox
- Integration outbox
- Integration runs
- Failed messages
- Webhook subscriptions
- Delivery attempts

**Operations**
- Audit logs
- Notifications
- Attachments
- API clients
- Idempotency records
- Background job metadata where appropriate
- Feature flags
- System settings

Treat this as a logical inventory of domains, not permission to create redundant tables without considering relationships and lifecycle requirements.

## 22.2 Database standards

Use:

- Appropriate primary keys
- Foreign keys
- Unique constraints
- Indexes for common access patterns
- Decimal types for money and precise quantities
- Explicit currency codes
- UTC storage for timestamps where appropriate
- Consistent timestamps and audit fields
- Soft deletion only where justified
- Transaction boundaries
- Optimistic or pessimistic concurrency controls where appropriate

Never use floating-point numbers for financial amounts.

Use immutable movement/transaction records where business integrity requires them.

Document data-retention policies and sensitive-data handling.

---

# 23. SECURITY, PRIVACY AND COMPLIANCE

Security must be built into every phase.

Implement:

- Secure authentication
- Server-side authorization
- Tenant isolation
- Input validation
- CSRF protection for applicable web flows
- Output escaping
- SQL injection prevention
- Rate limiting
- Secure file uploads
- Malware scanning integration where appropriate
- API authentication
- Credential encryption
- Secrets management
- Encryption in transit
- Appropriate encryption at rest
- Audit trails
- Backup encryption
- Session revocation
- Device deauthorization
- Secure error handling
- Security headers
- Dependency vulnerability checks
- Least-privilege access

Protect personally identifiable information, GPS data, customer information and enterprise integration payloads.

Support configurable data retention and secure deletion policies where legally appropriate.

Do not store payment card data unless a compliant architecture and provider agreement explicitly require and permit it.

Keep platform administrators from accessing sensitive tenant data by default unless a documented, authorized support or compliance workflow permits access.

Regulatory verification and approval must remain distinct from internal platform verification.

The platform must not impersonate NAFDAC or any other regulator.

---

# 24. AUDITABILITY AND BUSINESS INTEGRITY

Provide auditable records for critical events, including:

- User and permission changes
- Organization verification
- Product/regulatory changes
- Inventory adjustments
- Batch movements
- Sales
- POS shift operations
- Refunds
- Payments
- Purchase approvals
- Integration changes
- Failed or retried synchronization
- Recall initiation and closure
- Privileged administrative operations

Record the actor, action, affected resource, timestamp, source and outcome as appropriate.

Avoid storing unnecessary sensitive payloads in audit records.

Implement append-only business history where appropriate, with restricted access and correction workflows.

Audit logging must not itself become a tenant data-leak channel.

---

# 25. API DESIGN

Use versioned REST APIs.

A representative structure is:

```text
/api/v1/auth
/api/v1/organizations
/api/v1/users
/api/v1/products
/api/v1/regulatory-identifiers
/api/v1/inventory
/api/v1/batches
/api/v1/purchase-orders
/api/v1/sales-orders
/api/v1/invoices
/api/v1/pos
/api/v1/pos/shifts
/api/v1/field-force/visits
/api/v1/field-force/orders
/api/v1/sync
/api/v1/marketplace
/api/v1/rfqs
/api/v1/traceability
/api/v1/recalls
/api/v1/shipments
/api/v1/integrations
/api/v1/reports
/api/v1/subscriptions
```

Adapt endpoint naming to the actual resource model.

Implement:

- Request validation
- Consistent response structures
- Pagination
- Filtering and sorting
- Authentication
- Authorization
- Rate limiting
- Idempotency where required
- Error codes
- API documentation
- Backward-compatibility policy
- Auditability
- Correlation IDs for troubleshooting

Generate and maintain an OpenAPI specification where practical.

Never expose internal database records without applying authorization and field-level visibility rules.

---

# 26. EVENT-READY ARCHITECTURE

Use Laravel events, queued jobs and clear service boundaries from the beginning.

Define domain events where useful, including:

- ProductCreated
- RegulatoryIdentifierUpdated
- BatchCreated
- StockReceived
- StockTransferred
- InventoryAdjusted
- OrderCreated
- OrderApproved
- SaleCompleted
- PaymentConfirmed
- ShipmentDispatched
- ShipmentDelivered
- TraceabilityEventRecorded
- BatchRecalled
- OfflineTransactionSynchronized
- IntegrationMessageFailed

Distinguish internal framework events from durable integration events.

For important cross-module operations, use a transactional outbox or equivalent durable publishing strategy when needed.

Consumers must be idempotent.

Do not allow a failed notification or analytics job to roll back an otherwise valid sale after the transaction has committed.

The architecture should permit future adoption of a dedicated event bus without requiring the immediate introduction of microservices.

---

# 27. SCALABILITY AND PERFORMANCE

The target ambition is to support approximately 100,000 daily active B2B transactions across five African countries, subject to the precise definition of a transaction and measured workloads.

Do not treat this number alone as proof that a particular database or framework must be replaced.

Measure actual throughput, peak concurrency, transaction size, read/write ratio, queue backlog and latency.

## 27.1 Initial production topology

```text
LOAD BALANCER
    |
    +-- Laravel Application Instance 1
    +-- Laravel Application Instance 2
    +-- Additional Instances as Required
                |
                +-- Redis
                +-- Queue Workers
                +-- MySQL Primary
                +-- MySQL Read Replicas as Needed
                +-- Object Storage
                +-- Monitoring and Logs
```

Ensure application instances are stateless or use appropriate shared services.

## 27.2 Separate workloads

Keep transactional operations separate from analytical reporting and AI workloads.

Avoid running large analytical queries directly against busy transactional tables when they would degrade core business operations.

Introduce read replicas, reporting projections or an analytical store when measurements justify them.

## 27.3 Load and concurrency tests

Create repeatable tests for:

- Concurrent inventory updates
- Simultaneous POS sales
- Duplicate order submissions
- Duplicate payment callbacks
- Batch allocation conflicts
- Integration bursts
- Synchronization after a prolonged outage
- Queue backlogs
- Large product imports
- Tenant-isolation performance
- Reporting under load

Document the test environment and measured results.

Do not invent performance results.

---

# 28. OBSERVABILITY AND OPERATIONS

Implement structured logs, health checks and monitoring hooks.

Track:

- Application errors
- API latency
- Database performance
- Queue backlog
- Failed jobs
- Integration failures
- Import/export processing
- Offline synchronization failures
- Pending device transactions
- POS payment reconciliation
- Inventory conflicts
- Recall processing
- Storage utilization
- Backup status

Provide actionable alerts for operational failures.

Do not expose sensitive data through logs or monitoring dashboards.

Document incident response, backup restoration and recovery procedures.

---

# 29. TESTING STRATEGY

Use appropriate automated tests for every critical module.

## 29.1 Required test categories

- Unit tests
- Feature tests
- API tests
- Authorization tests
- Tenant-isolation tests
- Database constraint tests
- Integration tests
- Queue/retry tests
- Mobile synchronization tests
- POS business-flow tests
- Traceability tests
- Performance/load tests
- Security tests

## 29.2 Mandatory business integrity scenarios

Verify that:

1. A user cannot read another tenant's restricted data.
2. An unauthorized cashier cannot approve a restricted refund.
3. An expired or recalled batch cannot be sold through normal workflows.
4. A failed inventory update does not produce a completed sale with inconsistent stock.
5. Duplicate synchronization does not create duplicate sales or orders.
6. Duplicate payment callbacks do not allocate the same payment twice.
7. A GPS check-in does not claim location verification when the location is unavailable or invalid.
8. An offline order is not presented as centrally accepted before server validation.
9. Integration retries do not duplicate inventory movements.
10. A recall can identify known affected batches and participating locations.
11. Historical traceability events are not silently overwritten.
12. Subscription restrictions cannot be bypassed through direct API requests.
13. Concurrent transactions cannot oversell inventory beyond configured policy.
14. Failed jobs can be retried or investigated without losing the original request.
15. Users cannot view integration credentials or unauthorized customer information.

Use factories and clearly labelled synthetic seed data.

Never use fabricated data as evidence of real regulatory approval or genuine pharmaceutical supply-chain provenance.

---

# 30. USER INTERFACE AND EXPERIENCE

Design a professional enterprise application that is responsive, accessible and usable in real operational environments.

The interface should feel suitable for pharmaceutical manufacturers, distributors, hospitals and pharmacies.

Use consistent design tokens and reusable components.

Provide:

- Clear navigation
- Role-specific dashboards
- Search and filters
- Pagination
- Loading indicators
- Empty states
- Error messages
- Confirmation dialogs for destructive actions
- Form validation
- Accessible labels and keyboard interaction
- Mobile-responsive layouts
- Printable invoices and reports
- Export permissions
- Audit history views
- Clear synchronization indicators

Prioritize fast operational workflows over decorative complexity.

The POS must be optimized for transaction speed.

The mobile field-force app must minimize typing and support intermittent connectivity.

Do not expose modules to users who lack the necessary permissions or entitlements.

---

# 31. IMPLEMENTATION PHASES AND ACCEPTANCE CRITERIA

Implement phases in the following order. Some technical foundations may be prepared earlier, but do not claim a later phase is complete until its acceptance criteria pass.

## PHASE 0 — DISCOVERY, REPOSITORY AUDIT AND ARCHITECTURE

Tasks:

- Inspect the environment.
- Confirm installed software and supported versions.
- Create the master documentation.
- Define module boundaries.
- Define domain entities and relationships.
- Define tenant-isolation strategy.
- Define API conventions.
- Define database naming conventions.
- Define test strategy.
- Define local development workflow.
- Create the implementation roadmap.
- Identify external credentials and integrations that will require owner configuration.

Deliverables:

- Architecture specification
- Database design
- Module dependency map
- Development setup instructions
- Implementation status file

Acceptance: The project can be initialized reproducibly and its architecture and phased plan are documented.

## PHASE 1 — PLATFORM FOUNDATION

Implement:

- Application skeleton
- Authentication
- Organizations
- Multi-tenancy
- Users and memberships
- Roles and permissions
- Organization verification workflow
- Branches and warehouses
- Country configuration foundations
- Branding configuration
- Platform administration
- Audit infrastructure
- API foundations
- Subscription and entitlement foundations
- Logging and health checks
- Initial CI/test workflow

Acceptance:

- Organizations can be created and managed.
- Authorized users can be invited and assigned roles.
- Tenant isolation is tested.
- Privileged operations are protected.
- Branding and country configuration are not hard-coded.
- Database migrations and initial tests pass.

## PHASE 2 — PHARMACEUTICAL ERP + POS + MOBILE FIELD FORCE/LIGHT CRM

Implement the Product Master foundation, including NAFDAC regulatory identifiers and product identity, then build:

**ERP**
- Inventory
- Warehouse
- Batch and expiry
- Procurement
- Suppliers
- Customers
- Sales
- Invoicing
- Finance foundations
- Branch management
- Stock transfers and adjustments

**POS**
- POS interface
- Product search and barcode scanning
- Cart and checkout
- Batch/FEFO selection
- Sales and invoices
- Payment recording
- Returns/refunds
- Cashier shifts
- Reconciliation
- POS reports
- Direct integration with ERP sales, inventory and finance services

**Mobile Field Force/Light CRM**
- Flutter application
- Customer/pharmacy directory
- Territory and customer assignments
- GPS check-in/check-out
- Visit logs
- Follow-up tasks
- Product catalogue
- Draft and submitted orders
- Order status
- Basic supervisor reports
- Local data model designed for offline operation

Acceptance:

- POS transactions update the ERP consistently.
- Inventory changes are recorded in an auditable ledger.
- Expired and recalled stock is blocked by default.
- Cashier shifts can be reconciled.
- Field representatives can capture visits and orders.
- GPS verification is truthful and permission-controlled.
- Mobile APIs enforce user, tenant and territory permissions.
- Tests cover the critical sales, stock and authorization paths.

## PHASE 3 — OFFLINE-FIRST POS AND MOBILE SYNCHRONIZATION

Implement:

- Local encrypted data storage
- Offline POS transactions
- Offline receipts
- Offline field-force visits
- Offline order capture
- Durable synchronization queue
- Retry and recovery
- Idempotency
- Duplicate prevention
- Conflict detection
- Inventory reconciliation
- Sync status indicators
- Device registration and revocation
- Offline payment state handling
- Recovery after device/application restart

Acceptance:

- Supported sales and visits survive internet outages and application restarts.
- Repeated synchronization does not duplicate transactions.
- Conflicting stock changes are detected and reconciled.
- Offline payments are not falsely represented as confirmed.
- The application exposes pending and failed synchronization states.
- The six-hour outage scenario is tested under a documented device and workload profile.

## PHASE 4 — B2B MARKETPLACE AND SUPPLY-CHAIN TRANSACTIONS

Implement:

- Supplier discovery
- Product listings
- Buyer/supplier relationships
- RFQ
- Quotations
- B2B ordering
- Order approvals
- Fulfilment
- Returns and disputes
- Payment status
- Marketplace transaction fees
- Supplier verification status

Acceptance:

- Authorized organizations can discover suppliers and exchange orders.
- Orders connect to the ERP transaction model.
- Restricted products and customers are controlled.
- Supplier and inventory information is labelled accurately.
- Marketplace fees and transaction records are auditable.

## PHASE 5 — ENTERPRISE INTEGRATION GATEWAY

Implement:

- Canonical Pharmaceutical Data Model
- Connector framework
- External system registry
- Field mappings
- REST integration
- Secure file-based import/export
- SFTP support where configured
- Webhook framework
- Asynchronous processing
- Integration inbox/outbox
- Retry and failed-message management
- Idempotency
- Integration monitoring
- Batch and product data ingestion
- Mapping and reconciliation reports
- Connector documentation

Add SAP-specific connectivity through an appropriate adapter based on the target SAP environment and its supported interfaces.

Acceptance:

- A sample external ERP dataset can be mapped into the canonical model.
- Invalid data is rejected or quarantined with an actionable error.
- Retried messages do not duplicate business transactions.
- Integration health and failures are visible.
- Sensitive credentials remain protected.
- The integration can operate without forcing the external organization to replace its ERP.

## PHASE 6 — ADVANCED CRM AND FIELD FORCE

Implement:

- Customer segmentation
- Advanced territory management
- Visit planning
- Sales targets
- Opportunities
- Follow-up automation
- Supervisor dashboards
- Customer interaction history
- Product detailing
- CRM analytics
- Enhanced mobile workflows

Acceptance: Supervisors can review authorized field activities, visits, orders and sales outcomes without violating tenant or territory permissions.

## PHASE 7 — TRACEABILITY, RECALLS AND LOGISTICS

Implement:

- End-to-end batch traceability
- QR-code association and scanning
- Serialization support where applicable
- Custody and movement history
- Shipment events
- Proof of delivery
- Recall workflows
- Quarantine
- Recall notifications and acknowledgement
- Batch exposure reports
- Cold-chain support where applicable
- Traceability audit history

Acceptance:

- A test batch can be traced through recorded supply-chain events.
- Authorized users can identify participating locations holding affected batches.
- Recalled and expired stock is blocked through appropriate business rules.
- Corrective events preserve history.
- The system distinguishes recorded evidence from independently verified authenticity.
- Applicable official regulatory requirements are reviewed and documented.

## PHASE 8 — ANALYTICS AND AI

Implement:

- Executive dashboards
- Inventory analytics
- Sales analytics
- POS analytics
- Field-force analytics
- Marketplace analytics
- Expiry-risk reporting
- Integration monitoring
- Forecasting interfaces
- AI reporting assistant
- AI procurement and inventory recommendations

Acceptance: Analytics respect permissions and tenant boundaries, and AI recommendations cannot independently perform unauthorized financial, inventory or regulatory actions.

## PHASE 9 — MULTI-COUNTRY EXPANSION

Implement and validate:

- Country configurations
- Currency handling
- Localized dates and numbers
- Tax rules
- Regulatory identifier configurations
- Local payment adapters
- Country-specific onboarding
- Country-aware reports
- Regional deployment readiness

Acceptance: Adding a country does not require hard-coding country-specific assumptions throughout the application.

## PHASE 10 — SCALE, RESILIENCE AND ENTERPRISE HARDENING

Implement as justified by measurements:

- Multiple application instances
- Load balancing
- Read replicas
- Queue scaling
- Durable event delivery
- Analytics data separation
- Dedicated search
- Event streaming
- Regional infrastructure
- Service decomposition where necessary
- Disaster recovery improvements
- Load and resilience testing

Acceptance:

- Load-test results and limitations are documented.
- Recovery procedures are tested.
- Queue backlogs and failures are observable.
- Transactional consistency is preserved.
- Scaling changes are based on measured bottlenecks.

---

# 32. REQUIRED SEED DATA AND DEMONSTRATION WORKFLOWS

Create clearly labelled synthetic development data for:

- One manufacturer
- One importer
- One distributor
- One wholesaler
- Two pharmacies
- One hospital
- Multiple branches and warehouses
- Sample products with clearly marked fictional regulatory identifiers
- Sample batches with different expiry dates
- Sample suppliers
- Sample customers
- Sample POS users
- Sample field representatives
- Sample purchase and sales orders

Create demonstration workflows for:

1. Product creation and regulatory identifier review.
2. Batch receipt and stock allocation.
3. Purchase order through goods receipt.
4. POS sale through invoice, payment recording and inventory deduction.
5. Shift opening and closing.
6. Offline POS sale and subsequent synchronization.
7. Offline field-force visit and order capture.
8. Synchronization conflict and recovery.
9. External ERP product/batch import.
10. B2B order from supplier discovery through fulfilment.
11. Batch traceability across multiple organizations.
12. Recall identification and quarantine.

Never label synthetic regulatory data as real or approved.

---

# 33. DEPLOYMENT AND DEVOPS

Prepare reproducible development and production deployment instructions.

Include:

- Environment requirements
- Environment-variable examples
- Database initialization
- Migrations
- Seed data
- Asset compilation
- Queue workers
- Scheduler
- File storage
- HTTPS
- Database backups
- Restore procedures
- Monitoring
- Logging
- Deployment checklist
- Rollback strategy
- Mobile build instructions
- API configuration
- Integration credential setup

Provide `.env.example`, never real secrets.

For production, use environment-specific configuration and managed secrets where possible.

Document the requirements that make basic shared hosting unsuitable for certain enterprise workloads.

Do not claim the platform is deployed or production-ready merely because it runs locally.

---

# 34. FINAL DELIVERABLES

At the appropriate stages, produce:

1. Working application source code.
2. Database migrations.
3. `database/sql/pharmaceutical_supply_chain_schema.sql`.
4. Seeders and synthetic demo data.
5. API documentation.
6. OpenAPI specification where practical.
7. Flutter mobile application source.
8. ERP and POS interfaces.
9. Mobile Field Force/Light CRM application.
10. Offline synchronization implementation.
11. Enterprise integration framework.
12. Product Master and regulatory identifier model.
13. Batch/QR traceability and recall workflows.
14. Automated tests.
15. CI/build configuration.
16. Deployment instructions.
17. Security and privacy documentation.
18. Backup and recovery documentation.
19. Architecture and database diagrams.
20. Phase-by-phase implementation report.
21. Known limitations and outstanding work.
22. A final launch-readiness checklist.

Where the working environment cannot produce a particular artifact or execute a particular test, explain the limitation accurately and provide the reproducible steps needed to complete it.

---

# 35. DEFINITION OF DONE

A phase is complete only when:

- Its documented scope is implemented.
- Database migrations work.
- Business rules are enforced server-side.
- Authorization is implemented.
- Tenant boundaries are tested.
- Relevant tests pass.
- Critical errors have been addressed.
- The interface connects to real backend functionality.
- API contracts are documented.
- Logging and error handling exist.
- Relevant documentation is updated.
- Known limitations are disclosed.
- The implementation status reflects actual evidence.

Do not substitute placeholder data for required production functionality without clearly identifying it as a temporary placeholder.

Do not claim a feature works if only its user interface has been built.

---

# 36. YOUR FIRST ACTIONS

Begin by performing the following steps in order:

1. Inspect the current working directory and development environment.
2. Identify whether the repository is empty, partially implemented or already operational.
3. Confirm the available PHP, Composer, Laravel, Node.js, Flutter, MySQL and testing tools as relevant.
4. Identify compatibility issues and dependencies.
5. Create `PROJECT_MASTER_SPEC.md` and the initial documentation set.
6. Create `docs/SYSTEM_ARCHITECTURE.md` and `docs/DATABASE_DESIGN.md`.
7. Produce a clear module dependency map and implementation roadmap.
8. Define the initial entity relationship model, including Product Master, NAFDAC identifiers, batches, inventory, POS, field-force visits, offline synchronization and enterprise integrations.
9. Define tenant isolation, authentication and authorization before building business modules.
10. Initialize or adapt the application architecture.
11. Implement Phase 1 in small, testable increments.
12. Run the relevant tests and correct failures.
13. Update `IMPLEMENTATION_STATUS.md`.
14. Present a concise report describing what works, what remains and the next implementation steps.

Continue through the roadmap as the project environment, available time, dependencies and verification results permit.

Do not stop after producing plans or documentation. Implement the application incrementally.

Do not attempt to generate the entire system in one enormous code dump.

Do not ask the user to make routine technical decisions that you can safely resolve using the specified architecture. When a decision has material cost, security, regulatory or operational implications, document the options and select a safe default where possible.

When a required external credential or service is unavailable, implement a properly abstracted adapter and a clearly labelled development/testing mode. Never fabricate a successful production integration.

**Start now by auditing the repository, creating the architecture documents and beginning Phase 1.**
