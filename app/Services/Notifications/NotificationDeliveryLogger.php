<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\PendingNotification;
use App\Models\User;
use App\Notifications\NotificationCenterMessage;
use Illuminate\Notifications\DatabaseNotification;

class NotificationDeliveryLogger
{
    public function __construct(
        protected NotificationSettingsManager $settingsManager,
    ) {}

    public function logMailSent(User $user, NotificationCenterMessage $notification): void
    {
        $destination = $this->settingsManager->destinationsFor($user, NotificationChannel::Email)->first();

        $this->logDestinationResult(
            $user,
            $notification,
            NotificationChannel::Email,
            $destination,
            NotificationDeliveryStatus::Delivered,
            provider: 'mail',
        );
    }

    public function logDatabaseSent(User $user, NotificationCenterMessage $notification, mixed $response): void
    {
        $meta = [];

        if ($response instanceof DatabaseNotification) {
            $meta['database_notification_id'] = $response->id;

            $response->forceFill([
                'family' => $notification->family->value,
                'trigger' => $notification->trigger->value,
                'priority' => $notification->priority->value,
                'fingerprint' => $notification->pendingNotificationId,
                'action_url' => $notification->actionUrl,
                'entity_type' => $notification->entityType,
                'entity_id' => $notification->entityId,
                'occurred_at' => $notification->occurredAt,
                'inbox_visible' => (bool) ($notification->meta['inbox_visible'] ?? true),
                'is_digest' => $notification->digest,
            ])->save();

            PendingNotification::query()
                ->whereKey($notification->pendingNotificationId)
                ->update([
                    'dispatched_at' => now(),
                    'notification_id' => $response->id,
                ]);
        }

        $this->logDestinationResult(
            $user,
            $notification,
            NotificationChannel::InApp,
            destination: null,
            status: NotificationDeliveryStatus::Delivered,
            provider: 'database',
            meta: $meta,
        );
    }

    /**
     * @param  list<array{
     *     destination_id: string|null,
     *     status: string,
     *     provider_message_id?: string|null,
     *     meta?: array<string, mixed>
     * }>  $results
     */
    public function logChannelResults(
        User $user,
        NotificationCenterMessage $notification,
        NotificationChannel $channel,
        array $results,
        ?string $provider = null,
    ): void {
        foreach ($results as $result) {
            $status = NotificationDeliveryStatus::tryFrom((string) ($result['status'] ?? ''));

            if (! $status instanceof NotificationDeliveryStatus) {
                continue;
            }

            $destinationId = $result['destination_id'] ?? null;
            $destination = is_string($destinationId) && $destinationId !== ''
                ? $this->settingsManager->destinationsFor($user, $channel)->firstWhere('id', $destinationId)
                : null;

            $this->logDestinationResult(
                user: $user,
                notification: $notification,
                channel: $channel,
                destination: $destination instanceof NotificationDestination ? $destination : null,
                status: $status,
                provider: $provider ?? $this->providerName($channel),
                providerMessageId: is_string($result['provider_message_id'] ?? null)
                    ? $result['provider_message_id']
                    : null,
                meta: is_array($result['meta'] ?? null) ? $result['meta'] : [],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function logChannelFailure(
        User $user,
        NotificationCenterMessage $notification,
        NotificationChannel $channel,
        array $meta = [],
    ): void {
        $destination = $channel === NotificationChannel::Email
            ? $this->settingsManager->destinationsFor($user, NotificationChannel::Email)->first()
            : null;

        $this->logDestinationResult(
            $user,
            $notification,
            $channel,
            $destination,
            NotificationDeliveryStatus::Failed,
            provider: $this->providerName($channel),
            meta: $meta,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function logDestinationResult(
        User $user,
        NotificationCenterMessage $notification,
        NotificationChannel $channel,
        ?NotificationDestination $destination,
        NotificationDeliveryStatus $status,
        ?string $provider = null,
        ?string $providerMessageId = null,
        array $meta = [],
    ): void {
        NotificationDelivery::query()->updateOrCreate(
            [
                'fingerprint' => sha1(implode('|', [
                    $notification->pendingNotificationId,
                    $channel->value,
                    $destination instanceof NotificationDestination ? $destination->id : 'none',
                ])),
            ],
            [
                'notification_message_id' => $notification->pendingNotificationId,
                'user_id' => $user->id,
                'family' => $notification->family->value,
                'trigger' => $notification->trigger->value,
                'channel' => $channel->value,
                'destination_id' => $destination?->id,
                'provider' => $provider ?? $this->providerName($channel),
                'provider_message_id' => $providerMessageId,
                'status' => $status->value,
                'payload' => [
                    'action_label' => __('notifications.actions.open'),
                ],
                'meta' => array_merge($meta, [
                    'fallback_strategy' => $notification->fallbackStrategy,
                    'remaining_fallback_channels' => $notification->fallbackChannels,
                    'bypass_quiet_hours' => $notification->bypassQuietHours,
                ]),
                'sent_at' => now(),
                'delivered_at' => $status === NotificationDeliveryStatus::Delivered ? now() : null,
                'failed_at' => $status === NotificationDeliveryStatus::Failed ? now() : null,
            ]
        );

        if ($status === NotificationDeliveryStatus::Delivered) {
            PendingNotification::query()
                ->whereKey($notification->pendingNotificationId)
                ->update([
                    'processed_at' => now(),
                    'dispatched_at' => now(),
                ]);

            $this->markDigestSourcesDelivered($notification, $channel, $destination, $provider, $providerMessageId);
        }
    }

    protected function markDigestSourcesDelivered(
        NotificationCenterMessage $notification,
        NotificationChannel $channel,
        ?NotificationDestination $destination,
        ?string $provider,
        ?string $providerMessageId,
    ): void {
        if (! $notification->digest || $notification->sourcePendingIds === []) {
            return;
        }

        PendingNotification::query()
            ->whereIn('id', $notification->sourcePendingIds)
            ->get()
            ->each(function (PendingNotification $sourcePending) use ($notification, $channel, $destination, $provider, $providerMessageId): void {
                NotificationDelivery::query()->firstOrCreate(
                    [
                        'fingerprint' => sha1('digest-source|'.$sourcePending->id.'|'.$channel->value.'|'.$notification->pendingNotificationId),
                    ],
                    [
                        'notification_message_id' => $sourcePending->id,
                        'user_id' => $sourcePending->user_id,
                        'family' => $this->pendingFamilyValue($sourcePending),
                        'trigger' => $this->pendingTriggerValue($sourcePending),
                        'channel' => $channel->value,
                        'destination_id' => $destination?->id,
                        'provider' => $provider ?? $this->providerName($channel),
                        'provider_message_id' => $providerMessageId,
                        'status' => NotificationDeliveryStatus::Delivered->value,
                        'payload' => ['digest_pending_notification_id' => $notification->pendingNotificationId],
                        'meta' => ['digest' => true],
                        'sent_at' => now(),
                        'delivered_at' => now(),
                    ]
                );

                $sourcePending->forceFill([
                    'processed_at' => now(),
                    'dispatched_at' => now(),
                ])->save();
            });
    }

    protected function providerName(NotificationChannel $channel): string
    {
        return match ($channel) {
            NotificationChannel::Email => 'mail',
            NotificationChannel::InApp => 'database',
            NotificationChannel::Push => (string) config('notification-center.push.provider', 'fcm'),
            NotificationChannel::Whatsapp => (string) config('notification-center.whatsapp.provider', 'meta_cloud'),
            default => $channel->value,
        };
    }

    protected function pendingFamilyValue(PendingNotification $pending): string
    {
        return $pending->family instanceof \BackedEnum
            ? $pending->family->value
            : (string) $pending->family;
    }

    protected function pendingTriggerValue(PendingNotification $pending): string
    {
        return $pending->trigger instanceof \BackedEnum
            ? $pending->trigger->value
            : (string) $pending->trigger;
    }
}
