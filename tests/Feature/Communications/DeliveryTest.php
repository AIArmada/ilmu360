<?php

use AIArmada\Communications\Contracts\ConsentResolver;
use AIArmada\Communications\Contracts\PreferenceResolver;
use AIArmada\Communications\Enums\CommunicationCategory;
use AIArmada\Communications\Enums\CommunicationDirection;
use AIArmada\Communications\Enums\CommunicationPriority;
use AIArmada\Communications\Enums\CommunicationStatus;
use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Enums\NotificationFamily;
use AIArmada\Communications\Enums\NotificationTrigger;
use AIArmada\Communications\Enums\RecipientRole;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Models\CommunicationAttempt;
use AIArmada\Communications\Models\CommunicationDelivery;
use AIArmada\Communications\Models\CommunicationRecipient;
use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Features\Communications\Api\DeliveryFencing;
use App\Features\Communications\Api\NotificationTransport;
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

it('fails only open deliveries owned by the communication and keeps settled or ambiguous deliveries unchanged', function () {
    $makeCommunication = function (): Communication {
        $communication = new Communication;
        $communication->direction = CommunicationDirection::Outbound;
        $communication->category = CommunicationCategory::Transactional;
        $communication->priority = CommunicationPriority::Normal;
        $communication->status = CommunicationStatus::Queued;
        $communication->save();

        return $communication;
    };
    $makeRecipient = function (Communication $communication): CommunicationRecipient {
        $recipient = new CommunicationRecipient;
        $recipient->communication_id = $communication->getKey();
        $recipient->role = RecipientRole::To;
        $recipient->save();

        return $recipient;
    };
    $makeDelivery = function (
        Communication $communication,
        CommunicationRecipient $recipient,
        DeliveryStatus $status,
    ): CommunicationDelivery {
        $delivery = new CommunicationDelivery;
        $delivery->communication_id = $communication->getKey();
        $delivery->recipient_id = $recipient->getKey();
        $delivery->channel = NotificationTransport::DigestMail->value;
        $delivery->status = $status;
        $delivery->attempt_count = 0;
        $delivery->save();

        return $delivery;
    };

    $communication = $makeCommunication();
    $recipient = $makeRecipient($communication);
    $queued = $makeDelivery($communication, $recipient, DeliveryStatus::Queued);
    $accepted = $makeDelivery($communication, $recipient, DeliveryStatus::Accepted);
    $acceptedAfterSendingFlip = $makeDelivery($communication, $recipient, DeliveryStatus::Sending);
    $acceptedAfterSendingFlip->accepted_at = now();
    $acceptedAfterSendingFlip->save();
    $ambiguous = $makeDelivery($communication, $recipient, DeliveryStatus::Sending);
    $attempt = new CommunicationAttempt;
    $attempt->delivery_id = $ambiguous->getKey();
    $attempt->attempt_number = 1;
    $attempt->started_at = now();
    $attempt->save();

    $cancelledDelivery = $makeDelivery($communication, $recipient, DeliveryStatus::Sending);
    $cancelledDelivery->cancelled_at = now();
    $cancelledDelivery->save();

    $cancelledCommunication = $makeCommunication();
    $cancelledCommunication->status = CommunicationStatus::Cancelled;
    $cancelledCommunication->cancelled_at = now();
    $cancelledCommunication->save();
    $aggregateCancelledDelivery = $makeDelivery(
        $cancelledCommunication,
        $makeRecipient($cancelledCommunication),
        DeliveryStatus::Sending,
    );

    $otherCommunication = $makeCommunication();
    $otherRecipient = $makeRecipient($otherCommunication);
    $otherDelivery = $makeDelivery($otherCommunication, $otherRecipient, DeliveryStatus::Queued);

    app(DeliveryFencing::class)->recordNotificationFailure(
        (string) $communication->getKey(),
        [
            NotificationTransport::DigestMail->value => (string) $queued->getKey(),
            NotificationTransport::Push->value => (string) $accepted->getKey(),
            'accepted-after-sending-flip' => (string) $acceptedAfterSendingFlip->getKey(),
            NotificationTransport::Inbox->value => (string) $ambiguous->getKey(),
            'cancelled-delivery' => (string) $cancelledDelivery->getKey(),
            'cancelled-aggregate' => (string) $aggregateCancelledDelivery->getKey(),
            'unrelated' => (string) $otherDelivery->getKey(),
        ],
        'channel delivery failed',
    );

    app(DeliveryFencing::class)->recordNotificationFailure(
        (string) $cancelledCommunication->getKey(),
        ['cancelled-aggregate' => (string) $aggregateCancelledDelivery->getKey()],
        'late channel delivery failure',
    );

    $this->assertDatabaseHas('communication_deliveries', [
        'id' => $queued->getKey(),
        'status' => DeliveryStatus::Failed->value,
    ]);
    $this->assertDatabaseHas('communication_deliveries', [
        'id' => $accepted->getKey(),
        'status' => DeliveryStatus::Accepted->value,
    ]);
    $this->assertDatabaseHas('communication_deliveries', [
        'id' => $acceptedAfterSendingFlip->getKey(),
        'status' => DeliveryStatus::Sending->value,
        'accepted_at' => $acceptedAfterSendingFlip->accepted_at,
    ]);
    $this->assertDatabaseHas('communication_deliveries', [
        'id' => $ambiguous->getKey(),
        'status' => DeliveryStatus::Sending->value,
    ]);
    $this->assertDatabaseHas('communication_deliveries', [
        'id' => $cancelledDelivery->getKey(),
        'status' => DeliveryStatus::Sending->value,
        'cancelled_at' => $cancelledDelivery->cancelled_at,
    ]);
    $this->assertDatabaseHas('communication_deliveries', [
        'id' => $aggregateCancelledDelivery->getKey(),
        'status' => DeliveryStatus::Sending->value,
    ]);
    $this->assertDatabaseHas('communication_deliveries', [
        'id' => $otherDelivery->getKey(),
        'status' => DeliveryStatus::Queued->value,
    ]);
});

it('rejects external delivery results without a complete frozen fence', function () {
    $communication = new Communication;
    $communication->direction = CommunicationDirection::Outbound;
    $communication->category = CommunicationCategory::Transactional;
    $communication->priority = CommunicationPriority::Normal;
    $communication->status = CommunicationStatus::Queued;
    $communication->save();

    $recipient = new CommunicationRecipient;
    $recipient->communication_id = $communication->getKey();
    $recipient->role = RecipientRole::To;
    $recipient->save();

    foreach ([null, ['digest_mail_fence' => ['delivery_id' => 'incomplete']]] as $metadata) {
        $delivery = new CommunicationDelivery;
        $delivery->communication_id = $communication->getKey();
        $delivery->recipient_id = $recipient->getKey();
        $delivery->channel = NotificationTransport::DigestMail->value;
        $delivery->status = DeliveryStatus::Sending;
        $delivery->attempt_count = 1;
        $delivery->save();

        $attempt = new CommunicationAttempt;
        $attempt->delivery_id = $delivery->getKey();
        $attempt->attempt_number = 1;
        $attempt->started_at = now();
        $attempt->metadata = $metadata;
        $attempt->save();

        expect(DB::transaction(fn (): bool => app(DeliveryFencing::class)->recordExternalSent(
            (string) $communication->getKey(),
            (string) $delivery->getKey(),
            (string) $attempt->getKey(),
        )))->toBeFalse();

        expect(DB::transaction(fn (): bool => app(DeliveryFencing::class)->recordExternalFailure(
            (string) $communication->getKey(),
            (string) $delivery->getKey(),
            (string) $attempt->getKey(),
            'verified non-delivery',
        )))->toBeFalse();

        $this->assertDatabaseHas('communication_deliveries', [
            'id' => $delivery->getKey(),
            'status' => DeliveryStatus::Sending->value,
        ]);
        $this->assertDatabaseHas('communication_attempts', [
            'id' => $attempt->getKey(),
            'responded_at' => null,
        ]);
    }
});

it('respects configured app family and channel preferences in package eligibility', function () {
    $user = User::factory()->create();
    $preferences = app(AccountNotificationPreferences::class);
    $preferences->stateFor((string) $user->getKey());
    $resolver = app(PreferenceResolver::class);

    expect($resolver)->toBeInstanceOf(AppPreferenceResolver::class)
        ->and($resolver->isEnabled($user->getMorphClass(), (string) $user->getKey(), 'database', 'event_update'))->toBeTrue()
        ->and($resolver->isEnabled($user->getMorphClass(), (string) $user->getKey(), NotificationTransport::Inbox->value, 'event_update'))->toBeTrue()
        ->and($resolver->isEnabled($user->getMorphClass(), (string) $user->getKey(), 'push', 'event_update'))->toBeFalse()
        ->and($resolver->isEnabled($user->getMorphClass(), (string) $user->getKey(), NotificationTransport::Push->value, 'event_update'))->toBeFalse()
        ->and($resolver->isOptedIn($user->getMorphClass(), (string) $user->getKey(), 'mail', 'event_update'))->toBeTrue()
        ->and($resolver->isOptedIn($user->getMorphClass(), (string) $user->getKey(), NotificationTransport::DigestMail->value, 'event_update'))->toBeTrue();

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
    $withoutPushDestination = $resolver->resolveConsent($recipientType, $recipientId, NotificationTransport::Push->value, 'transactional');

    app(AccountNotificationPreferences::class)->syncSystemEmailDestination($recipientId);

    $withDestination = $resolver->resolveConsent($recipientType, $recipientId, 'mail', 'transactional');

    expect($resolver)->toBeInstanceOf(AppConsentResolver::class)
        ->and($withoutDestination->consented)->toBeFalse()
        ->and($withoutPushDestination->consented)->toBeFalse()
        ->and($withDestination->consented)->toBeTrue();
});
