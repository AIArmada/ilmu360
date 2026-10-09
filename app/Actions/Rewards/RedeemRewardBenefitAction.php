<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardBenefitGrantStatus;
use App\Enums\Rewards\RewardBenefitItemStatus;
use App\Enums\Rewards\RewardBenefitStockKind;
use App\Models\RewardBenefitGrant;
use App\Models\RewardBenefitItem;
use App\Models\RewardPointOperation;
use App\Models\RewardPointReservationSubmission;
use App\Support\Rewards\RewardBenefitStockLedger;
use App\Support\Rewards\RewardLedgerEntries;
use App\Support\Rewards\RewardOperationKeys;
use App\Support\Rewards\RewardPointOperationAuthority;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Point-funded benefit redemption: one atomic lot + stock reservation.
 *
 * The member (or staff on their behalf) spends frozen point terms for a
 * catalog unit. One transaction locks the catalog item, verifies live
 * availability from locked stock rows, reserves the points (lots in
 * sorted order inside Reserve), creates the grant, reserves stock, and
 * marks the reservation sending — so a racing award reversal either
 * voids first (redemption then refuses on insufficient available value)
 * or observes the sending handoff and refuses itself. Exactly one wins;
 * nothing half-reserves.
 *
 * Identity is the caller-supplied redemption key (`redeem:{key}` grant,
 * `rsv:redeem:{key}` point reservation), BOUND to the full terms:
 * recipient, item, quantity, adapter, family, unit, and frozen point
 * total. Replays with identical terms return the stored grant; any term
 * mismatch is refused without returning the stored grant's data and
 * without side effects. A concurrent same-key winner is resolved
 * PostgreSQL-safely outside the rolled-back transaction, collapsing onto
 * the winner only when every bound term matches.
 *
 * Pending/reserved points are never spendable — reservation draws from
 * available value only — and expired lots never fund redemption.
 */
final readonly class RedeemRewardBenefitAction
{
    use AsAction;

    public const array ADAPTERS = ['manual', 'coupon'];

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public function handle(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        RewardBenefitItem|string $item,
        int $quantity,
        string $redemptionKey,
        string $adapter,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?array $evidence = null,
        ?CarbonInterface $decidedAt = null,
    ): RewardBenefitGrant {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if ($actorType !== 'user' || ! in_array($actorRole, ['member', 'staff', 'admin'], true)) {
            throw new InvalidArgumentException('Benefit redemption requires a user identity.');
        }

        if ($actorRole === 'member' && $actorId !== $recipientId) {
            throw new InvalidArgumentException('Members redeem only their own benefits.');
        }

        if ($quantity < 1 || $quantity > 1000) {
            throw new InvalidArgumentException('Redemption quantity must be 1..1000.');
        }

        if (! in_array($adapter, self::ADAPTERS, true)) {
            throw new InvalidArgumentException('Fulfillment adapter must be manual or coupon.');
        }

        RewardOperationKeys::assertValid($redemptionKey);

        $grantKey = "redeem:{$redemptionKey}";

        if (mb_strlen($grantKey) > 191) {
            throw new InvalidArgumentException('Redemption key is too long for the grant identity.');
        }

        $reservationKey = "rsv:redeem:{$redemptionKey}";

        if (mb_strlen($reservationKey) > 191) {
            throw new InvalidArgumentException('Redemption key is too long for the reservation identity.');
        }

        $itemId = $item instanceof RewardBenefitItem ? (string) $item->getKey() : $item;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn (): RewardBenefitGrant => $this->attempt(
                    $recipientType, $recipientId, $familyKey, $itemId, $quantity,
                    $grantKey, $reservationKey, $adapter,
                    $actorType, $actorId, $actorRole, $evidence, $decided,
                ));
            } catch (QueryException $exception) {
                if (self::isDeadlock($exception) && $attempt < 3) {
                    continue;
                }

                // A concurrent same-key redemption won the grant insert;
                // collapse onto it only when its bound terms match. Every
                // catch sits outside the transaction, PostgreSQL-safe.
                if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                    throw $exception;
                }

                $winner = RewardBenefitGrant::query()->where('grant_key', $grantKey)->first();

                if (! $winner instanceof RewardBenefitGrant) {
                    throw $exception;
                }

                self::assertReplayBinds(
                    $winner, $recipientType, $recipientId, $itemId, $quantity,
                    $adapter, $familyKey, false,
                );

                return $winner;
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    private function attempt(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $itemId,
        int $quantity,
        string $grantKey,
        string $reservationKey,
        string $adapter,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?array $evidence,
        CarbonImmutable $decided,
    ): RewardBenefitGrant {
        $replay = RewardBenefitGrant::query()->where('grant_key', $grantKey)->lockForUpdate()->first();

        if ($replay instanceof RewardBenefitGrant) {
            self::assertReplayBinds(
                $replay, $recipientType, $recipientId, $itemId, $quantity,
                $adapter, $familyKey, true,
            );

            return $replay;
        }

        // Item first, then stock, then lots: the canonical benefit order
        // (grant → item → stock → lots → movements → submission).
        // Fulfillment takes grant → item → lots, so both converge through
        // item → lots and no path ever inverts.
        $itemRow = RewardBenefitItem::query()->whereKey($itemId)->lockForUpdate()->first();

        if (! $itemRow instanceof RewardBenefitItem) {
            throw new InvalidArgumentException('Unknown reward benefit item.');
        }

        if ($itemRow->direct_entitlement) {
            throw new InvalidArgumentException('Direct-entitlement benefits are granted by award, not redeemed for points.');
        }

        if ($itemRow->cost_points === null || $itemRow->cost_unit === null) {
            throw new InvalidArgumentException('Benefit item carries no point cost.');
        }

        if ($itemRow->status !== RewardBenefitItemStatus::Active) {
            throw new InvalidArgumentException('Benefit item is retired; new redemptions are closed.');
        }

        if ($itemRow->expires_at !== null && ! $itemRow->expires_at->greaterThan($decided)) {
            throw new InvalidArgumentException('Benefit item has expired.');
        }

        $balances = RewardBenefitStockLedger::lockedBalances($itemRow);

        if ($balances['available'] < $quantity) {
            throw new InvalidArgumentException('Insufficient benefit stock for the requested quantity.');
        }

        $total = ((int) $itemRow->cost_points) * $quantity;
        $unit = (string) $itemRow->cost_unit;

        ReserveRewardPointsAction::run(
            $recipientType, $recipientId, $familyKey, $unit, $total, $reservationKey,
            "benefit-redemption:{$grantKey}", $decided, $actorType, $actorId,
        );

        $grant = RewardBenefitGrant::query()->create([
            'grant_key' => $grantKey,
            'item_id' => $itemRow->getKey(),
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'award_id' => null,
            'reservation_key' => $reservationKey,
            'quantity' => $quantity,
            'status' => RewardBenefitGrantStatus::Reserved,
            'fulfillment_adapter' => $adapter,
            'evidence' => $evidence,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'reserved_at' => $decided,
        ]);

        RewardBenefitStockLedger::append(
            RewardBenefitStockKind::Reserve, "stock-reserve:{$grantKey}",
            (string) $itemRow->getKey(), $quantity, (string) $grant->getKey(),
            $actorType, $actorId, $actorRole, $decided,
        );

        // Lots are held by this transaction; pending pieces exist, so
        // the handoff mark commits atomically with the reservation.
        MarkRewardReservationSubmittedAction::run(
            $reservationKey, RewardPointReservationSubmission::STATUS_SENDING, $grantKey, $decided,
        );

        return $grant->refresh();
    }

    /**
     * Term-bound replay: every caller-visible term plus the frozen
     * point-reservation terms must match. Refusals name no stored data,
     * so a foreign caller learns nothing about the bound grant.
     */
    private static function assertReplayBinds(
        RewardBenefitGrant $winner,
        string $recipientType,
        string $recipientId,
        string $itemId,
        int $quantity,
        string $adapter,
        string $familyKey,
        bool $insideTransaction,
    ): void {
        $mismatch = (string) $winner->recipient_type !== $recipientType
            || (string) $winner->recipient_id !== $recipientId
            || (string) $winner->item_id !== $itemId
            || (int) $winner->quantity !== $quantity
            || (string) $winner->fulfillment_adapter !== $adapter;

        $operation = null;

        if ($winner->reservation_key !== null) {
            $query = RewardPointOperation::query()
                ->where('kind', RewardPointOperationAuthority::KIND_RESERVE)
                ->where('operation_key', (string) $winner->reservation_key);

            $operation = $insideTransaction
                ? $query->lockForUpdate()->first()
                : $query->first();
        }

        if (! $operation instanceof RewardPointOperation) {
            $mismatch = true;
        } elseif ((string) $operation->family_key !== $familyKey
            || (string) $operation->recipient_type !== $recipientType
            || (string) $operation->recipient_id !== $recipientId
            || (string) $operation->unit === ''
        ) {
            $mismatch = true;
        }

        if ($mismatch) {
            throw new InvalidArgumentException('Redemption key is already bound to different terms; supply a fresh key.');
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
}
