<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Feature-owned web routes (FeatureModule auto-loads this file). The Livewire
// alias 'pages.saved-searches.index' is registered explicitly in
// FeatureServiceProvider so the existing string alias keeps resolving.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/carian-tersimpan', 'pages.saved-searches.index')
        ->name('saved-searches.index');
});
