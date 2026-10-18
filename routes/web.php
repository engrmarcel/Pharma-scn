<?php

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\SessionController;
use App\Livewire\Admin\VerificationQueue;
use App\Livewire\Catalogue\ProductBrowser;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('login', [SessionController::class, 'create'])->name('login');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:auth')->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
    Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
    Route::post('organizations/switch', [DashboardController::class, 'switchOrganization'])->name('organizations.switch');

    Route::get('catalogue', ProductBrowser::class)->name('catalogue');

    Route::get('admin/verifications', VerificationQueue::class)
        ->middleware('can:platform.organizations.view')
        ->name('admin.verifications');
});
