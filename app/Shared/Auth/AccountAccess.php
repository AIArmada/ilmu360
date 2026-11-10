<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Conventional authentication adapter for the Identity capability.
 *
 * Implementations own Laravel auth/Sanctum/Fortify details so feature
 * transport and workflows never import the user model directly.
 */
interface AccountAccess
{
    /**
     * Register through the conventional Fortify registration path.
     *
     * @param  array<string, mixed>  $input
     */
    public function register(array $input): AccountProfile;

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

    public function revokeCurrentToken(Authenticatable $account): void;

    public function markEmailVerified(string $accountId): void;

    public function sendEmailVerification(string $accountId): void;

    public function resetPassword(string $accountId, string $password, ?Request $request = null): void;
}
