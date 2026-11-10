<?php

declare(strict_types=1);

namespace App\Shared\Communications;

/**
 * Interim account notification-settings boundary.
 *
 * Communications will own preferences, destinations and inboxes once that
 * feature is extracted. Consumers use this contract instead of importing the
 * conventional notification settings manager or communications support.
 */
interface AccountNotificationSettings
{
    /**
     * @return array<string, mixed>
     */
    public function stateFor(string $accountId): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function save(string $accountId, array $payload): void;

    public function syncProfileSettings(string $accountId): void;

    public function removePushDestination(string $accountId, string $destinationId): bool;
}
