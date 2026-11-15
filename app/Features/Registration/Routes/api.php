<?php

declare(strict_types=1);

use App\Features\Registration\EntryPoints\Http\RegistrationExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('/events/{event}/registrations/export', [RegistrationExportController::class, 'export'])
            ->name('api.registrations.export');
    });
