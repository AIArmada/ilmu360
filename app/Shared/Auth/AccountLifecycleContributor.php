<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/**
 * Portable account lifecycle hook.
 *
 * Contributors snapshot and restore their own state without exposing Eloquent
 * models. Snapshots must contain only JSON-serialisable values because they are
 * stored in the deleted-model copy that account restoration reads back.
 */
interface AccountLifecycleContributor
{
    public const string TAG = 'identity.account_lifecycle_contributors';

    /**
     * Stable snapshot key owned by this contributor.
     */
    public function key(): string;

    /**
     * Higher priority contributors run first in every lifecycle phase.
     */
    public function priority(): int;

    /**
     * @return array<string, mixed>
     */
    public function snapshot(string $accountId): array;

    public function detach(string $accountId): void;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function restore(string $accountId, array $snapshot): void;

    public function afterRestore(string $accountId): void;
}
