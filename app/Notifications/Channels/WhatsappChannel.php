<?php

namespace App\Notifications\Channels;

use App\Enums\NotificationChannel;
use App\Models\NotificationDestination;
use App\Notifications\Channels\Exceptions\ChannelDeliveryException;
use App\Services\Notifications\NotificationSettingsManager;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class WhatsappChannel
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
        if (! method_exists($notification, 'toWhatsapp')) {
            return [
                'provider' => (string) config('notification-center.whatsapp.provider', 'meta_cloud'),
                'results' => [],
            ];
        }

        /** @var array<string, mixed> $payload */
        $payload = $notification->toWhatsapp($notifiable);
        $destinations = method_exists($notifiable, 'routeNotificationForWhatsapp')
            ? $notifiable->routeNotificationForWhatsapp($notification)
            : $this->settingsManager->destinationsFor($notifiable, NotificationChannel::Whatsapp);
        $provider = (string) config('notification-center.whatsapp.provider', 'meta_cloud');
        $baseUrl = rtrim((string) config('notification-center.whatsapp.base_url'), '/');
        $version = trim((string) config('notification-center.whatsapp.version'), '/');
        $phoneNumberId = config('notification-center.whatsapp.phone_number_id');
        $accessToken = config('notification-center.whatsapp.access_token');
        $results = [];

        if (! $destinations instanceof Collection || $destinations->isEmpty()) {
            throw new ChannelDeliveryException(
                'WhatsApp delivery failed because no destinations are available.',
                $provider,
                [[
                    'destination_id' => null,
                    'status' => 'failed',
                    'meta' => ['reason' => 'destinations_unavailable'],
                ]]
            );
        }

        if (! is_string($phoneNumberId)
            || $phoneNumberId === ''
            || ! is_string($accessToken)
            || $accessToken === ''
        ) {
            foreach ($destinations as $destination) {
                $results[] = [
                    'destination_id' => $destination instanceof NotificationDestination ? $destination->id : null,
                    'status' => 'failed',
                    'meta' => ['reason' => 'provider_not_configured'],
                ];
            }

            throw new ChannelDeliveryException(
                'WhatsApp delivery failed because the provider is not configured.',
                $provider,
                $results,
            );
        }

        $delivered = false;

        foreach ($destinations as $destination) {
            if (! $destination instanceof NotificationDestination) {
                continue;
            }

            $phone = $destination->address;

            if (! is_string($phone) || $phone === '') {
                $results[] = [
                    'destination_id' => $destination->id,
                    'status' => 'failed',
                    'meta' => ['reason' => 'phone_missing'],
                ];

                continue;
            }

            $response = Http::withToken($accessToken)->post("{$baseUrl}/{$version}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'template',
                'template' => [
                    'name' => (string) data_get($payload, 'template', 'notification_update'),
                    'language' => [
                        'code' => (string) data_get($payload, 'language', 'en'),
                    ],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => (string) data_get($payload, 'title', '')],
                                ['type' => 'text', 'text' => (string) data_get($payload, 'body', '')],
                                ['type' => 'text', 'text' => (string) data_get($payload, 'action_url', route('dashboard'))],
                            ],
                        ],
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
                'provider_message_id' => (string) ($response->json('messages.0.id') ?? ''),
                'meta' => ['response' => $response->json()],
            ];
        }

        if (! $delivered) {
            throw new ChannelDeliveryException('WhatsApp delivery failed.', $provider, $results);
        }

        return [
            'provider' => $provider,
            'results' => $results,
        ];
    }
}
