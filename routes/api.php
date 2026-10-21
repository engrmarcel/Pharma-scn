<?php

use App\Http\Controllers\Api\V1\AccountSecurityController;
use App\Http\Controllers\Api\V1\Admin\CatalogueAdminController;
use App\Http\Controllers\Api\V1\Admin\MarketplaceAdminController;
use App\Http\Controllers\Api\V1\Admin\OperationsController;
use App\Http\Controllers\Api\V1\Admin\OrganizationAdminController;
use App\Http\Controllers\Api\V1\Admin\PlatformAdminController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BatchController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\FieldForceController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\IntegrationInboundController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\MarketplaceController;
use App\Http\Controllers\Api\V1\MarketplaceFulfilmentController;
use App\Http\Controllers\Api\V1\MarketplaceTradingController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationProductController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\ProcurementController;
use App\Http\Controllers\Api\V1\ProductChangeRequestController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReferenceDataController;
use App\Http\Controllers\Api\V1\SalesController;
use App\Http\Controllers\Api\V1\StockDocumentController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SyncController;
use Illuminate\Support\Facades\Route;

/*
| Versioned REST API. Route groups:
|   public         - no authentication
|   authenticated  - auth:sanctum + active account, no tenant
|   tenant         - + X-Organization-Id resolved against the user's memberships
|   admin          - platform permissions; never a tenant context
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // Public
    Route::get('branding', [ReferenceDataController::class, 'branding'])->name('branding');
    Route::get('health', [OperationsController::class, 'health'])->middleware('throttle:30,1')->name('health');
    // Provider callbacks: authenticated by the provider's signature, not by a user.
    // External systems (spec §15): authenticated by connector key, rate-limited per connector; never a user session.
    Route::middleware('connector')->prefix('integration/inbound')->name('integration.inbound.')->group(function () {
        Route::get('ping', [IntegrationInboundController::class, 'ping'])->name('ping');
        Route::post('messages', [IntegrationInboundController::class, 'store'])->name('messages.store');
        Route::get('messages/{messageId}', [IntegrationInboundController::class, 'message'])->where('messageId', '[A-Za-z0-9._:\/-]{1,128}')->name('messages.show');
        Route::get('runs/{runId}', [IntegrationInboundController::class, 'run'])->whereUlid('runId')->name('runs.show');
    });

    Route::post('payments/webhooks/{provider}', PaymentWebhookController::class)->where('provider', '[a-z0-9_]+')->middleware('throttle:300,1')->name('payments.webhook');

    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('tokens', [AuthController::class, 'issueToken'])->name('auth.tokens.issue');
        Route::post('register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset');
    });

    // Authenticated, no tenant
    Route::middleware(['auth:sanctum', 'active', 'device', 'secure', 'throttle:api'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        // Reachable before the account satisfies the security policy (see EnsureAccountSecurity).
        Route::get('auth/security', [AccountSecurityController::class, 'status'])->name('auth.mfa.status');
        Route::post('auth/mfa/setup', [AccountSecurityController::class, 'setupMfa'])->middleware('throttle:10,1')->name('auth.mfa.setup');
        Route::post('auth/mfa/confirm', [AccountSecurityController::class, 'confirmMfa'])->middleware('throttle:10,1')->name('auth.mfa.confirm');
        Route::delete('auth/mfa', [AccountSecurityController::class, 'disableMfa'])->middleware('throttle:10,1')->name('auth.mfa.disable');
        Route::post('auth/mfa/recovery-codes', [AccountSecurityController::class, 'regenerateRecoveryCodes'])->middleware('throttle:10,1')->name('auth.mfa.recovery-codes');
        Route::post('auth/email/verification-notification', [AccountSecurityController::class, 'resendVerification'])->middleware('throttle:6,1')->name('auth.email.resend');
        Route::get('auth/tokens', [AuthController::class, 'tokens'])->name('auth.tokens.index');
        Route::delete('auth/tokens/current', [AuthController::class, 'logout'])->name('auth.logout');
        Route::delete('auth/tokens/{tokenId}', [AuthController::class, 'revokeToken'])->whereNumber('tokenId')->name('auth.tokens.revoke');

        Route::prefix('reference')->name('reference.')->group(function () {
            Route::get('countries', [ReferenceDataController::class, 'countries'])->name('countries');
            Route::get('organization-types', [ReferenceDataController::class, 'organizationTypes'])->name('organization-types');
            Route::get('regulatory-authorities', [ReferenceDataController::class, 'regulatoryAuthorities'])->name('regulatory-authorities');
            Route::get('catalogue', [ReferenceDataController::class, 'catalogueReference'])->name('catalogue');
        });

        Route::post('organizations', [OrganizationController::class, 'register'])->middleware('idempotent')->name('organizations.register');
        Route::post('invitations/accept', [MemberController::class, 'acceptInvitation'])->name('invitations.accept');

        // Shared canonical catalogue (read)
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->whereUlid('product')->name('products.show');
        Route::get('regulatory-identifiers/lookup', [ProductController::class, 'lookupRegulatoryIdentifier'])->name('regulatory-identifiers.lookup');

        // Tenant
        Route::middleware(['tenant', 'idempotent'])->group(function () {
            Route::get('organization', [OrganizationController::class, 'show'])->name('organization.show');
            Route::patch('organization', [OrganizationController::class, 'update'])->name('organization.update');
            Route::get('organization/documents', [OrganizationController::class, 'documents'])->name('organization.documents');
            Route::post('organization/documents', [OrganizationController::class, 'uploadDocument'])->name('organization.documents.upload');
            Route::get('organization/documents/{attachmentId}', [OrganizationController::class, 'downloadDocument'])->whereUlid('attachmentId')->name('organization.documents.download');
            Route::post('organization/verification/submit', [OrganizationController::class, 'submitVerification'])->name('organization.verification.submit');

            Route::get('members', [MemberController::class, 'index'])->name('members.index');
            Route::get('roles', [MemberController::class, 'roles'])->name('roles.index');
            Route::post('invitations', [MemberController::class, 'invite'])->name('invitations.store');
            Route::put('members/{membershipId}/roles', [MemberController::class, 'updateRoles'])->whereUlid('membershipId')->name('members.roles');
            Route::put('members/{membershipId}/status', [MemberController::class, 'updateStatus'])->whereUlid('membershipId')->name('members.status');
            Route::put('members/{membershipId}/locations', [MemberController::class, 'updateLocations'])->whereUlid('membershipId')->name('members.locations');

            Route::get('branches', [LocationController::class, 'branches'])->name('branches.index');
            Route::post('branches', [LocationController::class, 'storeBranch'])->name('branches.store');
            Route::patch('branches/{branch}', [LocationController::class, 'updateBranch'])->whereUlid('branch')->name('branches.update');
            Route::get('warehouses', [LocationController::class, 'warehouses'])->name('warehouses.index');
            Route::post('warehouses', [LocationController::class, 'storeWarehouse'])->name('warehouses.store');
            Route::patch('warehouses/{warehouse}', [LocationController::class, 'updateWarehouse'])->whereUlid('warehouse')->name('warehouses.update');

            Route::get('product-change-requests', [ProductChangeRequestController::class, 'index'])->name('product-change-requests.index');
            Route::post('product-change-requests', [ProductChangeRequestController::class, 'store'])->name('product-change-requests.store');

            Route::middleware('module:catalogue')->group(function () {
                Route::get('catalogue/items', [OrganizationProductController::class, 'index'])->name('catalogue.items.index');
                Route::post('catalogue/items', [OrganizationProductController::class, 'store'])->name('catalogue.items.store');
                Route::patch('catalogue/items/{item}', [OrganizationProductController::class, 'update'])->whereUlid('item')->name('catalogue.items.update');
            });

            Route::middleware('module:procurement|inventory')->prefix('suppliers')->name('suppliers.')->group(function () {
                Route::get('/', [SupplierController::class, 'index'])->name('index');
                Route::post('/', [SupplierController::class, 'store'])->name('store');
                Route::get('{id}', [SupplierController::class, 'show'])->whereUlid('id')->name('show');
                Route::patch('{id}', [SupplierController::class, 'update'])->whereUlid('id')->name('update');
                Route::post('{id}/contacts', [SupplierController::class, 'storeContact'])->whereUlid('id')->name('contacts.store');
                Route::delete('{id}/contacts/{contactId}', [SupplierController::class, 'destroyContact'])->whereUlid(['id', 'contactId'])->name('contacts.destroy');
            });

            Route::middleware('module:sales|pos|field_force')->prefix('customers')->name('customers.')->group(function () {
                Route::get('/', [CustomerController::class, 'index'])->name('index');
                Route::post('/', [CustomerController::class, 'store'])->name('store');
                Route::get('{id}', [CustomerController::class, 'show'])->whereUlid('id')->name('show');
                Route::patch('{id}', [CustomerController::class, 'update'])->whereUlid('id')->name('update');
                Route::post('{id}/contacts', [CustomerController::class, 'storeContact'])->whereUlid('id')->name('contacts.store');
                Route::delete('{id}/contacts/{contactId}', [CustomerController::class, 'destroyContact'])->whereUlid(['id', 'contactId'])->name('contacts.destroy');
            });

            Route::middleware('module:inventory|pos')->group(function () {
                Route::prefix('inventory')->name('inventory.')->group(function () {
                    Route::get('stock-levels', [InventoryController::class, 'stockLevels'])->name('stock-levels');
                    Route::get('balances', [InventoryController::class, 'balances'])->name('balances');
                    Route::get('movements', [InventoryController::class, 'movements'])->name('movements');
                    Route::get('expiring', [InventoryController::class, 'expiring'])->name('expiring');
                    Route::get('low-stock', [InventoryController::class, 'lowStock'])->name('low-stock');
                    Route::get('valuation', [InventoryController::class, 'valuation'])->name('valuation');
                    Route::post('allocations/preview', [InventoryController::class, 'previewAllocation'])->name('allocations.preview');
                    // Stock receipts must be retry-safe: the client must send an Idempotency-Key.
                    Route::post('receipts', [InventoryController::class, 'receive'])->middleware('idempotent:required')->name('receipts.store');
                    Route::get('settings', [InventoryController::class, 'settings'])->name('settings');
                    Route::patch('settings', [InventoryController::class, 'updateSettings'])->name('settings.update');
                });

                Route::get('warehouses/{warehouseId}/bins', [InventoryController::class, 'bins'])->whereUlid('warehouseId')->name('bins.index');
                Route::post('warehouses/{warehouseId}/bins', [InventoryController::class, 'storeBin'])->whereUlid('warehouseId')->name('bins.store');

                Route::get('batches', [BatchController::class, 'index'])->name('batches.index');
                Route::get('batches/{id}', [BatchController::class, 'show'])->whereUlid('id')->name('batches.show');
                Route::post('batches/{id}/{action}', [BatchController::class, 'changeQuality'])->whereUlid('id')->whereIn('action', ['quarantine', 'release', 'reject'])->name('batches.quality');

                Route::get('stock-adjustments', [StockDocumentController::class, 'adjustments'])->name('stock-adjustments.index');
                Route::post('stock-adjustments', [StockDocumentController::class, 'storeAdjustment'])->middleware('idempotent:required')->name('stock-adjustments.store');

                Route::get('stock-transfers', [StockDocumentController::class, 'transfers'])->name('stock-transfers.index');
                Route::post('stock-transfers', [StockDocumentController::class, 'storeTransfer'])->name('stock-transfers.store');
                Route::get('stock-transfers/{id}', [StockDocumentController::class, 'showTransfer'])->whereUlid('id')->name('stock-transfers.show');
                Route::post('stock-transfers/{id}/dispatch', [StockDocumentController::class, 'dispatchTransfer'])->whereUlid('id')->name('stock-transfers.dispatch');
                Route::post('stock-transfers/{id}/receive', [StockDocumentController::class, 'receiveTransfer'])->whereUlid('id')->middleware('idempotent:required')->name('stock-transfers.receive');
                Route::post('stock-transfers/{id}/cancel', [StockDocumentController::class, 'cancelTransfer'])->whereUlid('id')->name('stock-transfers.cancel');

                Route::get('stock-counts', [StockDocumentController::class, 'counts'])->name('stock-counts.index');
                Route::post('stock-counts', [StockDocumentController::class, 'storeCount'])->name('stock-counts.store');
                Route::put('stock-counts/{id}/lines', [StockDocumentController::class, 'recordCountLines'])->whereUlid('id')->name('stock-counts.lines');
                Route::post('stock-counts/{id}/post', [StockDocumentController::class, 'postCount'])->whereUlid('id')->name('stock-counts.post');
            });

            Route::middleware('module:procurement')->group(function () {
                Route::get('purchase-requisitions', [ProcurementController::class, 'requisitions'])->name('purchase-requisitions.index');
                Route::post('purchase-requisitions', [ProcurementController::class, 'storeRequisition'])->name('purchase-requisitions.store');
                Route::post('purchase-requisitions/{id}/submit', [ProcurementController::class, 'submitRequisition'])->whereUlid('id')->name('purchase-requisitions.submit');
                Route::post('purchase-requisitions/{id}/{decision}', [ProcurementController::class, 'decideRequisition'])->whereUlid('id')->whereIn('decision', ['approve', 'reject'])->name('purchase-requisitions.decide');
                Route::post('purchase-requisitions/{id}/convert', [ProcurementController::class, 'convertRequisition'])->whereUlid('id')->name('purchase-requisitions.convert');

                Route::get('purchase-orders', [ProcurementController::class, 'orders'])->name('purchase-orders.index');
                Route::post('purchase-orders', [ProcurementController::class, 'storeOrder'])->name('purchase-orders.store');
                Route::get('purchase-orders/{id}', [ProcurementController::class, 'showOrder'])->whereUlid('id')->name('purchase-orders.show');
                Route::patch('purchase-orders/{id}', [ProcurementController::class, 'updateOrder'])->whereUlid('id')->name('purchase-orders.update');
                Route::post('purchase-orders/{id}/{action}', [ProcurementController::class, 'orderAction'])->whereUlid('id')
                    ->whereIn('action', ['submit', 'approve', 'reject', 'send', 'cancel', 'close'])->name('purchase-orders.action');

                Route::get('goods-receipts', [ProcurementController::class, 'goodsReceipts'])->name('goods-receipts.index');
                Route::post('goods-receipts', [ProcurementController::class, 'storeGoodsReceipt'])->middleware('idempotent:required')->name('goods-receipts.store');

                Route::get('supplier-invoices', [ProcurementController::class, 'invoices'])->name('supplier-invoices.index');
                Route::post('supplier-invoices', [ProcurementController::class, 'storeInvoice'])->middleware('idempotent:required')->name('supplier-invoices.store');
                Route::post('supplier-invoices/{id}/{action}', [ProcurementController::class, 'invoiceAction'])->whereUlid('id')->whereIn('action', ['approve', 'cancel'])->name('supplier-invoices.action');

                Route::get('supplier-returns', [ProcurementController::class, 'returns'])->name('supplier-returns.index');
                Route::post('supplier-returns', [ProcurementController::class, 'storeReturn'])->middleware('idempotent:required')->name('supplier-returns.store');

                Route::get('suppliers/{supplierId}/price-list', [ProcurementController::class, 'priceList'])->whereUlid('supplierId')->name('suppliers.price-list');
                Route::put('suppliers/{supplierId}/price-list', [ProcurementController::class, 'replacePriceList'])->whereUlid('supplierId')->name('suppliers.price-list.replace');
                Route::get('suppliers/{supplierId}/performance', [ProcurementController::class, 'supplierPerformance'])->whereUlid('supplierId')->name('suppliers.performance');

                Route::get('procurement/suggestions', [ProcurementController::class, 'suggestions'])->name('procurement.suggestions');
                Route::get('procurement/settings', [ProcurementController::class, 'settings'])->name('procurement.settings');
                Route::patch('procurement/settings', [ProcurementController::class, 'updateSettings'])->name('procurement.settings.update');
            });

            // B2B marketplace (spec §14).
            Route::middleware('module:marketplace')->prefix('marketplace')->name('marketplace.')->group(function () {
                Route::get('profile', [MarketplaceController::class, 'profile'])->name('profile');
                Route::put('profile', [MarketplaceController::class, 'saveProfile'])->name('profile.save');
                Route::get('listings', [MarketplaceController::class, 'listings'])->name('listings.index');
                Route::post('listings', [MarketplaceController::class, 'storeListing'])->name('listings.store');
                Route::patch('listings/{id}', [MarketplaceController::class, 'updateListing'])->whereUlid('id')->name('listings.update');
                Route::get('discover', [MarketplaceController::class, 'discover'])->name('discover');
                Route::get('suppliers/{organizationId}', [MarketplaceController::class, 'supplier'])->whereUlid('organizationId')->name('suppliers.show');
                Route::get('relationships', [MarketplaceController::class, 'relationships'])->name('relationships.index');
                Route::post('relationships', [MarketplaceController::class, 'requestRelationship'])->name('relationships.store');
                Route::post('relationships/{id}/suspend', [MarketplaceController::class, 'suspendRelationship'])->whereUlid('id')->name('relationships.suspend');
                Route::post('relationships/{id}/{decision}', [MarketplaceController::class, 'decideRelationship'])->whereUlid('id')->whereIn('decision', ['approve', 'reject'])->name('relationships.decide');

                Route::get('rfqs', [MarketplaceTradingController::class, 'rfqs'])->name('rfqs.index');
                Route::post('rfqs', [MarketplaceTradingController::class, 'storeRfq'])->name('rfqs.store');
                Route::get('rfqs/{id}', [MarketplaceTradingController::class, 'rfq'])->whereUlid('id')->name('rfqs.show');
                Route::get('rfqs/{id}/comparison', [MarketplaceTradingController::class, 'comparison'])->whereUlid('id')->name('rfqs.comparison');
                Route::post('rfqs/{id}/quotes', [MarketplaceTradingController::class, 'quoteRfq'])->whereUlid('id')->name('rfqs.quote');
                Route::post('rfqs/{id}/decline', [MarketplaceTradingController::class, 'declineRfq'])->whereUlid('id')->name('rfqs.decline');
                Route::post('rfqs/{id}/{action}', [MarketplaceTradingController::class, 'rfqAction'])->whereUlid('id')->whereIn('action', ['open', 'close', 'cancel'])->name('rfqs.action');
                Route::get('quotes', [MarketplaceTradingController::class, 'quotes'])->name('quotes.index');
                Route::post('quotes', [MarketplaceTradingController::class, 'storeQuote'])->name('quotes.store');
                Route::post('quotes/{id}/{action}', [MarketplaceTradingController::class, 'quoteAction'])->whereUlid('id')->whereIn('action', ['accept', 'reject', 'withdraw'])->name('quotes.action');
                Route::get('orders', [MarketplaceTradingController::class, 'orders'])->name('orders.index');
                Route::post('orders', [MarketplaceTradingController::class, 'storeOrder'])->name('orders.store');
                Route::get('orders/{id}', [MarketplaceTradingController::class, 'order'])->whereUlid('id')->name('orders.show');
                Route::post('orders/{id}/{action}', [MarketplaceTradingController::class, 'orderAction'])->whereUlid('id')->whereIn('action', ['submit', 'approve', 'disapprove', 'accept', 'reject', 'cancel'])->name('orders.action');

                Route::get('orders/{id}/shipments', [MarketplaceFulfilmentController::class, 'shipments'])->whereUlid('id')->name('orders.shipments.index');
                Route::post('orders/{id}/shipments', [MarketplaceFulfilmentController::class, 'ship'])->whereUlid('id')->middleware('idempotent:required')->name('orders.shipments.store');
                Route::post('shipments/{id}/receive', [MarketplaceFulfilmentController::class, 'receive'])->whereUlid('id')->middleware('idempotent:required')->name('shipments.receive');
                Route::get('orders/{id}/invoices', [MarketplaceFulfilmentController::class, 'invoices'])->whereUlid('id')->name('orders.invoices.index');
                Route::post('orders/{id}/invoices', [MarketplaceFulfilmentController::class, 'invoice'])->whereUlid('id')->middleware('idempotent:required')->name('orders.invoices.store');
                Route::post('invoices/{id}/record', [MarketplaceFulfilmentController::class, 'recordSupplierInvoice'])->whereUlid('id')->name('invoices.record');
                Route::get('orders/{id}/history', [MarketplaceFulfilmentController::class, 'history'])->whereUlid('id')->name('orders.history');
                Route::get('fees', [MarketplaceFulfilmentController::class, 'fees'])->name('fees');
                Route::get('disputes', [MarketplaceFulfilmentController::class, 'disputes'])->name('disputes.index');
                Route::post('orders/{id}/disputes', [MarketplaceFulfilmentController::class, 'raiseDispute'])->whereUlid('id')->name('disputes.store');
                Route::get('disputes/{id}', [MarketplaceFulfilmentController::class, 'dispute'])->whereUlid('id')->name('disputes.show');
                Route::post('disputes/{id}/{action}', [MarketplaceFulfilmentController::class, 'disputeAction'])->whereUlid('id')->whereIn('action', ['messages', 'escalate', 'resolve', 'return'])->name('disputes.action');
            });

            // Enterprise integration gateway (spec §15).
            Route::middleware('module:integrations')->prefix('integration')->name('integration.')->group(function () {
                Route::get('canonical-model', [IntegrationController::class, 'canonicalModel'])->name('canonical-model');
                Route::get('health', [IntegrationController::class, 'health'])->name('health');
                Route::get('connectors', [IntegrationController::class, 'connectors'])->name('connectors.index');
                Route::post('connectors', [IntegrationController::class, 'storeConnector'])->name('connectors.store');
                Route::get('connectors/{id}', [IntegrationController::class, 'connector'])->whereUlid('id')->name('connectors.show');
                Route::patch('connectors/{id}', [IntegrationController::class, 'updateConnector'])->whereUlid('id')->name('connectors.update');
                Route::post('connectors/{id}/key', [IntegrationController::class, 'rotateKey'])->whereUlid('id')->name('connectors.key');
                Route::put('connectors/{id}/credentials', [IntegrationController::class, 'setCredentials'])->whereUlid('id')->name('connectors.credentials');
                Route::post('connectors/{id}/signing', [IntegrationController::class, 'setSigning'])->whereUlid('id')->name('connectors.signing');
                Route::post('connectors/{id}/{action}', [IntegrationController::class, 'connectorStatus'])->whereUlid('id')->whereIn('action', ['activate', 'pause', 'disable'])->name('connectors.status');
                Route::get('connectors/{id}/mappings', [IntegrationController::class, 'mappings'])->whereUlid('id')->name('connectors.mappings');
                Route::put('connectors/{id}/mappings/{entity}', [IntegrationController::class, 'replaceMappings'])->whereUlid('id')->where('entity', '[a-z_]+')->name('connectors.mappings.replace');
                Route::post('connectors/{id}/mappings/{entity}/preview', [IntegrationController::class, 'previewMappings'])->whereUlid('id')->where('entity', '[a-z_]+')->name('connectors.mappings.preview');
                Route::get('connectors/{id}/reconciliation/inventory', [IntegrationController::class, 'inventoryReconciliation'])->whereUlid('id')->name('connectors.reconciliation.inventory');
                Route::get('messages', [IntegrationController::class, 'messages'])->name('messages.index');
                Route::get('messages/{id}', [IntegrationController::class, 'message'])->whereNumber('id')->name('messages.show');
                Route::post('messages/{id}/retry', [IntegrationController::class, 'retryMessage'])->whereNumber('id')->name('messages.retry');
                Route::post('messages/{id}/ignore', [IntegrationController::class, 'ignoreMessage'])->whereNumber('id')->name('messages.ignore');
                Route::post('messages/{id}/map', [IntegrationController::class, 'mapMessage'])->whereNumber('id')->name('messages.map');
                Route::get('runs', [IntegrationController::class, 'runs'])->name('runs.index');
                Route::get('runs/{id}', [IntegrationController::class, 'run'])->whereUlid('id')->name('runs.show');
                Route::get('external-references', [IntegrationController::class, 'references'])->name('references.index');
                Route::post('external-references', [IntegrationController::class, 'storeReference'])->name('references.store');
                Route::delete('external-references/{id}', [IntegrationController::class, 'destroyReference'])->whereNumber('id')->name('references.destroy');
            });

            // Devices and offline synchronization (spec §13).
            Route::middleware('module:field_force|pos')->group(function () {
                Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
                Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
                Route::post('devices/{id}/revoke', [DeviceController::class, 'revoke'])->whereUlid('id')->name('devices.revoke');
                Route::post('sync/batches', [SyncController::class, 'batch'])->name('sync.batches');
                Route::get('sync/status', [SyncController::class, 'status'])->name('sync.status');
                Route::get('sync/inbox', [SyncController::class, 'inbox'])->name('sync.inbox');
                Route::post('sync/inbox/{id}/resolve', [SyncController::class, 'resolveInbox'])->whereNumber('id')->name('sync.inbox.resolve');
                Route::get('sync/issues', [SyncController::class, 'issues'])->name('sync.issues');
                Route::post('sync/issues/{id}/resolve', [SyncController::class, 'resolveIssue'])->whereUlid('id')->name('sync.issues.resolve');
                Route::get('sync/reconciliation', [SyncController::class, 'reconciliation'])->name('sync.reconciliation');
            });

            // Field force / light CRM (spec §12). Device records are idempotent by client_reference.
            Route::middleware('module:field_force')->prefix('field-force')->name('field-force.')->group(function () {
                Route::get('me', [FieldForceController::class, 'me'])->name('me');
                Route::get('territories', [FieldForceController::class, 'territories'])->name('territories.index');
                Route::post('territories', [FieldForceController::class, 'storeTerritory'])->name('territories.store');
                Route::patch('territories/{id}', [FieldForceController::class, 'updateTerritory'])->whereUlid('id')->name('territories.update');
                Route::put('territories/{id}/members', [FieldForceController::class, 'setTerritoryMembers'])->whereUlid('id')->name('territories.members');
                Route::put('customer-assignments', [FieldForceController::class, 'assignCustomers'])->name('customer-assignments');
                Route::get('customers', [FieldForceController::class, 'customers'])->name('customers.index');
                Route::get('customers/{id}', [FieldForceController::class, 'customer'])->whereUlid('id')->name('customers.show');
                Route::get('catalogue', [FieldForceController::class, 'catalogue'])->name('catalogue');

                Route::get('visits', [FieldForceController::class, 'visits'])->name('visits.index');
                Route::post('visits', [FieldForceController::class, 'checkIn'])->name('visits.check-in');
                Route::get('visits/{id}', [FieldForceController::class, 'visit'])->whereUlid('id')->name('visits.show');
                Route::patch('visits/{id}', [FieldForceController::class, 'updateVisit'])->whereUlid('id')->name('visits.update');
                Route::post('visits/{id}/activities', [FieldForceController::class, 'addActivities'])->whereUlid('id')->name('visits.activities');
                Route::post('visits/{id}/check-out', [FieldForceController::class, 'checkOut'])->whereUlid('id')->name('visits.check-out');
                Route::post('visits/{id}/cancel', [FieldForceController::class, 'cancelVisit'])->whereUlid('id')->name('visits.cancel');

                Route::get('orders', [FieldForceController::class, 'orders'])->name('orders.index');
                Route::post('orders', [FieldForceController::class, 'storeOrder'])->name('orders.store');
                Route::get('orders/{id}', [FieldForceController::class, 'order'])->whereUlid('id')->name('orders.show');
                Route::patch('orders/{id}', [FieldForceController::class, 'updateOrder'])->whereUlid('id')->name('orders.update');
                Route::post('orders/{id}/submit', [FieldForceController::class, 'submitOrder'])->whereUlid('id')->name('orders.submit');
                Route::post('orders/{id}/cancel', [FieldForceController::class, 'cancelOrder'])->whereUlid('id')->name('orders.cancel');

                Route::get('follow-ups', [FieldForceController::class, 'followUps'])->name('follow-ups.index');
                Route::post('follow-ups', [FieldForceController::class, 'storeFollowUp'])->name('follow-ups.store');
                Route::post('follow-ups/{id}/{action}', [FieldForceController::class, 'closeFollowUp'])->whereUlid('id')->whereIn('action', ['complete', 'cancel'])->name('follow-ups.close');

                Route::get('reports/activity', [FieldForceController::class, 'activityReport'])->name('reports.activity');
            });

            Route::middleware('module:pos')->prefix('pos')->name('pos.')->group(function () {
                Route::get('me', [PosController::class, 'me'])->name('me');
                Route::get('terminals', [PosController::class, 'terminals'])->name('terminals.index');
                Route::post('terminals', [PosController::class, 'storeTerminal'])->name('terminals.store');
                Route::patch('terminals/{id}', [PosController::class, 'updateTerminal'])->whereUlid('id')->name('terminals.update');
                Route::get('terminals/{id}/offline-catalogue', [PosController::class, 'offlineCatalogue'])->whereUlid('id')->name('terminals.offline-catalogue');

                Route::post('shifts', [PosController::class, 'openShift'])->name('shifts.open');
                Route::get('shifts/current', [PosController::class, 'currentShift'])->name('shifts.current');
                Route::get('shifts/{id}', [PosController::class, 'showShift'])->whereUlid('id')->name('shifts.show');
                Route::post('shifts/{id}/cash-movements', [PosController::class, 'cashMovement'])->whereUlid('id')->middleware('idempotent:required')->name('shifts.cash');
                Route::post('shifts/{id}/close', [PosController::class, 'closeShift'])->whereUlid('id')->name('shifts.close');
                Route::post('shifts/{id}/review', [PosController::class, 'reviewShift'])->whereUlid('id')->name('shifts.review');

                Route::get('lookup', [PosController::class, 'lookup'])->name('lookup');
                Route::post('sales', [PosController::class, 'startSale'])->name('sales.start');
                Route::get('sales/{id}', [PosController::class, 'showSale'])->whereUlid('id')->name('sales.show');
                Route::put('sales/{id}/lines', [PosController::class, 'setLines'])->whereUlid('id')->name('sales.lines');
                Route::post('sales/{id}/payments', [PosController::class, 'addPayment'])->whereUlid('id')->middleware('idempotent:required')->name('sales.payments');
                Route::get('sales/{id}/receipt', [PosController::class, 'receipt'])->whereUlid('id')->name('sales.receipt');
                Route::post('sales/{id}/refunds', [PosController::class, 'requestRefund'])->whereUlid('id')->middleware('idempotent:required')->name('sales.refunds');
                Route::post('sales/{id}/{action}', [PosController::class, 'saleAction'])->whereUlid('id')->whereIn('action', ['hold', 'checkout', 'cancel'])->name('sales.action');

                Route::get('payments/pending', [PosController::class, 'pendingPayments'])->name('payments.pending');
                Route::post('payments/{paymentId}/confirm', [PosController::class, 'confirmPayment'])->whereUlid('paymentId')->name('payments.confirm');
                Route::get('refunds', [PosController::class, 'refunds'])->name('refunds.index');
                Route::post('refunds/{id}/{decision}', [PosController::class, 'decideRefund'])->whereUlid('id')->whereIn('decision', ['approve', 'reject'])->name('refunds.decide');
                Route::get('reports/{report}', [PosController::class, 'report'])->name('reports');
            });

            Route::middleware('module:sales')->group(function () {
                Route::get('price-lists', [SalesController::class, 'priceLists'])->name('price-lists.index');
                Route::post('price-lists', [SalesController::class, 'storePriceList'])->name('price-lists.store');
                Route::put('price-lists/{id}/items', [SalesController::class, 'replacePriceListItems'])->whereUlid('id')->name('price-lists.items');

                Route::get('quotations', [SalesController::class, 'quotations'])->name('quotations.index');
                Route::post('quotations', [SalesController::class, 'storeQuotation'])->name('quotations.store');
                Route::post('quotations/{id}/convert', [SalesController::class, 'convertQuotation'])->whereUlid('id')->middleware('idempotent:required')->name('quotations.convert');

                Route::get('sales-orders', [SalesController::class, 'salesOrders'])->name('sales-orders.index');
                Route::post('sales-orders', [SalesController::class, 'storeSalesOrder'])->middleware('idempotent:required')->name('sales-orders.store');
                Route::get('sales-orders/{id}', [SalesController::class, 'showSalesOrder'])->whereUlid('id')->name('sales-orders.show');
                Route::patch('sales-orders/{id}', [SalesController::class, 'updateSalesOrder'])->whereUlid('id')->name('sales-orders.update');
                Route::post('sales-orders/{id}/deliveries', [SalesController::class, 'deliver'])->whereUlid('id')->middleware('idempotent:required')->name('sales-orders.deliver');
                Route::post('sales-orders/{id}/invoice', [SalesController::class, 'invoiceOrder'])->whereUlid('id')->middleware('idempotent:required')->name('sales-orders.invoice');
                Route::post('sales-orders/{id}/{action}', [SalesController::class, 'salesOrderAction'])->whereUlid('id')
                    ->whereIn('action', ['confirm', 'approve', 'reject', 'cancel', 'close'])->name('sales-orders.action');

                Route::get('sales-invoices', [SalesController::class, 'invoices'])->name('sales-invoices.index');
                Route::get('sales-invoices/{id}', [SalesController::class, 'showInvoice'])->whereUlid('id')->name('sales-invoices.show');
                Route::post('sales-invoices/{id}/credit-notes', [SalesController::class, 'storeCreditNote'])->whereUlid('id')->middleware('idempotent:required')->name('sales-invoices.credit-notes');
                Route::post('customer-payments', [SalesController::class, 'receivePayment'])->middleware('idempotent:required')->name('customer-payments.store');
                Route::get('customers/{customerId}/statement', [SalesController::class, 'customerStatement'])->whereUlid('customerId')->name('customers.statement');
                Route::get('sales/receivables-aging', [SalesController::class, 'receivablesAging'])->name('sales.receivables-aging');
                Route::get('sales/settings', [SalesController::class, 'settings'])->name('sales.settings');
                Route::patch('sales/settings', [SalesController::class, 'updateSettings'])->name('sales.settings.update');
            });

            Route::middleware('module:finance')->prefix('finance')->name('finance.')->group(function () {
                Route::get('accounts', [FinanceController::class, 'accounts'])->name('accounts.index');
                Route::post('accounts', [FinanceController::class, 'storeAccount'])->name('accounts.store');
                Route::patch('accounts/{id}', [FinanceController::class, 'updateAccount'])->whereUlid('id')->name('accounts.update');
                Route::get('periods', [FinanceController::class, 'periods'])->name('periods.index');
                Route::post('periods/{id}/{action}', [FinanceController::class, 'changePeriod'])->whereUlid('id')->whereIn('action', ['close', 'reopen'])->name('periods.change');
                Route::get('journal-entries', [FinanceController::class, 'journalEntries'])->name('journal-entries.index');
                Route::post('journal-entries', [FinanceController::class, 'storeJournalEntry'])->middleware('idempotent:required')->name('journal-entries.store');
                Route::post('journal-entries/{id}/reverse', [FinanceController::class, 'reverseJournalEntry'])->whereUlid('id')->middleware('idempotent:required')->name('journal-entries.reverse');
                Route::get('payments', [FinanceController::class, 'payments'])->name('payments.index');
                Route::post('payments/supplier', [FinanceController::class, 'paySupplier'])->middleware('idempotent:required')->name('payments.supplier');
                Route::post('payments/{id}/allocations', [FinanceController::class, 'allocatePayment'])->whereUlid('id')->middleware('idempotent:required')->name('payments.allocate');
                Route::get('reports/{report}', [FinanceController::class, 'report'])
                    ->whereIn('report', ['trial-balance', 'balance-sheet', 'profit-and-loss', 'account-ledger', 'payables-aging'])->name('reports');
            });

            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
            Route::get('organization/subscription', [SubscriptionController::class, 'current'])->name('organization.subscription');
            Route::patch('organization/security', [AccountSecurityController::class, 'updateOrganizationSecurity'])->name('organization.security');
        });

        // Platform administration
        Route::prefix('admin')->name('admin.')->middleware('idempotent')->group(function () {
            Route::get('marketplace/fees', [MarketplaceAdminController::class, 'fees'])->name('marketplace.fees');
            Route::post('marketplace/fees/{id}/waive', [MarketplaceAdminController::class, 'waive'])->whereUlid('id')->name('marketplace.fees.waive');
            Route::get('marketplace/disputes', [MarketplaceAdminController::class, 'disputes'])->name('marketplace.disputes');
            Route::post('marketplace/disputes/{id}/notes', [MarketplaceAdminController::class, 'note'])->whereUlid('id')->name('marketplace.disputes.notes');
            Route::get('organizations', [OrganizationAdminController::class, 'index'])->name('organizations.index');
            Route::get('organizations/{organization}', [OrganizationAdminController::class, 'show'])->whereUlid('organization')->name('organizations.show');
            Route::get('organizations/{organization}/documents/{attachmentId}', [OrganizationAdminController::class, 'downloadDocument'])->whereUlid(['organization', 'attachmentId'])->name('organizations.documents.download');
            Route::post('organizations/{organization}/verification-decisions', [OrganizationAdminController::class, 'decide'])->whereUlid('organization')->name('organizations.decide');
            Route::post('organizations/{organization}/suspend', [OrganizationAdminController::class, 'suspend'])->whereUlid('organization')->name('organizations.suspend');
            Route::post('organizations/{organization}/reactivate', [OrganizationAdminController::class, 'reactivate'])->whereUlid('organization')->name('organizations.reactivate');
            Route::put('organizations/{organization}/modules', [OrganizationAdminController::class, 'updateModules'])->whereUlid('organization')->name('organizations.modules');

            Route::post('products', [CatalogueAdminController::class, 'storeProduct'])->name('products.store');
            Route::patch('products/{product}', [CatalogueAdminController::class, 'updateProduct'])->whereUlid('product')->name('products.update');
            Route::post('products/{product}/regulatory-identifiers', [CatalogueAdminController::class, 'storeRegulatoryIdentifier'])->whereUlid('product')->name('products.regulatory-identifiers.store');
            Route::post('regulatory-identifiers/{identifier}/verifications', [CatalogueAdminController::class, 'verifyRegulatoryIdentifier'])->whereUlid('identifier')->name('regulatory-identifiers.verify');
            Route::get('product-change-requests', [CatalogueAdminController::class, 'changeRequests'])->name('product-change-requests.index');
            Route::post('product-change-requests/{changeRequest}/approve', [CatalogueAdminController::class, 'approveChangeRequest'])->whereUlid('changeRequest')->name('product-change-requests.approve');
            Route::post('product-change-requests/{changeRequest}/reject', [CatalogueAdminController::class, 'rejectChangeRequest'])->whereUlid('changeRequest')->name('product-change-requests.reject');

            Route::put('branding', [PlatformAdminController::class, 'updateBranding'])->name('branding.update');
            Route::get('security-settings', [AccountSecurityController::class, 'platformSettings'])->name('security-settings.show');
            Route::put('security-settings', [AccountSecurityController::class, 'updatePlatformSettings'])->name('security-settings.update');
            Route::post('users/{user}/mfa-reset', [AccountSecurityController::class, 'resetUserMfa'])->whereNumber('user')->name('users.mfa-reset');
            Route::post('users/{user}/suspend', [PlatformAdminController::class, 'suspendUser'])->whereNumber('user')->name('users.suspend');
            Route::post('users/{user}/reactivate', [PlatformAdminController::class, 'reactivateUser'])->whereNumber('user')->name('users.reactivate');
            Route::get('audit-logs', [AuditLogController::class, 'platform'])->name('audit-logs.index');

            Route::get('subscription-plans', [SubscriptionController::class, 'plans'])->name('subscription-plans.index');
            Route::patch('subscription-plans/{plan}', [SubscriptionController::class, 'updatePlan'])->whereUlid('plan')->name('subscription-plans.update');
            Route::get('organizations/{organization}/subscription', [SubscriptionController::class, 'showForOrganization'])->whereUlid('organization')->name('organizations.subscription');
            Route::put('organizations/{organization}/subscription', [SubscriptionController::class, 'changeForOrganization'])->whereUlid('organization')->name('organizations.subscription.change');
            Route::put('organizations/{organization}/entitlement-overrides', [SubscriptionController::class, 'overrideForOrganization'])->whereUlid('organization')->name('organizations.entitlement-overrides');

            Route::get('operations/status', [OperationsController::class, 'status'])->name('operations.status');
            Route::get('operations/failed-jobs', [OperationsController::class, 'failedJobs'])->name('operations.failed-jobs');
            Route::post('operations/failed-jobs/{uuid}/retry', [OperationsController::class, 'retryFailedJob'])->whereUuid('uuid')->name('operations.failed-jobs.retry');
            Route::get('users', [OperationsController::class, 'users'])->name('users.index');
            Route::get('countries', [OperationsController::class, 'countries'])->name('countries.index');
            Route::patch('countries/{code}', [OperationsController::class, 'updateCountry'])->where('code', '[A-Za-z]{2}')->name('countries.update');
        });
    });
});
