<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/**
 * Conventional account persistence adapter for account settings and admin
 * account administration. It never exposes the mutable user model.
 */
interface AccountSettingsStore
{
    public function updateProfile(string $accountId, AccountProfileUpdate $update): void;

    /**
     * Administrative update including verification timestamps.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateAdministrative(string $accountId, array $attributes): void;

    /**
     * Administrative create including verification timestamps.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createAdministrative(array $attributes): string;

    public function delete(string $accountId): void;

    public function restore(string $deletedKey): AccountProfile;

    /**
     * @return list<AccountToken>
     */
    public function tokens(string $accountId): array;

    public function createToken(string $accountId, string $name): string;

    public function revokeToken(string $accountId, int|string $tokenId): bool;
}
