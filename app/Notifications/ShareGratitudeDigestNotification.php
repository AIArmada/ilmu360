<?php

declare(strict_types=1);

namespace App\Notifications;

use AIArmada\Communications\Contracts\CommunicationContextAware;
use AIArmada\Communications\Contracts\CommunicationRecorder;
use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Models\CommunicationDelivery;
use AIArmada\Communications\Traits\HasCommunicationContext;
use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Notifications\Channels\DigestMailChannel;
use App\Notifications\Channels\InboxChannel;
use App\Support\Communications\GuardedDeliverySend;
use Illuminate\Notifications\Messages\MailMessage;
use Throwable;

/**
 * Calm delayed gratitude digest content. Coarse counts only — no
 * per-visit detail, no recipient keys, no precise counterpart activity.
 *
 * Rides guarded mail plus the real member inbox channel. Both channels
 * claim the bound delivery pre-send and skip cancelled or settled work,
 * so a correction that cancels the transport first is never zombie-met
 * by a late job. Communication-context-aware: channel sends record back
 * onto the package deliveries, and a failed job records the failure
 * instead of leaving ambiguous silence — the digest reconcile reads
 * exactly this.
 */
class ShareGratitudeDigestNotification extends InAppNotification implements CommunicationContextAware
{
    use HasCommunicationContext;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly array $summary,
    ) {
        parent::__construct(
            pendingNotificationId: (string) ($summary['plan_key'] ?? ''),
            family: NotificationFamily::Sharing,
            trigger: NotificationTrigger::ShareGratitudeDigest,
            priority: NotificationPriority::Low,
            title: (string) __('notifications.share_gratitude_digest.title'),
            body: (string) __('notifications.share_gratitude_digest.body', [
                'summary' => (string) ($summary['text'] ?? ''),
            ]),
            entityType: 'reward_digest',
            entityId: isset($summary['slot_id']) ? (string) $summary['slot_id'] : null,
            meta: [
                'counts' => $summary['counts'] ?? [],
                'sections' => $summary['sections'] ?? [],
                'window' => $summary['window'] ?? [],
            ],
        );
    }

    /**
     * @param  mixed  $notifiable
     * @return list<string|class-string>
     */
    public function via($notifiable): array
    {
        return [DigestMailChannel::class, InboxChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) __('notifications.share_gratitude_digest.title'))
            ->line((string) __('notifications.share_gratitude_digest.body', [
                'summary' => (string) ($this->summary['text'] ?? ''),
            ]));
    }

    /**
     * Provider/transport failure: record the failure onto the bound
     * deliveries so reconcile requeues instead of waiting on silence.
     * Fenced: never regresses a delivered status and never converts
     * an UNKNOWN/ambiguous external attempt (provider may already
     * have accepted) into failure. Ambiguous mail stays Sending for
     * authoritative or operator resolution; only pre-send and
     * unambiguously unsent work is marked failed.
     */
    public function failed(Throwable $exception): void
    {
        if ($this->communicationId === null) {
            return;
        }

        try {
            $recorder = app(CommunicationRecorder::class);
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class;

            foreach ($this->deliveryIdsByChannel as $deliveryId) {
                if (! is_string($deliveryId) || $deliveryId === '') {
                    continue;
                }

                $delivery = CommunicationDelivery::query()->whereKey($deliveryId)->first();

                if (! $delivery instanceof CommunicationDelivery) {
                    continue;
                }

                if ($delivery->status instanceof DeliveryStatus && in_array($delivery->status, [
                    DeliveryStatus::Sent,
                    DeliveryStatus::Received,
                    DeliveryStatus::Delivered,
                    DeliveryStatus::Opened,
                    DeliveryStatus::Read,
                    DeliveryStatus::Clicked,
                    DeliveryStatus::Replied,
                ], true)) {
                    continue;
                }

                if (in_array($delivery->status, [
                    DeliveryStatus::Failed,
                    DeliveryStatus::Cancelled,
                    DeliveryStatus::Expired,
                    DeliveryStatus::Suppressed,
                    DeliveryStatus::Unsubscribed,
                    DeliveryStatus::Bounced,
                    DeliveryStatus::Complained,
                ], true)) {
                    continue;
                }

                if (GuardedDeliverySend::isAmbiguousExternal($deliveryId)) {
                    continue;
                }

                $recorder->markFailed($this->communicationId, $deliveryId, $message);
            }
        } catch (Throwable) {
            // Silently handle - we're in a failure handler
        }
    }
}
