<?php

declare(strict_types=1);

use App\Features\Reports\EntryPoints\Http\ReportController;
use Illuminate\Support\Facades\Route;

// Feature-owned API routes (FeatureModule auto-loads this file).
Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::post('/reports', [ReportController::class, 'store'])
            ->middleware('throttle:reports')
            ->name('api.reports.store');
    });
