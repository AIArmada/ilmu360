<?php

declare(strict_types=1);

namespace App\Features\Rewards\Services;

use App\Features\Communications\Api\InAppNotificationPayload;
use App\Features\Communications\Api\ManagedNotificationMessage;
use App\Features\Communications\Api\NotificationFamily;
use App\Features\Communications\Api\NotificationPriority;
use App\Features\Communications\Api\NotificationTransport;
use App\Features\Communications\Api\NotificationTrigger;

/**
 * Rewards gratitude digest message builder.
 *
 * Builds immutable notification content and its frozen delivery identity.
 */
final class ShareGratitudeDigestMessages
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public function build(array $summary): ManagedNotificationMessage
    {
        $text = (string) ($summary['text'] ?? '');
        $counts = (array) ($summary['counts'] ?? []);
        $sections = (array) ($summary['sections'] ?? []);
        $window = (array) ($summary['window'] ?? []);

        return new ManagedNotificationMessage(
            channels: [NotificationTransport::DigestMail->value, NotificationTransport::Inbox->value],
            inAppPayload: new InAppNotificationPayload(
                pendingNotificationId: (string) ($summary['plan_key'] ?? ''),
                family: NotificationFamily::Sharing,
                trigger: NotificationTrigger::ShareGratitudeDigest,
                priority: NotificationPriority::Low,
                title: (string) __('notifications.share_gratitude_digest.title'),
                body: (string) __('notifications.share_gratitude_digest.body', ['summary' => $text]),
                entityType: 'reward_digest',
                entityId: isset($summary['slot_id']) ? (string) $summary['slot_id'] : null,
                meta: [
                    'counts' => $counts,
                    'sections' => $sections,
                    'window' => $window,
                ],
            ),
            mailSubjectTranslationKey: 'notifications.share_gratitude_digest.title',
            mailBodyTranslationKey: 'notifications.share_gratitude_digest.body',
            mailReplacements: ['summary' => $text],
            operationSnapshot: [
                'plan_key' => isset($summary['plan_key']) && is_string($summary['plan_key']) ? $summary['plan_key'] : null,
                'slot_id' => isset($summary['slot_id']) && is_string($summary['slot_id']) ? $summary['slot_id'] : null,
                'payload_hash' => hash('sha256', json_encode([
                    'text' => $summary['text'] ?? '',
                    'counts' => $summary['counts'] ?? [],
                    'sections' => $summary['sections'] ?? [],
                    'window' => $summary['window'] ?? [],
                ], JSON_THROW_ON_ERROR)),
            ],
        );
    }
}
