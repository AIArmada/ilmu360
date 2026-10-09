<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use InvalidArgumentException;

/**
 * Stable semantic operation keys for point movements and adjustments.
 *
 * Every key is rooted in a canonical business source (award, reservation,
 * lot-expiry, linked operation) plus the movement kind, so retries and
 * replays collapse onto the same rows instead of minting duplicates.
 * Keys are bounded to 191 characters for indexed storage.
 */
final class RewardOperationKeys
{
    public const int MAX_LENGTH = 191;

    public static function credit(string $scope, string $rootId, string $unit): string
    {
        return self::bounded("credit:{$scope}:{$rootId}:{$unit}");
    }

    public static function aggregate(string $kind, string $rootType, string $rootId, string $unit, int $points): string
    {
        return self::bounded("{$kind}:{$rootType}:{$rootId}:{$unit}:{$points}");
    }

    public static function lotAllocation(string $aggregateKey, string $lotId): string
    {
        $digest = substr(hash('sha256', $aggregateKey.':'.$lotId), 0, 32);

        return self::bounded("{$aggregateKey}:lot:{$digest}");
    }

    public static function reservationRelease(string $reservationKey, string $lotId): string
    {
        $digest = substr(hash('sha256', $reservationKey.':'.$lotId), 0, 32);

        return self::bounded("release:{$reservationKey}:{$digest}");
    }

    public static function lotExpiry(string $lotId, string $expiresAt): string
    {
        $digest = substr(hash('sha256', $lotId.':'.$expiresAt), 0, 32);

        return self::bounded("expire:{$digest}");
    }

    public static function refund(string $linkedAggregateKey, int $sequence): string
    {
        return self::bounded("refund:{$linkedAggregateKey}:{$sequence}");
    }

    public static function adjustment(string $kind, string $rootType, string $rootId, string $nonce): string
    {
        return self::bounded("{$kind}:{$rootType}:{$rootId}:{$nonce}");
    }

    private static function bounded(string $key): string
    {
        if (mb_strlen($key) <= self::MAX_LENGTH) {
            return $key;
        }

        $digest = hash('sha256', $key);

        return substr($key, 0, self::MAX_LENGTH - 65).':'.$digest;
    }

    public static function assertValid(string $key): void
    {
        if ($key === '' || mb_strlen($key) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Operation key must be 1..191 characters.');
        }
    }
}
