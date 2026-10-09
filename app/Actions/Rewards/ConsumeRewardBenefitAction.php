<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Vouchers\Models\Voucher as VoucherModel;
use AIArmada\Vouchers\Models\VoucherUsage;
use App\Enums\Rewards\RewardBenefitGrantStatus;
use App\Models\RewardBenefitGrant;
use App\Models\RewardPointReservationSubmission;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Record actual benefit use: issued → consumed, exactly once.
 *
 * Manual grants consume on recipient/staff use evidence. Coupon grants
 * consume ONLY on canonical voucher usage: the evidence may cite the
 * recorded VoucherUsage row, or omit it to auto-resolve the recipient's
 * single canonical usage for the issued code. The usage is verified to
 * belong to the voucher AND the grant recipient — no matching usage
 * row, no consumption. Issuance is never confused with use.
 */
final readonly class ConsumeRewardBenefitAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $useEvidence
     */
    public function handle(
        RewardBenefitGrant|string $grant,
        array $useEvidence,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?CarbonInterface $decidedAt = null,
    ): RewardBenefitGrant {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if ($actorType !== 'user' || ! in_array($actorRole, ['member', 'staff', 'admin'], true)) {
            throw new InvalidArgumentException('Benefit consumption requires a user identity.');
        }

        $grantId = $grant instanceof RewardBenefitGrant ? (string) $grant->getKey() : $grant;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use ($grantId, $useEvidence, $actorId, $actorRole, $decided): RewardBenefitGrant {
            $locked = RewardBenefitGrant::query()->whereKey($grantId)->lockForUpdate()->first();

            if (! $locked instanceof RewardBenefitGrant) {
                throw new InvalidArgumentException('Unknown reward benefit grant.');
            }

            if ($locked->status !== RewardBenefitGrantStatus::Issued) {
                throw new InvalidArgumentException('Only issued grants can be consumed.');
            }

            if ($actorRole === 'member' && $actorId !== (string) $locked->recipient_id) {
                throw new InvalidArgumentException('Members consume only their own benefits.');
            }

            if ((string) $locked->fulfillment_adapter === 'coupon') {
                $this->assertCanonicalUsage($locked, $useEvidence);
            } else {
                $useRef = mb_trim((string) ($useEvidence['use_reference'] ?? ''));

                if ($useRef === '' || mb_strlen($useRef) > 191) {
                    throw new InvalidArgumentException('Manual consumption requires use_reference evidence of 1..191 characters.');
                }
            }

            if ($locked->reservation_key !== null
                && (string) $locked->fulfillment_adapter === 'coupon'
            ) {
                MarkRewardReservationSubmittedAction::run(
                    (string) $locked->reservation_key,
                    RewardPointReservationSubmission::STATUS_DELIVERED,
                    (string) $locked->external_ref, $decided,
                );
            }

            $evidence = $locked->evidence ?? [];
            $evidence['consumption'] = $useEvidence;

            $locked->forceFill([
                'status' => RewardBenefitGrantStatus::Consumed,
                'evidence' => $evidence,
                'consumed_at' => $decided,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Canonical coupon usage: the cited (or auto-resolved) usage row must
     * belong to the issued voucher AND to the grant recipient — wallet
     * association alone is not eligibility. Voucher → usage rows lock in
     * the same order revocation takes, so consume-vs-revoke converges.
     *
     * @param  array<string, mixed>  $useEvidence
     */
    private function assertCanonicalUsage(RewardBenefitGrant $grant, array $useEvidence): void
    {
        $voucher = VoucherModel::query()
            ->where('code', (string) $grant->external_ref)
            ->lockForUpdate()
            ->first();

        if (! $voucher instanceof VoucherModel) {
            throw new InvalidArgumentException('Issued coupon is missing; refusing consumption.');
        }

        $usageId = $useEvidence['voucher_usage_id'] ?? null;

        if (is_string($usageId) && $usageId !== '') {
            $usage = VoucherUsage::query()
                ->whereKey($usageId)
                ->where('voucher_id', $voucher->getKey())
                ->lockForUpdate()
                ->first();

            if (! $usage instanceof VoucherUsage) {
                throw new InvalidArgumentException('No canonical usage exists for the issued coupon; refusing consumption.');
            }
        } else {
            $matches = VoucherUsage::query()
                ->where('voucher_id', $voucher->getKey())
                ->where('redeemed_by_type', (string) $grant->recipient_type)
                ->where('redeemed_by_id', (string) $grant->recipient_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($matches->isEmpty()) {
                throw new InvalidArgumentException('No canonical usage exists for the issued coupon; refusing consumption.');
            }

            if ($matches->count() > 1) {
                throw new InvalidArgumentException('Several usages exist for the issued coupon; cite the voucher_usage_id explicitly.');
            }

            $usage = $matches->firstOrFail();
        }

        if ((string) $usage->redeemed_by_type !== (string) $grant->recipient_type
            || (string) $usage->redeemed_by_id !== (string) $grant->recipient_id
        ) {
            throw new InvalidArgumentException('The cited usage was not redeemed by the grant recipient; refusing consumption.');
        }
    }
}
