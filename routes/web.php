<?php

use App\Http\Controllers\Api\V1\AccountSecurityController;
use App\Http\Controllers\Web\AccountPagesController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DocumentationDownloadController;
use App\Http\Controllers\Web\LandingPageController;
use App\Http\Controllers\Web\PosCatalogueController;
use App\Http\Controllers\Web\PosReceiptController;
use App\Http\Controllers\Web\SessionController;
use App\Http\Controllers\Web\TraceScanPageController;
use App\Livewire\Account\SecuritySettings;
use App\Livewire\Admin;
use App\Livewire\Admin\DocumentationCenter;
use App\Livewire\Admin\LandingPageEditor;
use App\Livewire\Admin\VerificationQueue;
use App\Livewire\Analytics;
use App\Livewire\Analytics\ExecutiveDashboard;
use App\Livewire\Catalogue;
use App\Livewire\Catalogue\ProductBrowser;
use App\Livewire\Compliance;
use App\Livewire\Crm;
use App\Livewire\FieldForce;
use App\Livewire\FieldForce\SupervisorDashboard;
use App\Livewire\Finance;
use App\Livewire\Integration;
use App\Livewire\Inventory;
use App\Livewire\Logistics;
use App\Livewire\Marketplace;
use App\Livewire\Onboarding;
use App\Livewire\Organization;
use App\Livewire\Parties;
use App\Livewire\Pos;
use App\Livewire\Procurement;
use App\Livewire\Sales;
use App\Livewire\Sync;
use App\Livewire\Traceability;
use Illuminate\Support\Facades\Route;

// Public landing page (content edited at /admin/landing-page) and the media uploaded for it.
Route::get('/', [LandingPageController::class, 'show'])->name('landing');
Route::get('landing-media/{file}', [LandingPageController::class, 'media'])->where('file', '[a-z0-9]{32}\.[a-z0-9]{2,5}')->name('landing.media');

// QR codes point here: a public page showing what is recorded about the pack (spec §16.3).
Route::get('t/{token}', TraceScanPageController::class)->where('token', '[A-Za-z0-9]{22}')->middleware('throttle:'.config('traceability.public_scan_rate_per_minute').',1')->name('trace.page');

Route::middleware('guest')->group(function () {
    Route::get('login', [SessionController::class, 'create'])->name('login');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:auth')->name('login.store');
    Route::get('login/two-factor', [SessionController::class, 'showMfaChallenge'])->name('login.mfa');
    Route::post('login/two-factor', [SessionController::class, 'verifyMfaChallenge'])->middleware('throttle:10,1')->name('login.mfa.verify');

    Route::get('register', [AccountPagesController::class, 'showRegister'])->name('register');
    Route::post('register', [AccountPagesController::class, 'register'])->middleware('throttle:auth')->name('register.store');
    Route::get('forgot-password', [AccountPagesController::class, 'showForgot'])->name('password.request');
    Route::post('forgot-password', [AccountPagesController::class, 'forgot'])->middleware('throttle:6,1')->name('password.email');
    Route::get('reset-password/{token}', [AccountPagesController::class, 'showReset'])->name('password.reset');
    Route::post('reset-password', [AccountPagesController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');
});

// Signed link from the verification email; works without a session.
Route::get('email/verify/{id}/{hash}', [AccountSecurityController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1'])
    ->whereNumber('id')
    ->name('verification.verify');

Route::middleware(['auth', 'active', 'secure'])->group(function () {
    Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
    Route::get('account/security', SecuritySettings::class)->name('account.security');

    Route::get('dashboard', [DashboardController::class, 'show'])->middleware('tenant:optional')->name('dashboard');
    Route::post('organizations/switch', [DashboardController::class, 'switchOrganization'])->name('organizations.switch');
    Route::get('onboarding/organization', Onboarding\CreateOrganization::class)->name('onboarding.organization');
    Route::get('invitations/accept', Onboarding\AcceptInvitation::class)->name('invitations.accept');

    Route::get('catalogue', ProductBrowser::class)->name('catalogue');

    // Tenant pages: the organization chosen in the switcher, re-validated on every request (and on Livewire updates).
    // Organization settings (every organization, no module needed).
    Route::middleware('tenant')->prefix('organization')->name('organization.')->group(function () {
        Route::get('profile', Organization\Profile::class)->name('profile');
        Route::get('locations', Organization\Locations::class)->name('locations');
        Route::get('members', Organization\Members::class)->name('members');
        Route::get('subscription', Organization\Subscription::class)->name('subscription');
        Route::get('audit-log', Organization\AuditTrail::class)->name('audit');
        Route::get('devices', Organization\Devices::class)->name('devices');
    });

    Route::middleware('tenant')->group(function () {
        Route::get('catalogue/items', Catalogue\Items::class)->middleware('module:catalogue')->name('catalogue.items');
        Route::get('catalogue/change-requests', Catalogue\ChangeRequests::class)->name('catalogue.change-requests');
        Route::get('customers', Parties\Customers::class)->middleware('module:sales|pos|field_force')->name('parties.customers');
        Route::get('suppliers', Parties\Suppliers::class)->middleware('module:procurement|inventory')->name('parties.suppliers');
    });

    Route::middleware(['tenant', 'module:inventory|pos'])->prefix('inventory')->name('inventory.')->group(function () {
        Route::get('stock', Inventory\Stock::class)->name('stock');
        Route::get('batches', Inventory\Batches::class)->name('batches');
        Route::get('movements', Inventory\Movements::class)->name('movements');
        Route::get('receive', Inventory\Receipts::class)->name('receipts');
        Route::get('adjustments', Inventory\Adjustments::class)->name('adjustments');
        Route::get('counts', Inventory\Counts::class)->name('counts');
        Route::get('transfers', Inventory\Transfers::class)->name('transfers');
        Route::get('settings', Inventory\Settings::class)->name('settings');
    });

    Route::middleware(['tenant', 'module:procurement'])->prefix('procurement')->name('procurement.')->group(function () {
        Route::get('requisitions', Procurement\Requisitions::class)->name('requisitions');
        Route::get('orders', Procurement\PurchaseOrders::class)->name('orders');
        Route::get('receipts', Procurement\GoodsReceipts::class)->name('receipts');
        Route::get('invoices', Procurement\SupplierInvoices::class)->name('invoices');
        Route::get('returns', Procurement\SupplierReturns::class)->name('returns');
        Route::get('suggestions', Procurement\Suggestions::class)->name('suggestions');
        Route::get('settings', Procurement\Settings::class)->name('settings');
    });

    Route::middleware(['tenant', 'module:sales'])->prefix('sales')->name('sales.')->group(function () {
        Route::get('orders', Sales\SalesOrders::class)->name('orders');
        Route::get('quotations', Sales\Quotations::class)->name('quotations');
        Route::get('invoices', Sales\Invoices::class)->name('invoices');
        Route::get('payments', Sales\Payments::class)->name('payments');
        Route::get('aging', Sales\Aging::class)->name('aging');
        Route::get('price-lists', Sales\PriceLists::class)->name('price-lists');
        Route::get('settings', Sales\Settings::class)->name('settings');
    });

    Route::middleware(['tenant', 'module:finance'])->prefix('finance')->name('finance.')->group(function () {
        Route::get('reports', Finance\Reports::class)->name('reports');
        Route::get('journals', Finance\Journals::class)->name('journals');
        Route::get('payments', Finance\SupplierPayments::class)->name('payments');
        Route::get('accounts', Finance\Accounts::class)->name('accounts');
        Route::get('periods', Finance\Periods::class)->name('periods');
    });

    Route::middleware(['tenant', 'module:marketplace'])->prefix('marketplace')->name('marketplace.')->group(function () {
        Route::get('/', Marketplace\Discover::class)->name('discover');
        Route::get('orders', Marketplace\Orders::class)->name('orders');
        Route::get('rfqs', Marketplace\Rfqs::class)->name('rfqs');
        Route::get('quotes', Marketplace\Quotes::class)->name('quotes');
        Route::get('listings', Marketplace\Listings::class)->name('listings');
        Route::get('partners', Marketplace\Relationships::class)->name('relationships');
        Route::get('disputes', Marketplace\Disputes::class)->name('disputes');
        Route::get('fees', Marketplace\Fees::class)->name('fees');
        Route::get('profile', Marketplace\Profile::class)->name('profile');
    });

    Route::middleware(['tenant', 'module:logistics|inventory'])->prefix('logistics')->name('logistics.')->group(function () {
        Route::get('shipments', Logistics\Shipments::class)->name('shipments');
        Route::get('vehicles', Logistics\Vehicles::class)->name('vehicles');
        Route::get('reconciliation', Logistics\Reconciliation::class)->name('reconciliation');
    });

    Route::middleware(['tenant', 'module:integrations'])->prefix('integrations')->name('integration.')->group(function () {
        Route::get('connectors', Integration\Connectors::class)->name('connectors');
        Route::get('messages', Integration\Messages::class)->name('messages');
        Route::get('runs', Integration\Runs::class)->name('runs');
        Route::get('webhooks', Integration\Webhooks::class)->name('webhooks');
        Route::get('references', Integration\References::class)->name('references');
        Route::get('health', Integration\Health::class)->name('health');
    });

    Route::get('sync', Sync\Overview::class)->middleware('tenant')->name('sync.overview');

    // Traceability and recalls are shared platform services: no module needed.
    Route::middleware('tenant')->group(function () {
        Route::get('trace/verify', Traceability\Verify::class)->name('trace.verify');
        Route::get('trace/identifiers', Traceability\Identifiers::class)->name('trace.identifiers');
        Route::get('trace/events', Traceability\Events::class)->name('trace.events');
        Route::get('trace/lots', Traceability\Lots::class)->name('trace.lots');
        Route::get('trace/integrity', Traceability\Integrity::class)->name('trace.integrity');
        Route::get('recalls', Compliance\Recalls::class)->name('recalls.index');
        Route::get('recalls/notices', Compliance\Notices::class)->name('recalls.notices');
        Route::get('regulatory-incidents', Compliance\Incidents::class)->name('recalls.incidents');
    });

    Route::middleware(['tenant', 'module:pos'])->group(function () {
        // Desktop POS terminal (full screen); its product list loads from the terminal's catalogue.
        Route::get('pos', Pos\Terminal::class)->name('pos.register');
        Route::get('pos/terminals/{terminal}/catalogue', PosCatalogueController::class)->whereUlid('terminal')->name('pos.catalogue');
        Route::get('pos/receipts/{sale}', PosReceiptController::class)->whereUlid('sale')->name('pos.receipt');
        Route::get('pos/terminals', Pos\Terminals::class)->name('pos.terminals');
        Route::get('pos/shifts', Pos\Shifts::class)->name('pos.shifts');
        Route::get('pos/refunds', Pos\Refunds::class)->name('pos.refunds');
        Route::get('pos/pending-payments', Pos\PendingPayments::class)->name('pos.payments');
        Route::get('pos/reports', Pos\Reports::class)->name('pos.reports');
    });

    Route::middleware(['tenant', 'module:field_force'])->group(function () {
        Route::get('field-force/dashboard', SupervisorDashboard::class)->name('field-force.dashboard');
        Route::get('field-force/territories', FieldForce\Territories::class)->name('field-force.territories');
        Route::get('field-force/visits', FieldForce\Visits::class)->name('field-force.visits');
        Route::get('field-force/orders', FieldForce\Orders::class)->name('field-force.orders');
        Route::get('field-force/follow-ups', FieldForce\FollowUps::class)->name('field-force.follow-ups');
        Route::get('field-force/activity', FieldForce\Activity::class)->name('field-force.activity');

        Route::prefix('crm')->name('crm.')->group(function () {
            Route::get('accounts', Crm\Accounts::class)->name('accounts');
            Route::get('plans', Crm\Plans::class)->name('plans');
            Route::get('opportunities', Crm\Opportunities::class)->name('opportunities');
            Route::get('targets', Crm\Targets::class)->name('targets');
            Route::get('interactions', Crm\Interactions::class)->name('interactions');
            Route::get('feedback', Crm\Feedback::class)->name('feedback');
            Route::get('detailing', Crm\Detailing::class)->name('detailing');
            Route::get('segments', Crm\Segments::class)->name('segments');
            Route::get('analytics', Crm\Analytics::class)->name('analytics');
            Route::get('automation', Crm\Automation::class)->name('automation');
        });
    });

    Route::middleware(['tenant', 'module:analytics'])->group(function () {
        Route::get('analytics', ExecutiveDashboard::class)->name('analytics.executive');
        Route::get('analytics/dashboards', Analytics\Dashboards::class)->name('analytics.dashboards');
        Route::get('analytics/forecast', Analytics\Forecast::class)->name('analytics.forecast');
        Route::get('analytics/recommendations', Analytics\Recommendations::class)->name('analytics.recommendations');
        Route::get('analytics/assistant', Analytics\Assistant::class)->name('analytics.assistant');
    });

    Route::get('admin/documentation', DocumentationCenter::class)->middleware('can:platform.documentation.view')->name('admin.documentation');
    Route::get('admin/documentation/pdf', DocumentationDownloadController::class)->name('admin.documentation.pdf.all');
    Route::get('admin/documentation/pdf/{slug}', DocumentationDownloadController::class)->where('slug', '[a-z-]+')->name('admin.documentation.pdf');

    Route::get('admin/landing-page', LandingPageEditor::class)->middleware('can:platform.settings.manage')->name('admin.landing-page');

    Route::get('admin/verifications', VerificationQueue::class)
        ->middleware('can:platform.organizations.view')
        ->name('admin.verifications');

    // Platform administration: each screen checks its own platform permissions (App\Livewire\WebPage).
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('organizations', Admin\Organizations::class)->name('organizations');
        Route::get('users', Admin\Users::class)->name('users');
        Route::get('catalogue', Admin\Catalogue::class)->name('catalogue');
        Route::get('recalls', Admin\Recalls::class)->name('recalls');
        Route::get('traceability', Admin\Traceability::class)->name('trace');
        Route::get('marketplace', Admin\Marketplace::class)->name('marketplace');
        Route::get('countries', Admin\Countries::class)->name('countries');
        Route::get('exchange-rates', Admin\ExchangeRates::class)->name('exchange-rates');
        Route::get('plans', Admin\Plans::class)->name('plans');
        Route::get('analytics', Admin\Analytics::class)->name('analytics');
        Route::get('operations', Admin\Operations::class)->name('operations');
        Route::get('security', Admin\Security::class)->name('security');
        Route::get('branding', Admin\Branding::class)->name('branding');
        Route::get('audit', Admin\AuditLog::class)->name('audit');
    });
});
