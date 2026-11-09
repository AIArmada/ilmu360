<?php

declare(strict_types=1);

namespace App\Support\Moderation;

use App\Models\ModerationReview;
use App\Models\User;
use App\Shared\Auth\AccountLifecycleContributor;

/**
 * Preserves moderation review attribution across account deletion and
 * restoration.
 *
 * Review records are retained; only the acting account link is detached and
 * restored while still unassigned. The actor type is rewritten as the morph
 * alias so stored moderation rows never retain fully qualified class names.
 */
final readonly class ModerationAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function key(): string
    {
        return 'moderation';
    }

    public function priority(): int
    {
        return 50;
    }

    public function snapshot(string $accountId): array
    {
        return [
            'moderation_review_ids' => ModerationReview::query()
                ->where('actioned_by_id', $accountId)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all(),
        ];
    }

    public function detach(string $accountId): void
    {
        ModerationReview::query()
            ->where('actioned_by_id', $accountId)
            ->update([
                'actioned_by_type' => null,
                'actioned_by_id' => null,
            ]);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $ids = $this->stringIds($snapshot['moderation_review_ids'] ?? []);

        if ($ids === []) {
            return;
        }

        ModerationReview::query()
            ->whereKey($ids)
            ->whereNull('actioned_by_id')
            ->update([
                'actioned_by_type' => (new User)->getMorphClass(),
                'actioned_by_id' => $accountId,
            ]);
    }

    public function afterRestore(string $accountId): void {}

    /**
     * @return list<string>
     */
    private function stringIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(static fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
