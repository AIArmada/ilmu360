<?php

declare(strict_types=1);

namespace App\Listeners\Communications;

use AIArmada\Communications\Contracts\CommunicationManager;
use AIArmada\Communications\Enums\CommunicationEventSource;
use AIArmada\Communications\Events\DeliveryFailed;
use AIArmada\Communications\Models\CommunicationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class DeliveryFallbackListener implements ShouldQueue
{
    public function __construct(
        private readonly CommunicationManager $comms,
    ) {}

    public function handle(DeliveryFailed $event): void
    {
        $delivery = CommunicationDelivery::with([
            'communication.recipients.recipient',
            'content',
        ])->find($event->deliveryId);

        if ($delivery === null) {
            return;
        }

        $communication = $delivery->communication;

        if ($communication === null) {
            return;
        }

        $fallbackChannels = $communication->metadata['fallback_channels'] ?? [];

        if ($fallbackChannels === []) {
            return;
        }

        $currentChannel = $delivery->channel;

        $currentIdx = array_search($currentChannel, $fallbackChannels, true);

        if ($currentIdx === false || $currentIdx >= count($fallbackChannels) - 1) {
            $communication->events()->create([
                'delivery_id' => $delivery->id,
                'event' => 'fallback_exhausted',
                'source' => CommunicationEventSource::System,
                'provider' => $delivery->provider,
                'occurred_at' => CarbonImmutable::now(),
                'metadata' => [
                    'failed_channel' => $currentChannel,
                    'failure_code' => $event->failureCode,
                    'failure_message' => $event->failureMessage,
                ],
            ]);

            Log::warning('Notification fallback exhausted', [
                'communication_id' => $communication->id,
                'delivery_id' => $delivery->id,
                'channel' => $currentChannel,
                'failure_code' => $event->failureCode,
            ]);

            return;
        }

        $nextChannel = $fallbackChannels[$currentIdx + 1];

        $recipient = $delivery->recipient;

        if ($recipient === null) {
            return;
        }

        $notifiable = $recipient->recipient;

        if ($notifiable === null) {
            return;
        }

        $communication->events()->create([
            'delivery_id' => $delivery->id,
            'event' => 'fallback_triggered',
            'source' => CommunicationEventSource::System,
            'provider' => $delivery->provider,
            'occurred_at' => CarbonImmutable::now(),
            'metadata' => [
                'from_channel' => $currentChannel,
                'to_channel' => $nextChannel,
                'failure_code' => $event->failureCode,
                'failure_message' => $event->failureMessage,
            ],
        ]);

        Log::info('Notification delivery falling back to next channel', [
            'communication_id' => $communication->id,
            'delivery_id' => $delivery->id,
            'from_channel' => $currentChannel,
            'to_channel' => $nextChannel,
        ]);

        // ponytail: no notification object available yet — Phase 2 stores
        // the notification class in communication metadata so we can
        // reconstruct and dispatch via $this->comms->notify(...) here.
    }
}
