<?php

namespace App\Notifications\Channels;

use App\Enums\NotificationChannel;
use App\Models\NotificationDestination;
use App\Notifications\Channels\Exceptions\ChannelDeliveryException;
use App\Services\Notifications\NotificationSettingsManager;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class PushChannel
{
    public function __construct(
        protected NotificationSettingsManager $settingsManager,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     results: list<array{
     *         destination_id: string|null,
     *         status: string,
     *         provider_message_id?: string|null,
     *         meta?: array<string, mixed>
     *     }>
     * }
     */
    public function send(object $notifiable, Notification $notification): array
    {
        if (! method_exists($notification, 'toPush')) {
            return [
                'provider' => (string) config('notification-center.push.provider', 'fcm'),
                'results' => [],
            ];
        }

        /** @var array<string, mixed> $payload */
        $payload = $notification->toPush($notifiable);
        $destinations = method_exists($notifiable, 'routeNotificationForPush')
            ? $notifiable->routeNotificationForPush($notification)
            : $this->settingsManager->destinationsFor($notifiable, NotificationChannel::Push);
        $provider = (string) config('notification-center.push.provider', 'fcm');
        $projectId = config('notification-center.push.project_id');
        $credentials = config('notification-center.push.credentials');
        $results = [];

        if (! $destinations instanceof Collection || $destinations->isEmpty()) {
            throw new ChannelDeliveryException(
                'Push delivery failed because no destinations are available.',
                $provider,
                [[
                    'destination_id' => null,
                    'status' => 'failed',
                    'meta' => ['reason' => 'destinations_unavailable'],
                ]]
            );
        }

        if (! is_string($projectId)
            || $projectId === ''
            || ! is_string($credentials)
            || $credentials === ''
        ) {
            foreach ($destinations as $destination) {
                $results[] = [
                    'destination_id' => $destination instanceof NotificationDestination ? $destination->id : null,
                    'status' => 'failed',
                    'meta' => ['reason' => 'provider_not_configured'],
                ];
            }

            throw new ChannelDeliveryException(
                'Push delivery failed because the provider is not configured.',
                $provider,
                $results,
            );
        }

        $endpoint = str_replace('{project}', $projectId, (string) config('notification-center.push.endpoint'));
        $delivered = false;

        foreach ($destinations as $destination) {
            if (! $destination instanceof NotificationDestination) {
                continue;
            }

            $deviceToken = $destination->external_id;

            if (! is_string($deviceToken) || $deviceToken === '') {
                $results[] = [
                    'destination_id' => $destination->id,
                    'status' => 'failed',
                    'meta' => ['reason' => 'device_token_missing'],
                ];

                continue;
            }

            $response = Http::withToken($credentials)->post($endpoint, [
                'message' => [
                    'token' => $deviceToken,
                    'notification' => [
                        'title' => (string) data_get($payload, 'title', ''),
                        'body' => (string) data_get($payload, 'body', ''),
                    ],
                    'data' => [
                        'family' => (string) data_get($payload, 'family', ''),
                        'trigger' => (string) data_get($payload, 'trigger', ''),
                        'action_url' => (string) data_get($payload, 'action_url', ''),
                        'notification_id' => (string) data_get($payload, 'notification_id', ''),
                    ],
                ],
            ]);

            if ($response->failed()) {
                $results[] = [
                    'destination_id' => $destination->id,
                    'status' => 'failed',
                    'meta' => ['response' => $response->json()],
                ];

                continue;
            }

            $delivered = true;
            $results[] = [
                'destination_id' => $destination->id,
                'status' => 'delivered',
                'provider_message_id' => (string) ($response->json('name') ?? ''),
                'meta' => ['response' => $response->json()],
            ];
        }

        if (! $delivered) {
            throw new ChannelDeliveryException('Push delivery failed.', $provider, $results);
        }

        return [
            'provider' => $provider,
            'results' => $results,
        ];
    }
}
