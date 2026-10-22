<?php

use App\Http\Controllers\Api\V1\AccountSecurityController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DocumentationDownloadController;
use App\Http\Controllers\Web\LandingPageController;
use App\Http\Controllers\Web\PosReceiptController;
use App\Http\Controllers\Web\SessionController;
use App\Http\Controllers\Web\TraceScanPageController;
use App\Livewire\Account\SecuritySettings;
use App\Livewire\Admin\DocumentationCenter;
use App\Livewire\Admin\LandingPageEditor;
use App\Livewire\Admin\VerificationQueue;
use App\Livewire\Analytics\ExecutiveDashboard;
use App\Livewire\Catalogue;
use App\Livewire\Catalogue\ProductBrowser;
use App\Livewire\FieldForce\SupervisorDashboard;
use App\Livewire\Finance;
use App\Livewire\Inventory;
use App\Livewire\Organization;
use App\Livewire\Parties;
use App\Livewire\Pos\Register;
use App\Livewire\Procurement;
use App\Livewire\Sales;
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

    Route::middleware(['tenant', 'module:pos'])->group(function () {
        Route::get('pos', Register::class)->name('pos.register');
        Route::get('pos/receipts/{sale}', PosReceiptController::class)->whereUlid('sale')->name('pos.receipt');
    });

    Route::middleware(['tenant', 'module:field_force'])->group(function () {
        Route::get('field-force/dashboard', SupervisorDashboard::class)->name('field-force.dashboard');
    });

    Route::middleware(['tenant', 'module:analytics'])->group(function () {
        Route::get('analytics', ExecutiveDashboard::class)->name('analytics.executive');
    });

    Route::get('admin/documentation', DocumentationCenter::class)->middleware('can:platform.documentation.view')->name('admin.documentation');
    Route::get('admin/documentation/pdf', DocumentationDownloadController::class)->name('admin.documentation.pdf.all');
    Route::get('admin/documentation/pdf/{slug}', DocumentationDownloadController::class)->where('slug', '[a-z-]+')->name('admin.documentation.pdf');

    Route::get('admin/landing-page', LandingPageEditor::class)->middleware('can:platform.settings.manage')->name('admin.landing-page');

    Route::get('admin/verifications', VerificationQueue::class)
        ->middleware('can:platform.organizations.view')
        ->name('admin.verifications');
});
