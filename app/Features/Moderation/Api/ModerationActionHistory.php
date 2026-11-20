<?php

declare(strict_types=1);

namespace App\Features\Moderation\Api;

/**
 * Account-attribution and deletion operations for recorded moderation actions.
 *
 * Review rows are retained when an account is deleted; only the acting-account
 * link is detached and restored. Event cleanup removes the actions attached to
 * the actionable record before its owner row is deleted.
 */
interface ModerationActionHistory
{
    /** @return list<string> */
    public function idsForAccount(string $accountId): array;

    public function detachAccount(string $accountId): void;

    /**
     * @param  list<string>  $actionIds
     */
    public function restoreAccount(string $accountId, array $actionIds): void;

    public function deleteForActionable(string $actionableType, string $actionableId): void;
}
