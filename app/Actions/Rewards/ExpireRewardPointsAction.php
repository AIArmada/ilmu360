<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardPointLotStatus;
use App\Enums\Rewards\RewardPointMovementKind;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Support\Rewards\RewardOperationKeys;
use App\Support\Rewards\RewardPointLedger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Lot expiry: consumes only the unreserved remainder of lots whose expiry
 * has passed. Active reservations survive expiry until their linked
 * fulfillment resolves (release-after-expiry becomes an expiry movement, not
 * spendable value). Replays skip lots that already expired.
 *
 * @return list<RewardPointMovement>
 */
final readonly class ExpireRewardPointsAction
{
    use AsAction;

    /**
     * @return list<RewardPointMovement>
     */
    public function handle(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $unit,
        CarbonInterface $asOf,
        string $reason = 'lot-expiry',
        string $actorType = 'system',
        ?string $actorId = null,
    ): array {
        CreditRewardPointsAction::assertRecipient($recipientType, $recipientId);
        CreditRewardPointsAction::assertFamily($familyKey);
        CreditRewardPointsAction::assertUnit($unit);
        CreditRewardPointsAction::assertActor($actorType, $actorId);
        CreditRewardPointsAction::assertReason($reason);

        $at = CarbonImmutable::parse($asOf)->setTimezone('UTC');

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn (): array => $this->attempt(
                    $recipientType, $recipientId, $familyKey, $unit, $at, $reason, $actorType, $actorId,
                ));
            } catch (QueryException $exception) {
                // Deadlock victims and lost expiry-key racers retry; the
                // retry observes the winner's expired lots and returns []
                // (every catch sits outside the transaction, PostgreSQL-safe).
                if ($attempt >= 3 || (! self::isDeadlock($exception) && ! CreateRewardProgramAction::isUniqueViolation($exception))) {
                    throw $exception;
                }
            }
        }
    }

    private static function isDeadlock(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();

        if (in_array($code, ['40001', '1213'], true)) {
            return true;
        }

        return stripos($exception->getMessage(), 'deadlock') !== false;
    }

    /**
     * @return list<RewardPointMovement>
     */
    private function attempt(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $unit,
        CarbonImmutable $at,
        string $reason,
        string $actorType,
        ?string $actorId,
    ): array {
        $lots = RewardPointLot::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('family_key', $familyKey)
            ->where('unit', $unit)
            ->where('status', RewardPointLotStatus::Open)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($lots->isEmpty()) {
            return [];
        }

        $movements = RewardPointMovement::query()
            ->whereIn('lot_id', $lots->map(fn (RewardPointLot $lot): string => (string) $lot->getKey())->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $created = [];

        foreach ($lots as $lot) {
            if ($lot->expired_at !== null) {
                continue;
            }

            $lotMovements = $movements->where('lot_id', (string) $lot->getKey());
            $remainder = RewardPointLedger::unreservedResidual($lot, $lotMovements);

            if ($remainder < 0) {
                throw new InvalidArgumentException('Lot balance is incoherent; refusing to expire.');
            }

            if ($remainder > 0) {
                $key = RewardOperationKeys::lotExpiry(
                    (string) $lot->getKey(),
                    CarbonImmutable::parse($lot->expires_at)->setTimezone('UTC')->toIso8601String(),
                );

                if (RewardPointMovement::query()->where('operation_key', $key)->exists()) {
                    $lot->forceFill(['expired_at' => $at])->save();

                    continue;
                }

                $created[] = RewardPointMovement::query()->create([
                    'lot_id' => $lot->getKey(),
                    'kind' => RewardPointMovementKind::Expire,
                    'delta' => $remainder,
                    'operation_key' => $key,
                    'aggregate_operation_key' => $key,
                    'linked_movement_id' => null,
                    'reservation_key' => null,
                    'award_id' => $lot->award_id,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'reason' => $reason,
                    'occurred_at' => $at,
                ]);
            }

            $reserved = RewardPointLedger::activeReserved($lot, $lotMovements);

            $lot->forceFill([
                'expired_at' => $at,
                ...($reserved === 0 ? [
                    'status' => RewardPointLotStatus::Expired,
                ] : []),
            ])->save();
        }

        return $created;
    }
}
