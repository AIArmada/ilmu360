<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardPayeeStatus;
use App\Models\RewardPayee;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Staff rejection of a pending payee registration, with reason evidence.
 * Only Pending rows reject; the recipient keeps any live Verified
 * destination and may register again.
 */
final readonly class RejectRewardPayeeAction
{
    use AsAction;

    public function handle(
        RewardPayee $payee,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?CarbonInterface $decidedAt = null,
    ): RewardPayee {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Payee rejection requires a staff/admin user identity.');
        }

        $reason = mb_trim($reason);

        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('Payee rejection needs a reason of 1..2000 characters.');
        }

        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use ($payee, $actorId, $reason, $decided): RewardPayee {
            $locked = RewardPayee::query()->whereKey($payee->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== RewardPayeeStatus::Pending) {
                throw new InvalidArgumentException('Only a pending payee registration can be rejected.');
            }

            if ($actorId === (string) $locked->recipient_id) {
                throw new InvalidArgumentException('Payee self-decision is prohibited: the decider must not be the recipient.');
            }

            $evidence = $locked->evidence ?? [];
            $evidence['rejection_reason'] = $reason;
            $evidence['decided_by'] = $actorId;

            $locked->forceFill([
                'status' => RewardPayeeStatus::Rejected,
                'evidence' => $evidence,
                'rejected_at' => $decided,
            ])->save();

            return $locked;
        });
    }
}
