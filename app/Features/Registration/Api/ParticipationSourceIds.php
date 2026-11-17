<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

/**
 * Lock qualified registration and check-in source IDs for cancellation.
 * Callers execute both reads within the source cancellation transaction.
 */
interface ParticipationSourceIds
{
    /** @return list<string> */
    public function registrationIds(string $scope, string $scopeId): array;

    /** @return list<string> */
    public function checkinIds(string $scope, string $scopeId): array;
}
