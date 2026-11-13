<?php

use AIArmada\Communications\Enums\NotificationFamily;
use AIArmada\Communications\Enums\NotificationTrigger;
use AIArmada\Communications\Contracts\ConsentResolver;
use AIArmada\Communications\Contracts\PreferenceResolver;
use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Features\Communications\Support\Integration\AppConsentResolver;
use App\Features\Communications\Support\Integration\AppPreferenceResolver;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;

it('records a communication entry when a notification is sent with auto-capture', function () {
    Config::set('communications.features.auto_capture', true);
    $user = User::factory()->create();
    $notification = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function notificationFamily(): NotificationFamily
        {
            return NotificationFamily::EventUpdate;
        }

        public function notificationTrigger(): NotificationTrigger
        {
            return NotificationTrigger::EventUpdated;
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)
                ->subject('Comms Test Subject')
                ->line('Test body.');
        }
    };

    NotificationFacade::route('mail', 'comms-test@example.com')
        ->notify($notification);

    expect(DB::table('communications')->exists())->toBeTrue();
});

it('respects configured app family and channel preferences in package eligibility', function () {
    $user = User::factory()->create();
    $preferences = app(AccountNotificationPreferences::class);
    $preferences->stateFor((string) $user->getKey());
    $resolver = app(PreferenceResolver::class);

    expect($resolver)->toBeInstanceOf(AppPreferenceResolver::class)
        ->and($resolver->isEnabled($user->getMorphClass(), (string) $user->getKey(), 'database', 'event_update'))->toBeTrue()
        ->and($resolver->isEnabled($user->getMorphClass(), (string) $user->getKey(), 'push', 'event_update'))->toBeFalse()
        ->and($resolver->isOptedIn($user->getMorphClass(), (string) $user->getKey(), 'mail', 'event_update'))->toBeTrue();

    $preferences->save((string) $user->getKey(), [
        'families' => [
            'event_updates' => [
                'enabled' => false,
            ],
        ],
    ]);

    expect($resolver->isEnabled($user->getMorphClass(), (string) $user->getKey(), 'database', 'event_update'))->toBeFalse();
});

it('requires an active destination for supported channel consent', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $recipientType = $user->getMorphClass();
    $recipientId = (string) $user->getKey();
    $resolver = app(ConsentResolver::class);

    $withoutDestination = $resolver->resolveConsent($recipientType, $recipientId, 'mail', 'transactional');

    app(AccountNotificationPreferences::class)->syncSystemEmailDestination($recipientId);

    $withDestination = $resolver->resolveConsent($recipientType, $recipientId, 'mail', 'transactional');

    expect($resolver)->toBeInstanceOf(AppConsentResolver::class)
        ->and($withoutDestination->consented)->toBeFalse()
        ->and($withDestination->consented)->toBeTrue();
});
