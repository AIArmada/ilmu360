<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Notifications\ShareGratitudeDigestNotification;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Communications\DigestMessages;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notification;

/**
 * Root gratitude digest message adapter.
 *
 * Builds the existing notification (mail/inbox channels, `failed()` handling
 * and transport stay root) and resolves recipients through the account
 * directory. Consumers pass scalar identity and payload only.
 */
final readonly class ShareGratitudeDigestMessages implements DigestMessages
{
    public function __construct(
        private AccountDirectory $accounts,
    ) {}

    public function notifiable(string $recipientId): ?Authenticatable
    {
        return $this->accounts->resolveNotifiable($recipientId);
    }

    public function digest(string $recipientId, array $summary): Notification
    {
        return new ShareGratitudeDigestNotification($summary);
    }

    public function redrive(
        string $recipientId,
        string $communicationId,
        array $summary,
        array $deliveryIdsByChannel,
    ): Notification {
        return (new ShareGratitudeDigestNotification($summary))
            ->withCommunicationContext($communicationId, $deliveryIdsByChannel);
    }
}
