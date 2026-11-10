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
     * Read the immutable identity profile for the given account.
     */
    public function profile(string $accountId): ?AccountProfile;

    /**
     * Read raw serialized account attributes plus roles for transport payloads.
     */
    public function record(string $accountId): ?AccountRecord;

    /**
     * Global-role names for the account.
     *
     * @return list<string>
     */
    public function roles(string $accountId): array;

    /**
     * Stable account type used for snapshots and audit attribution, or null
     * when the account does not exist.
     */
    public function accountType(string $accountId): ?string;

    /**
     * Acquire the account row lock that serialises actor-scoped mutations.
     */
    public function lockForUpdate(string $accountId): void;
}
