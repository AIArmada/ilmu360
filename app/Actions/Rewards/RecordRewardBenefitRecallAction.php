<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardBenefitGrantStatus;
use App\Models\RewardBenefitGrant;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Record a manual handoff recall on an issued manual grant.
 *
 * Revocation of a manual grant requires proof the handed-over benefit
 * was actually taken back (a recall reference: return receipt, desk
 * ticket, courier tracking). This action records that proof on the
 * grant evidence exactly once; revocation then verifies it. Coupon
 * grants never take this path — their unused proof is the absence of a
 * canonical voucher usage row.
 */
final readonly class RecordRewardBenefitRecallAction
{
    use AsAction;

    public function handle(
        RewardBenefitGrant|string $grant,
        string $recallReference,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?CarbonInterface $decidedAt = null,
    ): RewardBenefitGrant {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Benefit recall recording requires a staff/admin user identity.');
        }

        $reference = mb_trim($recallReference);

        if ($reference === '' || mb_strlen($reference) > 191) {
            throw new InvalidArgumentException('Recall reference must be 1..191 characters.');
        }

        $grantId = $grant instanceof RewardBenefitGrant ? (string) $grant->getKey() : $grant;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use ($grantId, $reference, $actorType, $actorId, $actorRole, $decided): RewardBenefitGrant {
            $locked = RewardBenefitGrant::query()->whereKey($grantId)->lockForUpdate()->first();

            if (! $locked instanceof RewardBenefitGrant) {
                throw new InvalidArgumentException('Unknown reward benefit grant.');
            }

            if ($locked->status !== RewardBenefitGrantStatus::Issued) {
                throw new InvalidArgumentException('Only issued grants can record a recall.');
            }

            if ((string) $locked->fulfillment_adapter !== 'manual') {
                throw new InvalidArgumentException('Only manual grants record a handoff recall.');
            }

            $evidence = $locked->evidence ?? [];

            if (mb_trim((string) ($evidence['recall_reference'] ?? '')) !== '') {
                throw new InvalidArgumentException('A recall is already recorded for this grant.');
            }

            $evidence['recall_reference'] = $reference;
            $evidence['recall_recorded_at'] = $decided->toIso8601String();
            $evidence['recall_actor'] = ['type' => $actorType, 'id' => $actorId, 'role' => $actorRole];

            $locked->forceFill(['evidence' => $evidence])->save();

            return $locked->refresh();
        });
    }
}
