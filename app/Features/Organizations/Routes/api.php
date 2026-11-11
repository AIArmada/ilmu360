<?php

declare(strict_types=1);

use App\Features\Organizations\EntryPoints\Http\OrganizationController;
use Illuminate\Support\Facades\Route;

// Public organization discovery.
Route::middleware('api')
    ->prefix('api/v1')
    ->name('api.client.')
    ->group(function (): void {
        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::get('/organizations/{organizationKey}', [OrganizationController::class, 'show'])->name('organizations.show');
    });

// Authenticated organization workspace.
Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1')
    ->name('api.client.')
    ->group(function (): void {
        Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::get('/organization-workspace', [OrganizationController::class, 'workspace'])
            ->middleware('current.organization:true')
            ->name('organizations.workspace');
    });
