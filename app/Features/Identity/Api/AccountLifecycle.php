<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

interface AccountLifecycle
{
    /**
     * Capture every contributor snapshot before the account is removed.
     *
     * @return array<string, array<string, mixed>>
     */
    public function snapshot(string $accountId): array;

    /**
     * Detach the account from every contributor-owned relation.
     */
    public function detach(string $accountId): void;

    /**
     * Restore contributor state from a previously captured snapshot.
     *
     * @param  array<string, array<string, mixed>>  $snapshot
     */
    public function restore(string $accountId, array $snapshot): void;

    /**
     * Run post-restore work after every contributor has restored its state.
     */
    public function afterRestore(string $accountId): void;
}
