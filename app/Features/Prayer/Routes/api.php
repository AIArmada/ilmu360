<?php

declare(strict_types=1);

use App\Features\Prayer\EntryPoints\Http\PrayerPreviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1/catalogs')->name('api.client.catalogs.')->group(function (): void {
    Route::get('/prayer-preview', PrayerPreviewController::class)
        ->middleware('throttle:60,1')
        ->name('prayer-preview');
});
