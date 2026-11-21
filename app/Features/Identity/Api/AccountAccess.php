<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

/**
 * Conventional authentication adapter for the Identity capability.
 *
 * Implementations own Laravel auth/Sanctum/Fortify details so feature
 * transport and workflows never import the user model directly.
 */
interface AccountAccess
{
    /**
     * Create a raw account row (for trusted provider sign-ups).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): AccountProfile;

    /**
     * Resolve an account by its contact email.
     */
    public function findByEmail(string $email): ?AccountProfile;

    /**
     * Verify credentials and issue a bearer token.
     */
    public function authenticate(string $login, string $password, string $deviceName): AccountSession;

    public function issueToken(string $accountId, string $deviceName): string;

    /**
     * Dispatch Laravel's Registered event for the created account.
     */
    public function dispatchRegistered(string $accountId): void;

    /**
     * Revoke the persisted bearer token when the caller resolved one.
     */
    public function revokeCurrentToken(string $accountId, int|string|null $accessTokenId): void;

    public function markEmailVerified(string $accountId): void;

    public function sendEmailVerification(string $accountId): void;

    /**
     * Persist the reset password and completion Signal before caller-owned token and event effects.
     */
    public function persistResetPassword(string $accountId, string $password): void;

    public function resetPassword(string $accountId, string $password): void;
}
