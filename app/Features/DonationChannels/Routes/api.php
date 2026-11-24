<?php

declare(strict_types=1);

use App\Features\DonationChannels\EntryPoints\Api\Admin\DonationBankCatalogController;
use App\Platform\Http\Middleware\EnsureAdminApiAccess;
use Illuminate\Support\Facades\Route;

// Admin donation bank catalog for schema-driven writes, scoped to the
// resolved donation owner country.
Route::middleware(['api', 'auth:sanctum', EnsureAdminApiAccess::class])
    ->prefix('api/v1/admin/catalogs')
    ->name('api.admin.catalogs.')
    ->group(function (): void {
        Route::get('/donation-banks', DonationBankCatalogController::class)->name('donation-banks');
    });
