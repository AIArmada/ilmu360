<?php

declare(strict_types=1);

namespace App\Features\Communications;

use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Features\Communications\Api\NotificationInboxManagement;
use App\Features\Communications\Api\PushDestinationManagement;
use App\Features\Communications\Contributors\CommunicationsAccountLifecycleContributor;
use App\Features\Communications\Services\NotificationInboxManagementService;
use App\Features\Communications\Services\NotificationSettingsService;
use App\Features\Communications\Services\PushDestinationService;
use App\Platform\FeatureModule;
use App\Shared\Auth\AccountLifecycleContributor;
use Illuminate\Support\Facades\Schedule;

final class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(AccountNotificationPreferences::class, NotificationSettingsService::class);
        $this->app->bind(NotificationInboxManagement::class, NotificationInboxManagementService::class);
        $this->app->bind(PushDestinationManagement::class, PushDestinationService::class);
        $this->app->tag([
            CommunicationsAccountLifecycleContributor::class,
        ], AccountLifecycleContributor::TAG);
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
