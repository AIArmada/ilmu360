<?php

namespace App\Features\Communications\Api;

interface DeliveryFencing
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function recordExternalSent(
        string $communicationId,
        string $deliveryId,
        string $attemptId,
        array $result = [],
    ): bool;

    public function recordExternalFailure(
        string $communicationId,
        string $deliveryId,
        string $attemptId,
        string $failureMessage,
        ?string $failureCode = null,
    ): bool;

    public function isAmbiguousExternal(string $deliveryId): bool;

    /**
     * @param  array<string, string>  $deliveryIdsByChannel
     */
    public function recordNotificationFailure(
        string $communicationId,
        array $deliveryIdsByChannel,
        string $failureMessage,
    ): void;
}
