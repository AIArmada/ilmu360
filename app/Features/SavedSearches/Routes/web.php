<?php

declare(strict_types=1);

use App\Features\SavedSearches\EntryPoints\Livewire\Pages\SavedSearchesIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/carian-tersimpan', SavedSearchesIndex::class)
        ->name('saved-searches.index');
});
