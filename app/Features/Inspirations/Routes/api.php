<?php

declare(strict_types=1);

use App\Features\Inspirations\EntryPoints\Http\RandomInspirationController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')
    ->prefix('api/v1')
    ->name('api.client.')
    ->group(function (): void {
        Route::get('/inspirations/random', RandomInspirationController::class)
            ->name('inspirations.random');
    });
