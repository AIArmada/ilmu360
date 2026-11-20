<?php

declare(strict_types=1);

namespace App\Features\Events\Contributors;

use App\Features\Moderation\Api\ModerationActionHistory;
use App\Shared\Auth\AccountLifecycleContributor;

/**
 * Preserves moderation review attribution across account deletion and
 * restoration.
 *
 * Review records are retained; only the acting account link is detached and
 * restored while still unassigned. The Moderation feature owns the persisted
 * rows and rewrites the actor type as the morph alias.
 */
final readonly class ModerationAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function __construct(
        private ModerationActionHistory $moderationActions,
    ) {}

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
            'moderation_review_ids' => $this->moderationActions->idsForAccount($accountId),
        ];
    }

    public function detach(string $accountId): void
    {
        $this->moderationActions->detachAccount($accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->moderationActions->restoreAccount($accountId, $this->stringIds($snapshot['moderation_review_ids'] ?? []));
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
