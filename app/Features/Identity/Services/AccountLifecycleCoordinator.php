<?php

declare(strict_types=1);

namespace App\Features\Identity\Services;

use App\Features\Identity\Api\AccountLifecycle;
use App\Shared\Auth\AccountLifecycleContributor;
use InvalidArgumentException;
use LogicException;

/**
 * Deterministic coordinator for tagged account lifecycle contributors.
 *
 * Contributors run by descending priority, then by key, in every phase. The
 * conventional User restoration trait delegates its migrated lifecycle steps
 * to this contract; contributors that have not moved yet remain under the
 * trait's `legacy` snapshot key.
 */
final class AccountLifecycleCoordinator implements AccountLifecycle
{
    /**
     * @var list<AccountLifecycleContributor>
     */
    private array $contributors = [];

    /**
     * @param  iterable<AccountLifecycleContributor>  $contributors
     */
    public function __construct(iterable $contributors)
    {
        $keys = [];

        foreach ($contributors as $contributor) {
            if (! $contributor instanceof AccountLifecycleContributor) {
                throw new InvalidArgumentException('Account lifecycle contributors must implement AccountLifecycleContributor.');
            }

            $key = $contributor->key();

            if ($key === '') {
                throw new InvalidArgumentException('Account lifecycle contributor keys cannot be empty.');
            }

            if (isset($keys[$key])) {
                throw new LogicException("Duplicate account lifecycle contributor key: {$key}");
            }

            $keys[$key] = true;
            $this->contributors[] = $contributor;
        }

        usort(
            $this->contributors,
            static fn (AccountLifecycleContributor $left, AccountLifecycleContributor $right): int => $right->priority() <=> $left->priority()
                ?: $left->key() <=> $right->key(),
        );
    }

    public function snapshot(string $accountId): array
    {
        $snapshots = [];

        foreach ($this->contributors as $contributor) {
            $snapshots[$contributor->key()] = $contributor->snapshot($accountId);
        }

        return $snapshots;
    }

    public function detach(string $accountId): void
    {
        foreach ($this->contributors as $contributor) {
            $contributor->detach($accountId);
        }
    }

    public function restore(string $accountId, array $snapshot): void
    {
        foreach ($this->contributors as $contributor) {
            $contributorSnapshot = $snapshot[$contributor->key()] ?? [];

            $contributor->restore($accountId, is_array($contributorSnapshot) ? $contributorSnapshot : []);
        }
    }

    public function afterRestore(string $accountId): void
    {
        foreach ($this->contributors as $contributor) {
            $contributor->afterRestore($accountId);
        }
    }
}
