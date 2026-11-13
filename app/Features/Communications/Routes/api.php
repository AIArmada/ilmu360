<?php

declare(strict_types=1);

use App\Features\Communications\EntryPoints\Http\NotificationDestinationController;
use App\Features\Communications\EntryPoints\Http\NotificationMessageController;
use App\Features\Communications\EntryPoints\Http\NotificationSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('/notifications', [NotificationMessageController::class, 'index'])
            ->name('api.notifications.index');
        Route::post('/notifications/{message}/read', [NotificationMessageController::class, 'read'])
            ->name('api.notifications.read');
        Route::post('/notifications/read-all', [NotificationMessageController::class, 'readAll'])
            ->name('api.notifications.read-all');
        Route::get('/notification-settings/catalog', [NotificationSettingsController::class, 'catalog'])
            ->name('api.notification-settings.catalog');
        Route::get('/notification-settings', [NotificationSettingsController::class, 'show'])
            ->name('api.notification-settings.show');
        Route::put('/notification-settings', [NotificationSettingsController::class, 'update'])
            ->name('api.notification-settings.update');
        Route::post('/notification-destinations/push', [NotificationDestinationController::class, 'storePush'])
            ->name('api.notification-destinations.push.store');
        Route::put('/notification-destinations/push/{installation}', [NotificationDestinationController::class, 'updatePush'])
            ->name('api.notification-destinations.push.update');
        Route::delete('/notification-destinations/push/{installation}', [NotificationDestinationController::class, 'destroyPush'])
            ->name('api.notification-destinations.push.destroy');
    });
