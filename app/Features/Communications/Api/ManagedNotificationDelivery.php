<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

interface ManagedNotificationDelivery
{
    public function adoptOrDispatch(ManagedNotificationDispatchRequest $request): ?ManagedCommunicationSnapshot;

    public function snapshot(string $communicationId): ?ManagedCommunicationSnapshot;

    public function cancelBeforeSend(string $communicationId, string $reason): bool;

    public function resolveAmbiguousExternalDelivery(
        string $deliveryId,
        string $decision,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?string $providerMessageId = null,
        ?string $reason = null,
    ): ManagedDeliveryResolutionSnapshot;
}
