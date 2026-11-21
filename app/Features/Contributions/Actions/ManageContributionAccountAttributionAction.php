<?php

declare(strict_types=1);

namespace App\Features\Contributions\Actions;

use App\Features\Contributions\Support\ContributionModel;

/**
 * Owns contribution proposer and reviewer attribution during account lifecycle.
 */
final readonly class ManageContributionAccountAttributionAction
{
    public function detach(string $accountId): void
    {
        ContributionModel::query('contribution_request')
            ->where('proposer_id', $accountId)
            ->update(['proposer_id' => null]);
        ContributionModel::query('contribution_request')
            ->where('reviewer_id', $accountId)
            ->update(['reviewer_id' => null]);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->restoreLinks($accountId, 'proposer_id', $this->stringIds($snapshot['proposer_ids'] ?? []));
        $this->restoreLinks($accountId, 'reviewer_id', $this->stringIds($snapshot['reviewer_ids'] ?? []));
    }


    /**
     * @param  list<string>  $ids
     */
    private function restoreLinks(string $accountId, string $column, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        ContributionModel::query('contribution_request')
            ->whereKey($ids)
            ->whereNull($column)
            ->update([$column => $accountId]);
    }

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
