<?php

namespace App\Support\Web;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * The web application's map: every workspace section and its pages, with the
 * module that must be enabled and the permissions that reveal each page.
 *
 * Menus, page banners and section tabs are built from it. Visibility here is a
 * convenience only: every page and every API call it makes re-checks access.
 */
class Navigation
{
    /**
     * Section key => [label, icon, group, modules (any of; null = none needed), scope, pages].
     * Page: [route, label, icon, permissions (any of), description, modules (optional, any of; narrows the section's)].
     * Scope "tenant" needs a selected organization; "platform" is platform staff; "any" is everyone.
     */
    public const SECTIONS = [
        'catalogue' => ['Catalogue', 'beaker', 'Supply chain', null, 'any', [
            ['catalogue', 'Product master', 'beaker', [], 'Search the shared product master by name, SKU, GTIN or registration number.'],
            ['catalogue.items', 'Our catalogue', 'rectangle-stack', ['tenant_products.view'], 'Products your organization trades, with SKUs, prices and reorder levels.', ['catalogue']],
            ['catalogue.change-requests', 'Change requests', 'pencil-square', ['catalogue.propose'], 'Propose new products or corrections to the shared product master.'],
        ]],
        'inventory' => ['Inventory', 'cube', 'Supply chain', ['inventory', 'pos'], 'tenant', [
            ['inventory.stock', 'Stock on hand', 'cube', ['inventory.view'], 'Batch-level stock by location, low stock, expiry and valuation.'],
            ['inventory.batches', 'Batches', 'qr-code', ['inventory.view'], 'Every batch with its expiry and quality status: quarantine, release, reject.'],
            ['inventory.movements', 'Movements', 'arrows-right-left', ['inventory.view'], 'The stock ledger: every receipt, issue, transfer and adjustment.'],
            ['inventory.receipts', 'Receive stock', 'inbox-arrow-down', ['inventory.receive'], 'Book stock in without a purchase order: opening balances and donations.'],
            ['inventory.adjustments', 'Adjustments', 'adjustments-horizontal', ['inventory.adjust'], 'Write stock up or down with a reason: damage, expiry, found stock.'],
            ['inventory.counts', 'Stock counts', 'clipboard-document-check', ['inventory.count'], 'Cycle and full counts: count, review variances, post.'],
            ['inventory.transfers', 'Transfers', 'truck', ['inventory.transfer', 'inventory.view'], 'Move stock between your warehouses: dispatch and receive.'],
            ['inventory.settings', 'Settings', 'cog-6-tooth', ['inventory.settings.manage'], 'Allocation, expiry and negative-stock rules.'],
        ]],
        'procurement' => ['Procurement', 'shopping-cart', 'Supply chain', ['procurement'], 'tenant', [
            ['procurement.requisitions', 'Requisitions', 'clipboard-document-list', ['procurement.view'], 'Internal requests to buy: submit, approve, convert to orders.'],
            ['procurement.orders', 'Purchase orders', 'shopping-cart', ['procurement.view'], 'Orders to suppliers: approve, send, receive, close.'],
            ['procurement.receipts', 'Goods receipts', 'inbox-arrow-down', ['procurement.view'], 'Receive goods against purchase orders, batch by batch.'],
            ['procurement.invoices', 'Supplier invoices', 'document-text', ['procurement.view'], 'Match supplier invoices to orders and receipts, then approve.'],
            ['procurement.returns', 'Supplier returns', 'arrow-uturn-left', ['procurement.view'], 'Return damaged, expired or recalled stock to suppliers.'],
            ['procurement.suggestions', 'Reorder suggestions', 'light-bulb', ['procurement.view'], 'What to reorder, from stock levels, reorder points and open orders.'],
            ['procurement.settings', 'Settings', 'cog-6-tooth', ['procurement.settings.manage'], 'Approval thresholds and matching tolerances.'],
        ]],
        'logistics' => ['Logistics', 'truck', 'Supply chain', ['logistics', 'inventory'], 'tenant', [
            ['logistics.shipments', 'Shipments', 'truck', ['logistics.view'], 'Plan, dispatch, track and deliver shipments, including cold chain.'],
            ['logistics.vehicles', 'Vehicles', 'wrench-screwdriver', ['logistics.view'], 'Your fleet and its temperature capability.'],
            ['logistics.reconciliation', 'Reconciliation', 'scale', ['logistics.view'], 'Shipped against delivered quantities, and open exceptions.'],
        ]],
        'traceability' => ['Traceability', 'finger-print', 'Supply chain', null, 'tenant', [
            ['trace.verify', 'Verify a pack', 'qr-code', ['traceability.view'], 'Scan or type a code to verify a pack, then record dispensing.'],
            ['trace.identifiers', 'Identifiers', 'tag', ['traceability.view'], 'Issue identifiers and serials, aggregate packs, print QR codes.'],
            ['trace.events', 'Events', 'list-bullet', ['traceability.view'], 'The traceability event log with corrections and reversals.'],
            ['trace.lots', 'Lot history', 'magnifying-glass', ['traceability.view'], 'Where a batch went: every event and holder.'],
            ['trace.integrity', 'Integrity', 'shield-check', ['traceability.view'], 'Check that the event chain has not been altered; export EPCIS.'],
        ]],
        'compliance' => ['Recalls & incidents', 'exclamation-triangle', 'Supply chain', null, 'tenant', [
            ['recalls.index', 'Recalls', 'exclamation-triangle', ['recalls.initiate', 'recalls.respond', 'traceability.view'], 'Recalls you have issued: exposure, activation and closure.'],
            ['recalls.notices', 'Recall notices', 'bell-alert', ['recalls.respond', 'recalls.initiate', 'traceability.view'], 'Recalls affecting your stock: acknowledge and complete.'],
            ['recalls.incidents', 'Regulatory incidents', 'document-magnifying-glass', ['regulatory.incidents.manage', 'traceability.view'], 'Incidents to report to regulators, with deadlines.'],
        ]],
        'parties' => ['Customers & suppliers', 'user-group', 'Commerce', ['procurement', 'inventory', 'sales', 'pos', 'field_force'], 'tenant', [
            ['parties.customers', 'Customers', 'user-group', ['customers.view', 'customers.view_assigned'], 'Customer accounts, contacts, credit terms and statements.', ['sales', 'pos', 'field_force']],
            ['parties.suppliers', 'Suppliers', 'building-office', ['suppliers.view'], 'Supplier accounts, contacts, price lists and performance.', ['procurement', 'inventory']],
        ]],
        'sales' => ['Sales', 'banknotes', 'Commerce', ['sales'], 'tenant', [
            ['sales.orders', 'Sales orders', 'shopping-bag', ['sales.view'], 'Orders from customers: approve, deliver, invoice.'],
            ['sales.quotations', 'Quotations', 'document-duplicate', ['sales.view'], 'Price offers that convert into orders.'],
            ['sales.invoices', 'Invoices', 'document-text', ['sales.view'], 'Customer invoices and credit notes.'],
            ['sales.payments', 'Payments', 'credit-card', ['sales.view'], 'Receive customer payments and allocate them to invoices.'],
            ['sales.aging', 'Receivables aging', 'clock', ['sales.view'], 'Who owes what, and for how long.'],
            ['sales.price-lists', 'Price lists', 'currency-dollar', ['sales.view', 'sales.price_lists.manage'], 'Selling prices by product and pack level.'],
            ['sales.settings', 'Settings', 'cog-6-tooth', ['sales.settings.manage'], 'Credit control, approvals and numbering.'],
        ]],
        'pos' => ['Point of sale', 'building-storefront', 'Commerce', ['pos'], 'tenant', [
            ['pos.register', 'Register', 'building-storefront', ['pos.sell'], 'Sell over the counter, take payment and print receipts.'],
            ['pos.shifts', 'Shifts', 'clock', ['pos.shift.review', 'pos.reports.view'], 'Cashier shifts, cash counts and variances to review.'],
            ['pos.refunds', 'Refunds', 'receipt-refund', ['pos.refund.approve', 'pos.refund.request'], 'Refund requests waiting for approval.'],
            ['pos.payments', 'Pending payments', 'credit-card', ['pos.payments.confirm'], 'Card and transfer payments waiting for confirmation.'],
            ['pos.reports', 'Reports', 'chart-bar', ['pos.reports.view'], 'Sales by day, cashier, product and payment method.'],
            ['pos.terminals', 'Terminals', 'computer-desktop', ['pos.terminals.manage'], 'Registers and the warehouse each one sells from.'],
        ]],
        'marketplace' => ['Marketplace', 'globe-europe-africa', 'Commerce', ['marketplace'], 'tenant', [
            ['marketplace.discover', 'Discover', 'magnifying-glass', ['marketplace.browse'], 'Find verified suppliers and their offers.'],
            ['marketplace.orders', 'Orders', 'shopping-bag', ['marketplace.browse', 'marketplace.orders.place', 'marketplace.orders.fulfil'], 'Orders you placed or received: approve, ship, receive, invoice.'],
            ['marketplace.rfqs', 'RFQs', 'chat-bubble-left-right', ['marketplace.rfq.manage', 'marketplace.quotes.respond'], 'Requests for quotation: send, compare, award.'],
            ['marketplace.quotes', 'Quotes', 'document-duplicate', ['marketplace.rfq.manage', 'marketplace.quotes.respond'], 'Quotes sent and received.'],
            ['marketplace.listings', 'Listings', 'rectangle-stack', ['marketplace.listings.manage'], 'What you offer to buyers: prices, minimums, visibility.'],
            ['marketplace.relationships', 'Trading partners', 'link', ['marketplace.relationships.manage', 'marketplace.browse'], 'Requests to trade, approvals and credit terms.'],
            ['marketplace.disputes', 'Disputes', 'scale', ['marketplace.disputes.manage'], 'Problems with orders: messages, escalation, resolution.'],
            ['marketplace.fees', 'Fees', 'receipt-percent', ['marketplace.orders.fulfil'], 'Marketplace fees charged on your orders.'],
            ['marketplace.profile', 'Profile', 'identification', ['marketplace.listings.manage'], 'How your organization appears to buyers.'],
        ]],
        'field_force' => ['Field force', 'map-pin', 'Field & CRM', ['field_force'], 'tenant', [
            ['field-force.dashboard', 'Field force dashboard', 'presentation-chart-line', ['field_force.team.view', 'field_force.view_all', 'crm.plans.approve'], 'Team visits, plan approvals, targets and pipeline.'],
            ['field-force.visits', 'Visits', 'map-pin', ['field_force.visits.capture', 'field_force.team.view', 'field_force.view_all'], 'Customer visits with check-in, activities and check-out.'],
            ['field-force.orders', 'Field orders', 'shopping-bag', ['field_force.orders.capture', 'field_force.team.view', 'field_force.view_all'], 'Orders taken in the field: draft, submit, track.'],
            ['field-force.follow-ups', 'Follow-ups', 'bell', ['field_force.visits.capture', 'field_force.team.view', 'field_force.view_all'], 'Promised actions after visits.'],
            ['field-force.territories', 'Territories', 'map', ['field_force.territories.manage', 'field_force.team.view', 'field_force.view_all'], 'Territories, their members and assigned customers.'],
            ['field-force.activity', 'Activity report', 'chart-bar-square', ['field_force.reports.view', 'field_force.team.view', 'field_force.view_all'], 'Visits, orders and coverage by representative.'],
        ]],
        'crm' => ['CRM', 'users', 'Field & CRM', ['field_force'], 'tenant', [
            ['crm.accounts', 'Accounts', 'users', ['customers.view', 'customers.view_assigned'], 'Customer profiles, contacts, consent and timeline.'],
            ['crm.plans', 'Visit plans', 'calendar-days', ['field_force.visits.capture', 'crm.plans.approve'], 'Weekly visit plans: build, submit, approve, route.'],
            ['crm.opportunities', 'Opportunities', 'sparkles', ['customers.view', 'customers.view_assigned'], 'Pipeline of deals and leads.'],
            ['crm.targets', 'Targets', 'flag', ['crm.targets.manage', 'field_force.team.view', 'field_force.visits.capture'], 'Visit, order and revenue targets with progress.'],
            ['crm.interactions', 'Interactions', 'chat-bubble-left-ellipsis', ['customers.view', 'customers.view_assigned'], 'Calls, meetings and messages logged with customers.'],
            ['crm.feedback', 'Feedback', 'megaphone', ['crm.feedback.manage', 'customers.view', 'customers.view_assigned'], 'Complaints, requests and product feedback with follow-through.'],
            ['crm.detailing', 'Detailing', 'presentation-chart-bar', ['crm.detailing.manage', 'field_force.visits.capture'], 'Approved product messages and what was presented.'],
            ['crm.segments', 'Segments', 'squares-plus', ['crm.segments.manage', 'customers.view'], 'Groups of customers by rule or by hand.'],
            ['crm.analytics', 'CRM analytics', 'chart-pie', ['field_force.reports.view'], 'Coverage, frequency and conversion.'],
            ['crm.automation', 'Automation', 'bolt', ['crm.automation.manage'], 'Automatic follow-ups and visit suggestions.'],
        ]],
        'finance' => ['Finance', 'calculator', 'Finance & insight', ['finance'], 'tenant', [
            ['finance.reports', 'Reports', 'document-chart-bar', ['finance.view'], 'Trial balance, profit and loss, balance sheet and ledgers.'],
            ['finance.journals', 'Journal entries', 'book-open', ['finance.view'], 'Posted journals with manual entries and reversals.'],
            ['finance.payments', 'Payments', 'banknotes', ['finance.view'], 'Supplier payments and allocations to invoices.'],
            ['finance.accounts', 'Chart of accounts', 'list-bullet', ['finance.view'], 'Ledger accounts and their types.'],
            ['finance.periods', 'Periods', 'calendar', ['finance.view'], 'Open, close and lock accounting periods.'],
        ]],
        'analytics' => ['Analytics', 'chart-bar', 'Finance & insight', ['analytics'], 'tenant', [
            ['analytics.executive', 'Executive dashboard', 'chart-bar', ['analytics.view'], 'Headline figures across sales, stock, expiry risk and operations.'],
            ['analytics.dashboards', 'Dashboards', 'presentation-chart-line', ['analytics.view'], 'Sales, inventory, procurement, finance and operations dashboards.'],
            ['analytics.forecast', 'Forecast', 'arrow-trending-up', ['analytics.view'], 'Demand forecasts per product and location.'],
            ['analytics.recommendations', 'Recommendations', 'light-bulb', ['analytics.view'], 'Advisory reorder, transfer and expiry actions to accept or dismiss.'],
            ['analytics.assistant', 'Assistant', 'chat-bubble-bottom-center-text', ['analytics.view'], 'Ask a question about your data in plain words.'],
        ]],
        'integrations' => ['Integrations', 'puzzle-piece', 'Connect', ['integrations'], 'tenant', [
            ['integration.connectors', 'Connectors', 'puzzle-piece', ['integrations.view'], 'ERP and partner connections: keys, signing, mappings, files.'],
            ['integration.messages', 'Messages', 'envelope', ['integrations.view'], 'Inbound messages: processed, quarantined, failed. Retry or map.'],
            ['integration.runs', 'Runs', 'play', ['integrations.view'], 'File and batch runs with their line results.'],
            ['integration.webhooks', 'Webhooks', 'paper-airplane', ['integrations.view'], 'Outbound event subscriptions, deliveries and the outbox.'],
            ['integration.references', 'External references', 'link', ['integrations.view'], 'Your codes mapped to partner systems\' codes.'],
            ['integration.health', 'Health', 'heart', ['integrations.view'], 'Connector status, backlog and recent failures.'],
        ]],
        'sync' => ['Devices & sync', 'device-phone-mobile', 'Connect', null, 'tenant', [
            ['sync.overview', 'Sync', 'arrow-path', ['sync.reconcile', 'devices.manage'], 'Offline sync status, conflicts to resolve and reconciliation.'],
            ['organization.devices', 'Devices', 'device-phone-mobile', ['devices.manage'], 'Registered phones, tablets and tills: revoke or wipe.'],
        ]],
        'organization' => ['Organization', 'building-office-2', 'Organization', null, 'tenant', [
            ['organization.profile', 'Profile & verification', 'identification', ['organization.view'], 'Details, licences, documents and platform verification.'],
            ['organization.locations', 'Branches & warehouses', 'building-storefront', ['locations.view'], 'Where you trade and hold stock, with storage bins.'],
            ['organization.members', 'Members & roles', 'users', ['organization.members.manage', 'organization.view'], 'Invite people, assign roles and locations.'],
            ['organization.subscription', 'Subscription', 'credit-card', ['organization.billing.view'], 'Your plan, modules and limits.'],
            ['organization.audit', 'Audit log', 'clipboard-document-list', ['audit.view'], 'Who did what, and when.'],
        ]],
        'admin' => ['Administration', 'cog-6-tooth', 'Administration', null, 'platform', [
            ['admin.organizations', 'Organizations', 'building-office-2', ['platform.organizations.view'], 'Every organization: modules, subscription, suspension.'],
            ['admin.verifications', 'Verification queue', 'shield-check', ['platform.organizations.view'], 'Review organization documents and decide platform verification.'],
            ['admin.users', 'Users', 'users', ['platform.users.manage'], 'Platform accounts: suspend, reactivate, reset two-factor.'],
            ['admin.catalogue', 'Product master', 'beaker', ['catalogue.manage', 'catalogue.review', 'regulatory.verify'], 'Create and edit products, review change requests, verify identifiers.'],
            ['admin.recalls', 'Recalls', 'exclamation-triangle', ['recalls.manage'], 'Platform-initiated recalls and their reach.'],
            ['admin.trace', 'Traceability', 'finger-print', ['traceability.view_all', 'traceability.verify'], 'Lot history across the network, event verification, integrity.'],
            ['admin.marketplace', 'Marketplace', 'globe-europe-africa', ['platform.marketplace.manage'], 'Escalated disputes and marketplace fees.'],
            ['admin.countries', 'Countries', 'globe-europe-africa', ['platform.countries.manage'], 'Country profiles, tax rates, identifier types and activation.'],
            ['admin.exchange-rates', 'Exchange rates', 'currency-dollar', ['platform.settings.manage', 'platform.countries.manage'], 'Rates used to present multi-currency reports.'],
            ['admin.plans', 'Subscription plans', 'credit-card', ['platform.billing.manage'], 'Plans, prices, modules and limits.'],
            ['admin.analytics', 'Platform analytics', 'chart-bar', ['platform.organizations.view'], 'Network growth, verification, revenue and value by country.'],
            ['admin.operations', 'Operations', 'server-stack', ['platform.operations.manage'], 'Queues, scheduler, failed jobs and system health.'],
            ['admin.security', 'Security policy', 'lock-closed', ['platform.settings.manage'], 'Two-factor, email verification and session rules.'],
            ['admin.branding', 'Branding', 'swatch', ['platform.settings.manage'], 'Platform name, colours, logo and support contacts.'],
            ['admin.landing-page', 'Landing page', 'globe-alt', ['platform.settings.manage'], 'Edit the public page: hero, network, features and app screens.'],
            ['admin.audit', 'Audit log', 'clipboard-document-list', ['platform.audit.view'], 'Platform-wide audit trail.'],
            ['admin.documentation', 'Documentation', 'book-open', ['platform.documentation.view'], 'Setup, administration, user guide, flows, API and integration: read or download as PDF.'],
        ]],
    ];

    /** Menu groups in display order (sections are listed under their group). */
    public const GROUPS = ['Supply chain', 'Commerce', 'Field & CRM', 'Finance & insight', 'Connect'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EntitlementService $entitlements,
    ) {}

    /** [section key, page] for a route name, or null. */
    public static function find(?string $route): ?array
    {
        foreach (self::SECTIONS as $key => $section) {
            foreach ($section[5] as $page) {
                if ($page[0] === $route) {
                    return [$key, $page];
                }
            }
        }

        return null;
    }

    /**
     * The sections and pages this user may open, for the given organization.
     *
     * @return array<string, array{label: string, icon: string, group: string, pages: list<array{route: string, label: string, icon: string, description: string}>}>
     */
    public function visible(User $user, ?Organization $org, ?OrganizationMembership $membership): array
    {
        $usable = $org !== null && $org->isActive();
        $out = [];

        foreach (self::SECTIONS as $key => [$label, $icon, $group, $modules, $scope, $pages]) {
            if ($scope === 'tenant' && ! $usable) {
                continue;
            }
            if ($modules !== null && ! $this->moduleUsable($org, $modules)) {
                continue;
            }

            $check = fn (array $abilities) => $abilities === [] || Gate::forUser($user)->any($abilities);
            $allowed = $scope === 'platform' || ! $usable
                ? fn (array $abilities) => $check($abilities)
                : fn (array $abilities) => $this->tenant->runAs($org, fn () => $check($abilities), $membership);

            $visible = [];
            foreach ($pages as $page) {
                [$route, $pageLabel, $pageIcon, $abilities, $description] = $page;
                if (isset($page[5]) && ! $this->moduleUsable($org, $page[5])) {
                    continue;
                }
                if (Route::has($route) && $allowed($abilities)) {
                    $visible[] = ['route' => $route, 'label' => $pageLabel, 'icon' => $pageIcon, 'description' => $description];
                }
            }

            if ($visible !== []) {
                $out[$key] = ['label' => $label, 'icon' => $icon, 'group' => $group, 'pages' => $visible];
            }
        }

        return $out;
    }

    private function moduleUsable(?Organization $org, array $modules): bool
    {
        foreach ($modules as $module) {
            if ($org?->hasModule($module) && $this->entitlements->allows($org, "module.{$module}")) {
                return true;
            }
        }

        return false;
    }
}
