<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Vouchers\Actions\ExpireVoucher;
use AIArmada\Vouchers\Models\Voucher as VoucherModel;
use AIArmada\Vouchers\Models\VoucherUsage;
use App\Enums\Rewards\RewardBenefitGrantStatus;
use App\Enums\Rewards\RewardBenefitStockKind;
use App\Enums\Rewards\RewardPointMovementKind;
use App\Models\RewardBenefitGrant;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Support\Rewards\RewardBenefitStockLedger;
use App\Support\Rewards\RewardLedgerEntries;
use App\Support\Rewards\RewardLedgerLockOrder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Revoke an issued-but-unused grant: restore once, never resurrect.
 *
 * Coupon grants revoke only when no canonical usage row exists for the
 * issued code; the voucher is expired so the wallet entry dies with the
 * grant. Captured points return through a linked refund (frozen lot/
 * refund/expiry terms apply — post-expiry refunds follow the ledger's
 * after-expiry truthfulness, never silent perpetuation). Consumed stock
 * returns through a restock movement. Consumed grants never revoke here —
 * recovery of consumed value is a separate authorized recovery case.
 */
final readonly class RevokeRewardBenefitGrantAction
{
    use AsAction;

    public function handle(
        RewardBenefitGrant|string $grant,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?CarbonInterface $decidedAt = null,
    ): RewardBenefitGrant {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        if ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Grant revocation requires a staff/admin user identity.');
        }

        $grantId = $grant instanceof RewardBenefitGrant ? (string) $grant->getKey() : $grant;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use ($grantId, $reason, $decided, $actorType, $actorId, $actorRole): RewardBenefitGrant {
            $locked = RewardBenefitGrant::query()->whereKey($grantId)->lockForUpdate()->first();

            if (! $locked instanceof RewardBenefitGrant) {
                throw new InvalidArgumentException('Unknown reward benefit grant.');
            }

            if ($locked->status !== RewardBenefitGrantStatus::Issued) {
                throw new InvalidArgumentException('Only issued grants can be revoked.');
            }

            if ((string) $locked->fulfillment_adapter === 'coupon') {
                $this->revokeCoupon($locked);
            } else {
                $evidence = $locked->evidence ?? [];
                $recall = mb_trim((string) ($evidence['recall_reference'] ?? ''));

                if ($recall === '') {
                    throw new InvalidArgumentException('Manual revocation requires recall_reference evidence on the grant; record the recall first.');
                }
            }

            if ($locked->reservation_key !== null) {
                $this->refundCapture($locked, $reason, $decided, $actorType, $actorId);
            }

            RewardBenefitStockLedger::append(
                RewardBenefitStockKind::Restock, "stock-restock:{$locked->grant_key}",
                (string) $locked->item_id, (int) $locked->quantity, (string) $locked->getKey(),
                $actorType, $actorId, $actorRole, $decided,
            );

            $evidence = $locked->evidence ?? [];
            $evidence['revocation'] = ['reason' => $reason, 'revoked_at' => $decided->toIso8601String()];

            $locked->forceFill([
                'status' => RewardBenefitGrantStatus::Revoked,
                'evidence' => $evidence,
                'revoked_at' => $decided,
            ])->save();

            return $locked->refresh();
        });
    }

    private function revokeCoupon(RewardBenefitGrant $grant): void
    {
        // The voucher row lock serializes revocation against usage
        // recording (which holds the same row while inserting) and
        // against consumption (which locks voucher → usage the same
        // way): either the use commits first and revocation refuses, or
        // revocation commits first and later usage is refused as expired.
        $voucher = VoucherModel::query()
            ->where('code', (string) $grant->external_ref)
            ->lockForUpdate()
            ->first();

        if (! $voucher instanceof VoucherModel) {
            throw new InvalidArgumentException('Issued coupon is missing; refusing revocation.');
        }

        $used = VoucherUsage::query()
            ->where('voucher_id', $voucher->getKey())
            ->lockForUpdate()
            ->exists();

        if ($used) {
            throw new InvalidArgumentException('Coupon was used; revocation is closed — open a recovery case instead.');
        }

        app(ExpireVoucher::class)->handle((string) $grant->external_ref);
    }

    private function refundCapture(
        RewardBenefitGrant $grant,
        string $reason,
        CarbonImmutable $decided,
        string $actorType,
        ?string $actorId,
    ): void {
        // Canonical lots-before-movements order, matching fulfill and
        // every point path: lock the touched lots sorted first, then the
        // capture pieces.
        $lotIds = RewardPointMovement::query()
            ->where('kind', RewardPointMovementKind::Debit)
            ->where('reservation_key', (string) $grant->reservation_key)
            ->orderBy('id')
            ->pluck('lot_id')
            ->map(static fn ($lotId): string => (string) $lotId)
            ->unique()
            ->values()
            ->all();

        if ($lotIds === []) {
            throw new InvalidArgumentException('Grant capture is missing; refusing revocation.');
        }

        foreach (RewardLedgerLockOrder::sorted($lotIds) as $lotId) {
            RewardPointLot::query()->whereKey($lotId)->lockForUpdate()->first();
        }

        $capture = RewardPointMovement::query()
            ->where('kind', RewardPointMovementKind::Debit)
            ->where('reservation_key', (string) $grant->reservation_key)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($capture->isEmpty()) {
            throw new InvalidArgumentException('Grant capture is missing; refusing revocation.');
        }

        $byAggregate = $capture->groupBy(static fn (RewardPointMovement $movement): string => (string) $movement->aggregate_operation_key);

        foreach ($byAggregate as $aggregateKey => $movements) {
            $points = (int) $movements->sum('delta');

            if ($points <= 0) {
                continue;
            }

            RefundRewardPointsAction::run(
                "refund:{$aggregateKey}:revoke", $aggregateKey, $points,
                "benefit-revoke:{$reason}", $decided, $actorType, $actorId,
            );
        }
    }
}
