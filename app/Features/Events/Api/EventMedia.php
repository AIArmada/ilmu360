<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Event-owned staged contribution media application.
 *
 * Contribution approvals hand media staged on the pending request to the
 * event through this boundary. The Platform media operation owns the
 * collection write chain, and DonationChannels owns donation-QR attachment.
 */
interface EventMedia
{
    /**
     * @param  list<string>  $stagedMediaIds
     */
    public function applyStagedMedia(string $eventId, string $requestMorphType, string $requestId, array $stagedMediaIds): void;
}
