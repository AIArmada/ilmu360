<?php

declare(strict_types=1);

namespace App\Features\Rewards\Notifications;

use AIArmada\Communications\Contracts\CommunicationContextAware;
use AIArmada\Communications\Traits\HasCommunicationContext;
use App\Features\Communications\Api\DeliveryFencing;
use App\Features\Communications\Api\InAppNotificationMessage;
use App\Features\Communications\Api\InAppNotificationPayload;
use App\Features\Communications\Api\NotificationFamily;
use App\Features\Communications\Api\NotificationPriority;
use App\Features\Communications\Api\NotificationTrigger;
use App\Features\Communications\Api\NotificationTransport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
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
class ShareGratitudeDigestNotification extends Notification implements CommunicationContextAware, InAppNotificationMessage
{
    use HasCommunicationContext;

    private readonly string $pendingNotificationId;

    private readonly NotificationFamily $family;

    private readonly NotificationTrigger $trigger;

    private readonly NotificationPriority $priority;

    private readonly string $title;

    private readonly string $body;

    private readonly string $entityType;

    private readonly ?string $entityId;

    /** @var array<string, mixed> */
    private readonly array $meta;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly array $summary,
    ) {
        $this->pendingNotificationId = (string) ($summary['plan_key'] ?? '');
        $this->family = NotificationFamily::Sharing;
        $this->trigger = NotificationTrigger::ShareGratitudeDigest;
        $this->priority = NotificationPriority::Low;
        $this->title = (string) __('notifications.share_gratitude_digest.title');
        $this->body = (string) __('notifications.share_gratitude_digest.body', [
            'summary' => (string) ($summary['text'] ?? ''),
        ]);
        $this->entityType = 'reward_digest';
        $this->entityId = isset($summary['slot_id']) ? (string) $summary['slot_id'] : null;
        $this->meta = [
            'counts' => $summary['counts'] ?? [],
            'sections' => $summary['sections'] ?? [],
            'window' => $summary['window'] ?? [],
        ];
    }

    /**
     * @param  mixed  $notifiable
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return [NotificationTransport::DigestMail->value, NotificationTransport::Inbox->value];
    }

    public function inAppPayload(): InAppNotificationPayload
    {
        return new InAppNotificationPayload(
            pendingNotificationId: $this->pendingNotificationId,
            family: $this->family,
            trigger: $this->trigger,
            priority: $this->priority,
            title: $this->title,
            body: $this->body,
            entityType: $this->entityType,
            entityId: $this->entityId,
            meta: $this->meta,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        return $this->inAppPayload()->toArray();
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
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class;
            app(DeliveryFencing::class)->recordNotificationFailure(
                $this->communicationId,
                $this->deliveryIdsByChannel,
                $message,
            );
        } catch (Throwable) {
            // Silently handle - we're in a failure handler
        }
    }
}
