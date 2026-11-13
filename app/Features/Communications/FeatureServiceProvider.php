<?php

declare(strict_types=1);

namespace App\Features\Communications;

use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Features\Communications\Services\NotificationSettingsService;
use App\Platform\FeatureModule;
use Illuminate\Support\Facades\Schedule;

final class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(AccountNotificationPreferences::class, NotificationSettingsService::class);
    }

    public function boot(): void
    {
        parent::boot();

        if (! $this->app->runningInConsole()) {
            return;
        }

        Schedule::command('communications:dispatch-due')
            ->everyMinute()
            ->timezone('UTC')
            ->name('communications-dispatch-due')
            ->withoutOverlapping();
    }
}
