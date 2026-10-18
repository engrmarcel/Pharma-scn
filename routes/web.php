<?php

use App\Http\Controllers\Api\V1\AccountSecurityController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\PosReceiptController;
use App\Http\Controllers\Web\SessionController;
use App\Livewire\Account\SecuritySettings;
use App\Livewire\Admin\VerificationQueue;
use App\Livewire\Catalogue\ProductBrowser;
use App\Livewire\Pos\Register;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

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
    Route::middleware(['tenant', 'module:pos'])->group(function () {
        Route::get('pos', Register::class)->name('pos.register');
        Route::get('pos/receipts/{sale}', PosReceiptController::class)->whereUlid('sale')->name('pos.receipt');
    });

    Route::get('admin/verifications', VerificationQueue::class)
        ->middleware('can:platform.organizations.view')
        ->name('admin.verifications');
});
