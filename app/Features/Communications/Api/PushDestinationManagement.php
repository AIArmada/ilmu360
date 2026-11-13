<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

interface PushDestinationManagement
{
    public function register(string $accountId, string $installationId, PushDestinationDetails $details): PushDestinationSnapshot;

    public function update(string $accountId, string $installationId, PushDestinationDetails $details): ?PushDestinationSnapshot;

    public function deleteByInstallation(string $accountId, string $installationId): void;

    public function removeById(string $accountId, string $destinationId): bool;

    /**
     * @return list<PushDestinationSnapshot>
     */
    public function forNotification(string $accountId): array;

    /**
     * @return list<PushDestinationSnapshot>
     */
    public function forFallbackDelivery(string $accountId): array;
}
