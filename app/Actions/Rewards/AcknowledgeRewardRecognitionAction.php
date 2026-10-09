<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardComponent;
use App\Models\RewardAward;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Recipient acknowledgment of a recognition award.
 *
 * Recognition is private: only the recipient acknowledges, only once, and
 * only for recognition-component awards in an approved-or-fulfilled state.
 * The acknowledgment stamps acknowledged_at immutably; it is not a second
 * approval and changes no other state.
 */
final readonly class AcknowledgeRewardRecognitionAction
{
    use AsAction;

    public function handle(
        RewardAward|string $award,
        string $recipientId,
        ?CarbonInterface $acknowledgedAt = null,
    ): RewardAward {
        if (! Str::isUuid($recipientId)) {
            throw new InvalidArgumentException('Recipient id must be a UUID.');
        }

        $at = $acknowledgedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($acknowledgedAt)->setTimezone('UTC');

        $awardId = $award instanceof RewardAward ? (string) $award->getKey() : $award;

        return DB::transaction(function () use ($awardId, $recipientId, $at): RewardAward {
            $locked = RewardAward::query()->whereKey($awardId)->lockForUpdate()->first();

            if (! $locked instanceof RewardAward) {
                throw new InvalidArgumentException('Unknown reward award.');
            }

            if ($locked->component !== RewardComponent::Recognition) {
                throw new InvalidArgumentException('Only recognition awards can be acknowledged.');
            }

            if ((string) $locked->recipient_id !== $recipientId) {
                throw new InvalidArgumentException('Only the recipient can acknowledge their recognition.');
            }

            if (! in_array($locked->status, [RewardAwardStatus::Approved, RewardAwardStatus::Fulfilled], true)) {
                throw new InvalidArgumentException('Recognition can only be acknowledged once approved or fulfilled.');
            }

            if ($locked->acknowledged_at !== null) {
                return $locked;
            }

            $locked->forceFill(['acknowledged_at' => $at])->save();

            return $locked->refresh();
        });
    }
}
