<?php

declare(strict_types=1);

namespace App\Features\Communications;

use AIArmada\Communications\Contracts\ConsentResolver;
use AIArmada\Communications\Contracts\DestinationResolver;
use AIArmada\Communications\Contracts\PayloadRedactor;
use AIArmada\Communications\Contracts\PreferenceResolver;
use AIArmada\Communications\Contracts\QuietHoursResolver;
use AIArmada\Communications\Contracts\RecipientSnapshotResolver;
use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Features\Communications\Api\DeliveryFencing;
use App\Features\Communications\Api\ManagedNotificationDelivery;
use App\Features\Communications\Api\NotificationInboxManagement;
use App\Features\Communications\Api\NotificationMessageRendering;
use App\Features\Communications\Api\NotificationTransport;
use App\Features\Communications\Api\PushDestinationManagement;
use App\Features\Communications\Contributors\CommunicationsAccountLifecycleContributor;
use App\Features\Communications\EntryPoints\Channels\DigestMailChannel;
use App\Features\Communications\EntryPoints\Channels\InboxChannel;
use App\Features\Communications\EntryPoints\Channels\PushChannel;
use App\Features\Communications\Services\DeliveryFencingService;
use App\Features\Communications\Services\ManagedNotificationDeliveryService;
use App\Features\Communications\Services\NotificationInboxManagementService;
use App\Features\Communications\Services\NotificationMessageRenderer;
use App\Features\Communications\Services\NotificationSettingsService;
use App\Features\Communications\Services\PushDestinationService;
use App\Features\Communications\Support\Integration\AppConsentResolver;
use App\Features\Communications\Support\Integration\AppPreferenceResolver;
use App\Features\Communications\Support\Integration\AppQuietHoursResolver;
use App\Features\Communications\Support\Integration\AppRecipientSnapshotResolver;
use App\Features\Communications\Support\Integration\ListSafePayloadRedactor;
use App\Features\Communications\Support\Integration\ProbeSafeDestinationResolver;
use App\Platform\FeatureModule;
use App\Shared\Auth\AccountLifecycleContributor;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;

final class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(AccountNotificationPreferences::class, NotificationSettingsService::class);
        $this->app->bind(NotificationInboxManagement::class, NotificationInboxManagementService::class);
        $this->app->bind(NotificationMessageRendering::class, NotificationMessageRenderer::class);
        $this->app->bind(PushDestinationManagement::class, PushDestinationService::class);
        $this->app->bind(DeliveryFencing::class, DeliveryFencingService::class);
        $this->app->bind(ManagedNotificationDelivery::class, ManagedNotificationDeliveryService::class);
        $this->app->bind(ConsentResolver::class, AppConsentResolver::class);
        $this->app->bind(DestinationResolver::class, ProbeSafeDestinationResolver::class);
        $this->app->bind(PreferenceResolver::class, AppPreferenceResolver::class);
        $this->app->bind(QuietHoursResolver::class, AppQuietHoursResolver::class);
        $this->app->bind(RecipientSnapshotResolver::class, AppRecipientSnapshotResolver::class);
        $this->app->singleton(PayloadRedactor::class, ListSafePayloadRedactor::class);
        $this->app->tag([
            CommunicationsAccountLifecycleContributor::class,
        ], AccountLifecycleContributor::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        Notification::extend(
            NotificationTransport::Inbox->value,
            static fn (Container $container): InboxChannel => $container->make(InboxChannel::class),
        );
        Notification::extend(
            NotificationTransport::DigestMail->value,
            static fn (Container $container): DigestMailChannel => $container->make(DigestMailChannel::class),
        );
        Notification::extend(
            NotificationTransport::Push->value,
            static fn (Container $container): PushChannel => $container->make(PushChannel::class),
        );

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
