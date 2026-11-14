<?php

declare(strict_types=1);

use App\Features\Community\EntryPoints\Http\CommunityReleaseController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('/community/release', CommunityReleaseController::class)
            ->name('community.release.show');
    });
