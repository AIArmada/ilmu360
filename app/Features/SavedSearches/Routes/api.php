<?php

declare(strict_types=1);

use App\Features\SavedSearches\EntryPoints\Http\SavedSearchController;
use Illuminate\Support\Facades\Route;

// Feature-owned API routes (FeatureModule auto-loads this file).
Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::apiResource('saved-searches', SavedSearchController::class)
            ->parameters(['saved-searches' => 'savedSearch'])
            ->names('api.saved-searches');

        Route::post('/saved-searches/{savedSearch}/execute', [SavedSearchController::class, 'execute'])
            ->name('api.saved-searches.execute');
    });
