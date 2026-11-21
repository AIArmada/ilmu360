<?php

declare(strict_types=1);

namespace App\Features\Contributions\Contributors;

use App\Features\Contributions\Actions\ManageContributionAccountAttributionAction;
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
    public function __construct(private ManageContributionAccountAttributionAction $attribution) {}

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
        $this->attribution->detach($accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->attribution->restore($accountId, $snapshot);
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
}
