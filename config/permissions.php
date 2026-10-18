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
            ]],
            'finance_manager' => ['Finance manager', ['organization.view', 'locations.view', 'tenant_products.view', 'audit.view', 'organization.billing.view', 'suppliers.view', 'customers.view', 'customers.manage']],
            'accountant' => ['Accountant', ['organization.view', 'locations.view', 'tenant_products.view', 'suppliers.view', 'customers.view']],
            'inventory_manager' => ['Inventory manager', ['organization.view', 'locations.view', 'tenant_products.view', 'tenant_products.manage', 'catalogue.propose', 'suppliers.view']],
            'warehouse_officer' => ['Warehouse officer', ['organization.view', 'locations.view', 'tenant_products.view', 'suppliers.view']],
            'procurement_officer' => ['Procurement officer', ['organization.view', 'locations.view', 'tenant_products.view', 'suppliers.view', 'suppliers.manage']],
            'sales_manager' => ['Sales manager', ['organization.view', 'locations.view', 'tenant_products.view', 'tenant_products.manage', 'customers.view', 'customers.manage']],
            // Customer visibility is narrowed to assigned territories in Phase 2.6.
            'sales_representative' => ['Sales representative', ['organization.view', 'tenant_products.view', 'customers.view']],
            'pharmacist' => ['Pharmacist / authorized dispensary user', ['organization.view', 'locations.view', 'tenant_products.view', 'customers.view']],
            'pos_cashier' => ['POS cashier', ['organization.view', 'tenant_products.view', 'customers.view']],
            'pos_supervisor' => ['POS supervisor', ['organization.view', 'locations.view', 'tenant_products.view', 'customers.view', 'customers.manage']],
            'hospital_procurement_officer' => ['Hospital procurement officer', ['organization.view', 'locations.view', 'tenant_products.view', 'suppliers.view', 'suppliers.manage']],
            'logistics_coordinator' => ['Logistics coordinator', ['organization.view', 'locations.view', 'customers.view']],
            'driver' => ['Driver / delivery officer', ['organization.view']],
            'integration_admin' => ['Integration administrator', ['organization.view', 'tenant_products.view']],
            'auditor' => ['Auditor (read-only)', ['organization.view', 'locations.view', 'tenant_products.view', 'audit.view', 'suppliers.view', 'customers.view']],
        ],
    ],
];
