<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardBenefitItemStatus;
use App\Models\RewardBenefitItem;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Retire a benefit catalog item. Outstanding grants keep reconciling
 * against frozen terms; only new redemptions and new direct grants stop.
 */
final readonly class RetireRewardBenefitItemAction
{
    use AsAction;

    public function handle(
        RewardBenefitItem|string $item,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?CarbonInterface $decidedAt = null,
    ): RewardBenefitItem {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        if ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Benefit catalog changes require a staff/admin user identity.');
        }

        $itemId = $item instanceof RewardBenefitItem ? (string) $item->getKey() : $item;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use ($itemId, $decided): RewardBenefitItem {
            $locked = RewardBenefitItem::query()->whereKey($itemId)->lockForUpdate()->first();

            if (! $locked instanceof RewardBenefitItem) {
                throw new InvalidArgumentException('Unknown reward benefit item.');
            }

            if ($locked->status === RewardBenefitItemStatus::Retired) {
                return $locked;
            }

            $locked->forceFill([
                'status' => RewardBenefitItemStatus::Retired,
                'retired_at' => $decided,
            ])->save();

            return $locked->refresh();
        });
    }
}
