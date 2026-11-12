<?php

declare(strict_types=1);

namespace App\Features\Contributions\Contributors;

use App\Features\Contributions\Support\ContributionModel;
use App\Shared\Auth\AccountLifecycleContributor;

/**
 * Preserves contribution proposal/review attribution across account
 * deletion and restoration.
 *
 * Requests themselves are retained; only the account's proposer/reviewer
 * attribution is detached and restored while still unassigned.
 */
final readonly class ContributionsAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function key(): string
    {
        return 'contributions';
    }

    public function priority(): int
    {
        return 60;
    }

    public function snapshot(string $accountId): array
    {
        return [
            'proposer_ids' => $this->requestIds('proposer_id', $accountId),
            'reviewer_ids' => $this->requestIds('reviewer_id', $accountId),
        ];
    }

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

    public function afterRestore(string $accountId): void {}

    /**
     * @return list<string>
     */
    private function requestIds(string $column, string $accountId): array
    {
        return ContributionModel::query('contribution_request')
            ->where($column, $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
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
