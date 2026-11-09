<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

interface AccountDirectory
{
    /**
     * Resolve a notification recipient for the given account identifier.
     */
    public function resolveNotifiable(string $accountId): ?Authenticatable;

    /**
     * Accounts with the global roles that maintain application feedback.
     *
     * @return list<Authenticatable>
     */
    public function maintainers(): array;

    /**
     * Accounts with global administrative or moderation roles.
     *
     * @return list<Authenticatable>
     */
    public function admins(): array;

    public function exists(string $accountId): bool;

    /**
     * Acquire the account row lock that serialises actor-scoped mutations.
     */
    public function lockForUpdate(string $accountId): void;
}
