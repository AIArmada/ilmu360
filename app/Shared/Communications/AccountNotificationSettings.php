<?php

declare(strict_types=1);

namespace App\Shared\Communications;

/**
 * Identity-facing notification settings contract.
 *
 * Identity uses this contract for account settings. Its root adapter delegates
 * preference behavior to the Communications feature and retains push removal
 * until the destination cutover is complete.
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
