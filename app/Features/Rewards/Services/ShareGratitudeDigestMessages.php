<?php

declare(strict_types=1);

namespace App\Features\Rewards\Services;

use App\Features\Rewards\Notifications\ShareGratitudeDigestNotification;
use App\Shared\Auth\AccountDirectory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notification;

/**
 * Rewards gratitude digest message builder.
 *
 * Builds the Rewards notification and resolves recipients through the account
 * directory. Consumers pass scalar identity and payload only.
 */
final readonly class ShareGratitudeDigestMessages
{
    public function __construct(
        private AccountDirectory $accounts,
    ) {}

    public function notifiable(string $recipientId): ?Authenticatable
    {
        return $this->accounts->resolveNotifiable($recipientId);
    }

    public function digest(array $summary): Notification
    {
        return new ShareGratitudeDigestNotification($summary);
    }

    public function redrive(
        string $communicationId,
        array $summary,
        array $deliveryIdsByChannel,
    ): Notification {
        return (new ShareGratitudeDigestNotification($summary))
            ->withCommunicationContext($communicationId, $deliveryIdsByChannel);
    }
}
