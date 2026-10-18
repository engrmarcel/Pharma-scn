<?php

use App\Http\Controllers\Api\V1\Admin\CatalogueAdminController;
use App\Http\Controllers\Api\V1\Admin\OperationsController;
use App\Http\Controllers\Api\V1\Admin\OrganizationAdminController;
use App\Http\Controllers\Api\V1\Admin\PlatformAdminController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationProductController;
use App\Http\Controllers\Api\V1\ProductChangeRequestController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReferenceDataController;
use App\Http\Controllers\Api\V1\SubscriptionController;
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

    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('tokens', [AuthController::class, 'issueToken'])->name('auth.tokens.issue');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset');
    });

    // Authenticated, no tenant
    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
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

            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
            Route::get('organization/subscription', [SubscriptionController::class, 'current'])->name('organization.subscription');
        });

        // Platform administration
        Route::prefix('admin')->name('admin.')->middleware('idempotent')->group(function () {
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
