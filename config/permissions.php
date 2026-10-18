<?php

/*
|--------------------------------------------------------------------------
| Permission registry and system role templates
|--------------------------------------------------------------------------
|
| Every permission has a scope:
|   platform      - held through a platform role; never grants tenant data.
|   organization  - held through a membership role and evaluated against
|                   the organization in the current TenantContext.
|
| System roles are seeded by RolesAndPermissionsSeeder. Organizations may
| create custom roles from organization-scope permissions only.
| Later phases append their module permissions here.
|
*/

return [

    'permissions' => [
        // Platform
        'platform.organizations.view' => ['platform', 'View organizations and their verification state'],
        'platform.organizations.verify' => ['platform', 'Approve, reject or request changes to organization verification'],
        'platform.organizations.suspend' => ['platform', 'Suspend and reactivate organizations'],
        'platform.organizations.modules' => ['platform', 'Enable or disable organization modules'],
        'platform.users.manage' => ['platform', 'Suspend and reactivate user accounts'],
        'platform.settings.manage' => ['platform', 'Manage platform settings and branding'],
        'platform.audit.view' => ['platform', 'View platform-level audit events'],
        'platform.billing.manage' => ['platform', 'Manage plans, organization subscriptions and entitlement overrides'],
        'platform.operations.manage' => ['platform', 'View system health and retry failed jobs'],
        'catalogue.manage' => ['platform', 'Create and edit canonical products'],
        'catalogue.review' => ['platform', 'Approve or reject product change requests'],
        'regulatory.verify' => ['platform', 'Record regulatory identifier verification results'],

        // Organization
        'organization.view' => ['organization', 'View organization profile'],
        'organization.manage' => ['organization', 'Edit organization profile, documents and verification submission'],
        'organization.members.manage' => ['organization', 'Invite members and change their roles'],
        'organization.roles.manage' => ['organization', 'Create and edit custom roles'],
        'locations.view' => ['organization', 'View branches and warehouses'],
        'locations.manage' => ['organization', 'Create and edit branches and warehouses'],
        'catalogue.propose' => ['organization', 'Propose new canonical products or changes'],
        'tenant_products.view' => ['organization', 'View the organization product list'],
        'tenant_products.manage' => ['organization', 'Manage SKUs, prices and reorder settings'],
        'audit.view' => ['organization', 'View the organization audit trail'],
        'organization.billing.view' => ['organization', 'View the subscription, entitlements and usage'],
        'suppliers.view' => ['organization', 'View suppliers and their contacts'],
        'suppliers.manage' => ['organization', 'Create and edit suppliers'],
        'customers.view' => ['organization', 'View customers and their contacts'],
        'customers.manage' => ['organization', 'Create and edit customers'],
        'inventory.view' => ['organization', 'View stock levels, batches and the movement ledger'],
        'inventory.receive' => ['organization', 'Receive stock into warehouses'],
        'inventory.adjust' => ['organization', 'Post stock adjustments and write-offs'],
        'inventory.transfer' => ['organization', 'Create, dispatch and receive stock transfers'],
        'inventory.count' => ['organization', 'Run and post stock counts'],
        'inventory.quality' => ['organization', 'Quarantine, release or reject batches'],
        'inventory.valuation.view' => ['organization', 'View stock costs and valuation'],
        'inventory.settings.manage' => ['organization', 'Change inventory policy (negative stock, minimum shelf life) and bins'],
        'procurement.view' => ['organization', 'View requisitions, purchase orders, receipts, supplier invoices and returns'],
        'procurement.requisition' => ['organization', 'Raise purchase requisitions'],
        'procurement.order' => ['organization', 'Create, edit, submit, send and cancel purchase orders; maintain supplier price lists'],
        'procurement.approve' => ['organization', 'Approve or reject requisitions, purchase orders and supplier invoices'],
        'procurement.invoice' => ['organization', 'Record supplier invoices'],
        'procurement.return' => ['organization', 'Post returns to suppliers'],
        'procurement.settings.manage' => ['organization', 'Change approval threshold and receipt tolerance'],
        'finance.view' => ['organization', 'View accounts, journals, payments and financial reports'],
        'finance.accounts.manage' => ['organization', 'Maintain the chart of accounts'],
        'finance.journal.post' => ['organization', 'Post and reverse manual journal entries'],
        'finance.periods.manage' => ['organization', 'Close and reopen financial periods'],
        'finance.payments.record' => ['organization', 'Record and allocate payments'],
        'sales.view' => ['organization', 'View quotations, orders, deliveries, invoices, credit notes and receivables'],
        'sales.quote' => ['organization', 'Create quotations'],
        'sales.order' => ['organization', 'Create, confirm, cancel and close sales orders'],
        'sales.approve' => ['organization', 'Approve orders held for discount or credit limit'],
        'sales.deliver' => ['organization', 'Post deliveries against sales orders'],
        'sales.invoice' => ['organization', 'Issue sales invoices'],
        'sales.credit' => ['organization', 'Issue credit notes and accept returns'],
        'sales.price.override' => ['organization', 'Sell at a price different from the list price'],
        'sales.returns.restock' => ['organization', 'Return goods directly to sellable stock'],
        'sales.price_lists.manage' => ['organization', 'Maintain price lists'],
        'sales.settings.manage' => ['organization', 'Change discount limit and approval settings'],
        'pos.sell' => ['organization', 'Open own shift and sell at a POS terminal'],
        'pos.cash.manage' => ['organization', 'Put cash into or take cash out of a drawer'],
        'pos.shift.review' => ['organization', 'Review and sign off closed shifts'],
        'pos.payments.confirm' => ['organization', 'Confirm standalone-terminal card and transfer payments after settlement check'],
        'pos.refund.request' => ['organization', 'Request refunds on completed sales'],
        'pos.refund.approve' => ['organization', 'Approve or reject refunds'],
        'pos.sale.cancel' => ['organization', 'Cancel sales that already have payments'],
        'pos.dispense.prescription' => ['organization', 'Sell prescription-only and controlled products (with prescription details)'],
        'pos.terminals.manage' => ['organization', 'Register and disable POS terminals'],
        'pos.reports.view' => ['organization', 'View POS sales, shift and cashier reports'],
    ],

    'roles' => [
        'platform' => [
            'platform_super_admin' => ['Platform super administrator', '*'],
            'platform_operations_admin' => ['Platform operations administrator', [
                'platform.organizations.view', 'platform.organizations.suspend', 'platform.organizations.modules',
                'platform.users.manage', 'platform.audit.view', 'catalogue.manage', 'platform.billing.manage', 'platform.operations.manage',
            ]],
            'compliance_officer' => ['Compliance / verification officer', [
                'platform.organizations.view', 'platform.organizations.verify', 'catalogue.review', 'regulatory.verify', 'platform.audit.view',
            ]],
        ],
        'organization' => [
            'org_owner' => ['Organization owner', '*'],
            'org_admin' => ['Organization administrator', [
                'organization.view', 'organization.manage', 'organization.members.manage', 'organization.roles.manage',
                'locations.view', 'locations.manage', 'catalogue.propose', 'tenant_products.view', 'tenant_products.manage', 'audit.view',
                'organization.billing.view', 'suppliers.view', 'suppliers.manage', 'customers.view', 'customers.manage',
                'inventory.view', 'inventory.receive', 'inventory.adjust', 'inventory.transfer', 'inventory.count', 'inventory.quality', 'inventory.valuation.view', 'inventory.settings.manage',
                'procurement.view', 'procurement.requisition', 'procurement.order', 'procurement.approve', 'procurement.invoice', 'procurement.return', 'procurement.settings.manage',
                'finance.view', 'finance.accounts.manage', 'finance.journal.post', 'finance.periods.manage', 'finance.payments.record',
                'sales.view', 'sales.quote', 'sales.order', 'sales.approve', 'sales.deliver', 'sales.invoice', 'sales.credit', 'sales.price.override', 'sales.returns.restock', 'sales.price_lists.manage', 'sales.settings.manage',
                'pos.sell', 'pos.cash.manage', 'pos.shift.review', 'pos.payments.confirm', 'pos.refund.request', 'pos.refund.approve', 'pos.sale.cancel', 'pos.dispense.prescription', 'pos.terminals.manage', 'pos.reports.view',
            ]],
            'finance_manager' => ['Finance manager', ['organization.view', 'locations.view', 'tenant_products.view', 'audit.view', 'organization.billing.view', 'suppliers.view', 'customers.view', 'customers.manage', 'inventory.view', 'inventory.valuation.view', 'procurement.view', 'procurement.approve', 'procurement.invoice', 'finance.view', 'finance.accounts.manage', 'finance.journal.post', 'finance.periods.manage', 'finance.payments.record', 'sales.view', 'sales.approve', 'sales.invoice', 'sales.credit', 'pos.shift.review', 'pos.payments.confirm', 'pos.reports.view']],
            'accountant' => ['Accountant', ['organization.view', 'locations.view', 'tenant_products.view', 'suppliers.view', 'customers.view', 'inventory.view', 'inventory.valuation.view', 'procurement.view', 'procurement.invoice', 'finance.view', 'finance.journal.post', 'finance.payments.record', 'sales.view', 'sales.invoice', 'pos.reports.view']],
            'inventory_manager' => ['Inventory manager', ['organization.view', 'locations.view', 'tenant_products.view', 'tenant_products.manage', 'catalogue.propose', 'suppliers.view', 'inventory.view', 'inventory.receive', 'inventory.adjust', 'inventory.transfer', 'inventory.count', 'inventory.quality', 'inventory.valuation.view', 'inventory.settings.manage', 'procurement.view', 'procurement.requisition', 'sales.view', 'sales.deliver']],
            'warehouse_officer' => ['Warehouse officer', ['organization.view', 'locations.view', 'tenant_products.view', 'suppliers.view', 'inventory.view', 'inventory.receive', 'inventory.transfer', 'inventory.count', 'procurement.view', 'sales.view', 'sales.deliver']],
            'procurement_officer' => ['Procurement officer', ['organization.view', 'locations.view', 'tenant_products.view', 'suppliers.view', 'suppliers.manage', 'inventory.view', 'inventory.receive', 'procurement.view', 'procurement.requisition', 'procurement.order', 'procurement.invoice', 'procurement.return']],
            'sales_manager' => ['Sales manager', ['organization.view', 'locations.view', 'tenant_products.view', 'tenant_products.manage', 'customers.view', 'customers.manage', 'inventory.view', 'sales.view', 'sales.quote', 'sales.order', 'sales.approve', 'sales.invoice', 'sales.credit', 'sales.price.override', 'sales.price_lists.manage', 'sales.settings.manage', 'finance.payments.record', 'pos.reports.view']],
            // Customer visibility is narrowed to assigned territories in Phase 2.6.
            'sales_representative' => ['Sales representative', ['organization.view', 'tenant_products.view', 'customers.view', 'inventory.view', 'sales.view', 'sales.quote', 'sales.order']],
            'pharmacist' => ['Pharmacist / authorized dispensary user', ['organization.view', 'locations.view', 'tenant_products.view', 'customers.view', 'inventory.view', 'inventory.receive', 'inventory.quality', 'inventory.count', 'procurement.view', 'procurement.requisition', 'sales.view', 'pos.sell', 'pos.refund.request', 'pos.dispense.prescription']],
            'pos_cashier' => ['POS cashier', ['organization.view', 'tenant_products.view', 'customers.view', 'inventory.view', 'pos.sell', 'pos.refund.request']],
            'pos_supervisor' => ['POS supervisor', ['organization.view', 'locations.view', 'tenant_products.view', 'customers.view', 'customers.manage', 'inventory.view', 'inventory.count', 'sales.view', 'sales.credit', 'pos.sell', 'pos.cash.manage', 'pos.shift.review', 'pos.payments.confirm', 'pos.refund.request', 'pos.refund.approve', 'pos.sale.cancel', 'pos.reports.view', 'sales.approve', 'sales.price.override']],
            'hospital_procurement_officer' => ['Hospital procurement officer', ['organization.view', 'locations.view', 'tenant_products.view', 'suppliers.view', 'suppliers.manage', 'inventory.view', 'inventory.receive', 'procurement.view', 'procurement.requisition', 'procurement.order', 'procurement.invoice', 'procurement.return']],
            'logistics_coordinator' => ['Logistics coordinator', ['organization.view', 'locations.view', 'customers.view', 'inventory.view', 'inventory.transfer']],
            'driver' => ['Driver / delivery officer', ['organization.view']],
            'integration_admin' => ['Integration administrator', ['organization.view', 'tenant_products.view']],
            'auditor' => ['Auditor (read-only)', ['organization.view', 'locations.view', 'tenant_products.view', 'audit.view', 'suppliers.view', 'customers.view', 'inventory.view', 'inventory.valuation.view', 'procurement.view', 'finance.view', 'sales.view', 'pos.reports.view']],
        ],
    ],
];
