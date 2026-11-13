<?php

declare(strict_types=1);

namespace App\Shared\Communications;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notification;

/**
 * Digest recipient and message construction boundary.
 *
 * The root adapter owns the concrete notification, its mail/inbox channels and
 * its failure handling; consumers only pass scalar recipient identity plus the
 * computed summary payload.
 */
interface DigestMessages
{
    /**
     * Resolve the notification recipient for an account identifier.
     */
    public function notifiable(string $recipientId): ?Authenticatable;

    /**
     * Build the digest notification for a recipient.
     *
     * @param  array<string, mixed>  $summary
     */
    public function digest(string $recipientId, array $summary): Notification;

    /**
     * Rebuild a digest notification bound to an adopted communication transport.
     *
     * @param  array<string, mixed>  $summary
     * @param  array<string, string>  $deliveryIdsByChannel
     */
    public function redrive(
        string $recipientId,
        string $communicationId,
        array $summary,
        array $deliveryIdsByChannel,
    ): Notification;
}
