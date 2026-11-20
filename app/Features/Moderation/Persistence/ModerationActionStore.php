<?php

declare(strict_types=1);

namespace App\Features\Moderation\Persistence;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Moderation\Api\ModerationActionHistory;
use App\Features\Moderation\Api\RecordedModerationAction;
use App\Features\Moderation\Api\RecordModerationAction;
use App\Features\Moderation\Models\ModerationReview;
use App\Models\User;

/**
 * Canonical persistence for recorded moderation actions.
 *
 * Creation runs under the global owner context, matching the event moderation
 * transitions that previously created review rows directly. Account detach and
 * restore only rewrite the acting-account link and keep the review rows.
 */
final readonly class ModerationActionStore implements ModerationActionHistory, RecordModerationAction
{
    public function record(array $attributes): RecordedModerationAction
    {
        /** @var ModerationReview $review */
        $review = OwnerContext::withOwner(null, static fn (): ModerationReview => ModerationReview::create($attributes));

        return new RecordedModerationAction(
            id: (string) $review->getKey(),
            notes: $review->notes,
        );
    }

    public function idsForAccount(string $accountId): array
    {
        return ModerationReview::query()
            ->where('actioned_by_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function detachAccount(string $accountId): void
    {
        ModerationReview::query()
            ->where('actioned_by_id', $accountId)
            ->update([
                'actioned_by_type' => null,
                'actioned_by_id' => null,
            ]);
    }

    public function restoreAccount(string $accountId, array $actionIds): void
    {
        if ($actionIds === []) {
            return;
        }

        ModerationReview::query()
            ->whereKey($actionIds)
            ->whereNull('actioned_by_id')
            ->update([
                'actioned_by_type' => (new User)->getMorphClass(),
                'actioned_by_id' => $accountId,
            ]);
    }

    public function deleteForActionable(string $actionableType, string $actionableId): void
    {
        ModerationReview::query()
            ->where('actionable_type', $actionableType)
            ->where('actionable_id', $actionableId)
            ->each(static function (ModerationReview $review): void {
                OwnerContext::withOwner(null, static fn () => $review->delete());
            });
    }
}
