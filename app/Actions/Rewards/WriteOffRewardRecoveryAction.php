<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardRecoveryStatus;
use App\Models\RewardRecovery;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Write off an unrecoverable case with authorized evidence. A writeoff
 * closes the receivable without pretending value returned: no budget
 * movement, no silent balance invention. The reason and authorizer stay
 * on the immutable record.
 */
final readonly class WriteOffRewardRecoveryAction
{
    use AsAction;

    public function handle(
        RewardRecovery|string $recovery,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?CarbonInterface $decidedAt = null,
    ): RewardRecovery {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        if ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Recovery writeoff requires a staff/admin user identity.');
        }

        $recoveryId = $recovery instanceof RewardRecovery ? (string) $recovery->getKey() : $recovery;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use ($recoveryId, $reason, $decided): RewardRecovery {
            $locked = RewardRecovery::query()->whereKey($recoveryId)->lockForUpdate()->first();

            if (! $locked instanceof RewardRecovery) {
                throw new InvalidArgumentException('Unknown reward recovery.');
            }

            if ($locked->status !== RewardRecoveryStatus::Open) {
                throw new InvalidArgumentException('Only open recoveries can be written off.');
            }

            $evidence = $locked->evidence ?? [];
            $evidence['writeoff'] = ['reason' => $reason, 'written_off_at' => $decided->toIso8601String()];

            $locked->forceFill([
                'status' => RewardRecoveryStatus::WrittenOff,
                'evidence' => $evidence,
                'written_off_at' => $decided,
            ])->save();

            return $locked->refresh();
        });
    }
}
