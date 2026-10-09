<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Enums\Rewards\RewardPointLotStatus;
use App\Enums\Rewards\RewardPointMovementKind;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Shared point-lot reader and deterministic allocator.
 *
 * All balance math reconciles from persisted movements — lots carry no cached
 * counters. Callers lock candidate lots (sorted by id) before allocating;
 * allocation order itself is oldest-expiring-first for determinism.
 */
final class RewardPointLedger
{
    /**
     * @return Collection<int, RewardPointLot>
     */
    public static function lockCandidateLots(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $unit,
    ): Collection {
        return RewardPointLot::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('family_key', $familyKey)
            ->where('unit', $unit)
            ->where('status', RewardPointLotStatus::Open)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public static function isExpired(RewardPointLot $lot, CarbonImmutable $asOf): bool
    {
        if ($lot->expires_at === null) {
            return false;
        }

        return ! CarbonImmutable::parse($lot->expires_at)->setTimezone('UTC')->greaterThan($asOf);
    }

    /**
     * Unreserved residual regardless of expiry: credited + adjust - spent -
     * reserved - expired. Used by the expiry job to materialize the exact
     * remainder once; never used for spending decisions.
     *
     * @param  Collection<int, RewardPointMovement>  $movements
     */
    public static function unreservedResidual(RewardPointLot $lot, Collection $movements): int
    {
        $credited = self::sumKind($movements, [RewardPointMovementKind::Credit, RewardPointMovementKind::Refund]);
        $adjusts = self::sumKind($movements, [RewardPointMovementKind::Adjust]);
        $spent = self::sumKind($movements, [RewardPointMovementKind::Debit]);
        $expired = self::sumKind($movements, [RewardPointMovementKind::Expire]);

        return $credited + $adjusts - $spent - self::activeReserved($lot, $movements) - $expired;
    }

    /**
     * Spendable availability at the decision boundary. Expired lots return 0
     * without any cron prerequisite; already-reserved obligations survive
     * expiry until release/capture resolves them (they live in the reserved
     * bucket, never in available).
     *
     * @param  Collection<int, RewardPointMovement>  $movements
     */
    public static function lotAvailable(RewardPointLot $lot, Collection $movements, CarbonImmutable $asOf): int
    {
        $availableAt = CarbonImmutable::parse($lot->available_at)->setTimezone('UTC');

        if ($availableAt->greaterThan($asOf)) {
            return 0;
        }

        if (self::isExpired($lot, $asOf)) {
            return 0;
        }

        return self::unreservedResidual($lot, $movements);
    }

    /**
     * @param  Collection<int, RewardPointMovement>  $movements
     */
    public static function activeReserved(RewardPointLot $lot, Collection $movements): int
    {
        $lotMovements = $movements->filter(
            fn (RewardPointMovement $movement): bool => (string) $movement->lot_id === (string) $lot->getKey()
        );
        $linked = $lotMovements
            ->filter(fn (RewardPointMovement $movement): bool => $movement->linked_movement_id !== null)
            ->map(fn (RewardPointMovement $movement): string => (string) $movement->linked_movement_id)
            ->all();

        return (int) $lotMovements
            ->filter(fn (RewardPointMovement $movement): bool => $movement->kind === RewardPointMovementKind::Reserve)
            ->reject(fn (RewardPointMovement $movement): bool => in_array((string) $movement->getKey(), $linked, true))
            ->sum('delta');
    }

    /**
     * Enum-safe collection sum: Eloquent casts kind to an enum instance, so
     * string comparisons would silently match nothing and zero the buckets.
     *
     * @param  Collection<int, RewardPointMovement>  $movements
     * @param  list<RewardPointMovementKind>  $kinds
     */
    public static function sumKind(Collection $movements, array $kinds): int
    {
        return (int) $movements
            ->filter(fn (RewardPointMovement $movement): bool => in_array($movement->kind, $kinds, true))
            ->sum('delta');
    }

    /**
     * Deterministic oldest-expiring-first order: expiry ascending (never-
     * expiring lots last), then credited time, then lot id as the total
     * tiebreak. An explicit comparator keeps the order total and portable.
     *
     * @param  Collection<int, RewardPointLot>  $lots
     * @return Collection<int, RewardPointLot>
     */
    public static function oldestExpiringFirst(Collection $lots): Collection
    {
        return $lots->sort(static function (RewardPointLot $a, RewardPointLot $b): int {
            $aExpiry = $a->expires_at === null
                ? PHP_INT_MAX
                : CarbonImmutable::parse($a->expires_at)->getTimestamp();
            $bExpiry = $b->expires_at === null
                ? PHP_INT_MAX
                : CarbonImmutable::parse($b->expires_at)->getTimestamp();

            if ($aExpiry !== $bExpiry) {
                return $aExpiry <=> $bExpiry;
            }

            $aCredited = CarbonImmutable::parse($a->credited_at)->getTimestamp();
            $bCredited = CarbonImmutable::parse($b->credited_at)->getTimestamp();

            if ($aCredited !== $bCredited) {
                return $aCredited <=> $bCredited;
            }

            return strcmp((string) $a->getKey(), (string) $b->getKey());
        })->values();
    }

    /**
     * @param  Collection<int, RewardPointLot>  $lots
     * @param  Collection<int, RewardPointMovement>  $movements
     * @return array<string, int> lotId => points
     */
    public static function allocate(Collection $lots, Collection $movements, int $points, CarbonImmutable $asOf): array
    {
        $remaining = $points;
        $plan = [];

        foreach (self::oldestExpiringFirst($lots) as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $available = self::lotAvailable(
                $lot,
                $movements->filter(fn (RewardPointMovement $movement): bool => (string) $movement->lot_id === (string) $lot->getKey()),
                $asOf,
            );

            if ($available <= 0) {
                continue;
            }

            $take = min($available, $remaining);
            $plan[(string) $lot->getKey()] = $take;
            $remaining -= $take;
        }

        return $remaining > 0 ? [] : $plan;
    }

    public static function balancesFor(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $unit,
        CarbonImmutable $asOf,
    ): RewardPointBuckets {
        $lots = RewardPointLot::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('family_key', $familyKey)
            ->where('unit', $unit)
            ->get();

        if ($lots->isEmpty()) {
            return new RewardPointBuckets;
        }

        $movements = RewardPointMovement::query()
            ->whereIn('lot_id', $lots->map(fn (RewardPointLot $lot): string => (string) $lot->getKey())->all())
            ->get();

        $buckets = ['pending' => 0, 'available' => 0, 'reserved' => 0, 'spent' => 0, 'expired' => 0];

        foreach ($lots as $lot) {
            $lotMovements = $movements->filter(
                fn (RewardPointMovement $movement): bool => (string) $movement->lot_id === (string) $lot->getKey()
            );
            $availableAt = CarbonImmutable::parse($lot->available_at)->setTimezone('UTC');

            $credited = self::sumKind($lotMovements, [RewardPointMovementKind::Credit, RewardPointMovementKind::Refund])
                + self::sumKind($lotMovements, [RewardPointMovementKind::Adjust]);
            $spent = self::sumKind($lotMovements, [RewardPointMovementKind::Debit]);
            $reserved = self::activeReserved($lot, $lotMovements);
            $expired = self::sumKind($lotMovements, [RewardPointMovementKind::Expire]);
            $residual = $credited - $spent - $reserved - $expired;

            if ($availableAt->greaterThan($asOf)) {
                $buckets['pending'] += $residual;
            } elseif (self::isExpired($lot, $asOf)) {
                $buckets['expired'] += $residual;
            } else {
                $buckets['available'] += $residual;
            }

            $buckets['reserved'] += $reserved;
            $buckets['spent'] += $spent;
            $buckets['expired'] += $expired;
        }

        return RewardPointBuckets::fromArray($buckets);
    }
}
