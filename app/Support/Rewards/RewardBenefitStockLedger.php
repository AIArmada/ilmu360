<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Actions\Rewards\CreateRewardProgramAction;
use App\Enums\Rewards\RewardBenefitStockKind;
use App\Models\RewardBenefitItem;
use App\Models\RewardBenefitStockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Benefit stock ledger: available units derive from immutable rows only.
 *
 * available = capacity + restock - reserved - consume, where
 * reserved = reserve - release - consume (consuming converts the hold,
 * so consumed units leave the reserved bucket). Writers hold the
 * item row lock before appending, and every availability DECISION reads
 * through lockedBalances(): the movement rows are locked, so the decision
 * is current on every isolation — a locking read of the item row alone
 * does not refresh a plain aggregate of movement rows under REPEATABLE
 * READ. Lock order: grant → item → stock movements → lots (sorted) →
 * point movements (sorted) → submission → leaf inserts. Redemption takes
 * item → stock → lots; fulfillment/cancel/expire/revoke take the grant
 * first and converge through the same suffix, so no path ever inverts.
 */
final class RewardBenefitStockLedger
{
    /**
     * @return array{capacity: int, reserved: int, consumed: int, available: int}
     */
    public static function balances(RewardBenefitItem $item): array
    {
        $byKind = RewardBenefitStockMovement::query()
            ->where('item_id', $item->getKey())
            ->selectRaw('kind, COALESCE(SUM(quantity), 0) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        $reserved = (int) ($byKind[RewardBenefitStockKind::Reserve->value] ?? 0)
            - (int) ($byKind[RewardBenefitStockKind::Release->value] ?? 0)
            - (int) ($byKind[RewardBenefitStockKind::Consume->value] ?? 0);
        $consumed = (int) ($byKind[RewardBenefitStockKind::Consume->value] ?? 0);
        $restocked = (int) ($byKind[RewardBenefitStockKind::Restock->value] ?? 0);
        $capacity = (int) $item->capacity;

        return [
            'capacity' => $capacity,
            'reserved' => $reserved,
            'consumed' => $consumed,
            'available' => $capacity + $restocked - $reserved - $consumed,
        ];
    }

    /**
     * Locking availability read for reservation decisions. The caller must
     * already hold the item row lock; this locks the item's movement rows
     * in id order and aggregates in PHP (PostgreSQL forbids aggregates
     * under lock), so concurrent redeemers serialize instead of
     * over-reserving from a stale snapshot.
     *
     * @return array{capacity: int, reserved: int, consumed: int, available: int}
     */
    public static function lockedBalances(RewardBenefitItem $item): array
    {
        $rows = RewardBenefitStockMovement::query()
            ->where('item_id', $item->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $byKind = [];

        foreach ($rows as $row) {
            $kind = $row->kind instanceof RewardBenefitStockKind ? $row->kind->value : (string) $row->kind;
            $byKind[$kind] = ($byKind[$kind] ?? 0) + (int) $row->quantity;
        }

        $reserved = (int) ($byKind[RewardBenefitStockKind::Reserve->value] ?? 0)
            - (int) ($byKind[RewardBenefitStockKind::Release->value] ?? 0)
            - (int) ($byKind[RewardBenefitStockKind::Consume->value] ?? 0);
        $consumed = (int) ($byKind[RewardBenefitStockKind::Consume->value] ?? 0);
        $restocked = (int) ($byKind[RewardBenefitStockKind::Restock->value] ?? 0);
        $capacity = (int) $item->capacity;

        return [
            'capacity' => $capacity,
            'reserved' => $reserved,
            'consumed' => $consumed,
            'available' => $capacity + $restocked - $reserved - $consumed,
        ];
    }

    public static function append(
        RewardBenefitStockKind $kind,
        string $movementKey,
        string $itemId,
        int $quantity,
        ?string $grantId,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        CarbonImmutable $decided,
    ): RewardBenefitStockMovement {
        if ($movementKey === '' || mb_strlen($movementKey) > 191) {
            throw new InvalidArgumentException('Stock movement key must be 1..191 characters.');
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('Stock movement quantity must be positive.');
        }

        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        try {
            return DB::transaction(static fn (): RewardBenefitStockMovement => RewardBenefitStockMovement::query()->create([
                'movement_key' => $movementKey,
                'item_id' => $itemId,
                'kind' => $kind,
                'grant_id' => $grantId,
                'quantity' => $quantity,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_role' => $actorRole,
                'decided_at' => $decided,
            ]), 1);
        } catch (QueryException $exception) {
            if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = RewardBenefitStockMovement::query()->where('movement_key', $movementKey)->first();

            if ($existing instanceof RewardBenefitStockMovement
                && $existing->kind === $kind
                && (int) $existing->quantity === $quantity
            ) {
                return $existing;
            }

            throw new InvalidArgumentException('Stock movement key is already bound to different terms; supply a fresh key.', 0, $exception);
        }
    }
}
