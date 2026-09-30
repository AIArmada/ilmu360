<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Server-side store for cross-domain OAuth transactions.
 *
 * The OAuth flow can start on one hostname (https://ilmu360.test) and finish
 * on another (https://dev.ilmu360.com), where the browser carries no session
 * cookie. Both the OAuth `state` and the post-authentication handoff token
 * therefore live in the shared cache instead of the session.
 */
final class OAuthTransactionStore
{
    /**
     * How long an issued OAuth state stays valid (10 minutes).
     */
    public const STATE_TTL_SECONDS = 600;

    /**
     * How long an issued handoff token stays valid (2 minutes).
     */
    public const HANDOFF_TTL_SECONDS = 120;

    /**
     * Browser-bound verifier cookie. The `__Host-` prefix pins it to the
     * exact host over HTTPS, so sibling subdomains cannot shadow it.
     */
    public const VERIFIER_COOKIE = '__Host-oauth_v';

    /**
     * Issue an opaque OAuth state value for the `state` parameter, plus a
     * browser-bound verifier that must be presented back via cookie.
     *
     * @return array{state: string, verifier: string}
     */
    public static function issueState(string $provider, ?string $intended, ?string $verifier = null): array
    {
        $state = Str::random(64);
        $verifier ??= Str::random(64);

        Cache::put(self::stateKey($state), [
            'provider' => $provider,
            'intended' => $intended,
            'verifier_hash' => hash('sha256', $verifier),
            'issued_at' => now()->toIso8601String(),
        ], self::STATE_TTL_SECONDS);

        return ['state' => $state, 'verifier' => $verifier];
    }

    /**
     * Consume an OAuth state value exactly once.
     *
     * @return array{provider: string, intended: ?string, verifier_hash: string, issued_at: string}|null
     */
    public static function consumeState(string $state, string $provider): ?array
    {
        if ($state === '') {
            return null;
        }

        $payload = self::consumeEntry(self::stateKey($state));

        if (! is_array($payload)
            || ($payload['provider'] ?? null) !== $provider
            || ! is_string($payload['verifier_hash'] ?? null)
            || ($payload['verifier_hash'] ?? '') === ''
        ) {
            return null;
        }

        return [
            'provider' => $provider,
            'intended' => is_string($payload['intended'] ?? null) ? $payload['intended'] : null,
            'verifier_hash' => $payload['verifier_hash'],
            'issued_at' => is_string($payload['issued_at'] ?? null) ? $payload['issued_at'] : '',
        ];
    }

    /**
     * Issue a one-time handoff token that carries an authenticated user id
     * back to the canonical application host.
     */
    public static function issueHandoff(string $provider, string $userId, bool $createdAccount, ?string $intended, string $verifierHash): string
    {
        $token = Str::random(64);

        Cache::put(self::handoffKey($token), [
            'provider' => $provider,
            'user_id' => $userId,
            'created_account' => $createdAccount,
            'intended' => $intended,
            'verifier_hash' => $verifierHash,
            'issued_at' => now()->toIso8601String(),
        ], self::HANDOFF_TTL_SECONDS);

        return $token;
    }

    /**
     * Consume a handoff token exactly once.
     *
     * @return array{provider: string, user_id: string, created_account: bool, intended: ?string, verifier_hash: string, issued_at: string}|null
     */
    public static function consumeHandoff(string $token, string $provider): ?array
    {
        if ($token === '') {
            return null;
        }

        $payload = self::consumeEntry(self::handoffKey($token));

        if (! is_array($payload)
            || ($payload['provider'] ?? null) !== $provider
            || ! is_string($payload['user_id'] ?? null)
            || ($payload['user_id'] ?? '') === ''
            || ! is_string($payload['verifier_hash'] ?? null)
            || ($payload['verifier_hash'] ?? '') === ''
        ) {
            return null;
        }

        return [
            'provider' => $provider,
            'user_id' => $payload['user_id'],
            'created_account' => (bool) ($payload['created_account'] ?? false),
            'intended' => is_string($payload['intended'] ?? null) ? $payload['intended'] : null,
            'verifier_hash' => $payload['verifier_hash'],
            'issued_at' => is_string($payload['issued_at'] ?? null) ? $payload['issued_at'] : '',
        ];
    }

    /**
     * Check a presented verifier cookie against the stored hash.
     */
    public static function verifierMatches(mixed $verifier, mixed $expectedHash): bool
    {
        if (! is_string($verifier) || $verifier === '' || ! is_string($expectedHash) || $expectedHash === '') {
            return false;
        }

        return hash_equals($expectedHash, hash('sha256', $verifier));
    }

    /**
     * Build the browser-bound verifier cookie (host-only, HTTPS-only,
     * HttpOnly, top-level navigations only, same lifetime as the state).
     */
    public static function verifierCookie(string $verifier): SymfonyCookie
    {
        // Build the raw Symfony cookie directly: Cookie::make() backfills a
        // null domain from config('session.domain'), and any Domain attribute
        // makes browsers reject __Host- cookies outright.
        return new SymfonyCookie(
            self::VERIFIER_COOKIE,
            $verifier,
            time() + self::STATE_TTL_SECONDS,
            '/',
            null,
            true,
            true,
            false,
            'lax'
        );
    }

    /**
     * Read and delete a single-use entry under a short lock so concurrent
     * presents cannot both succeed. Contention is treated as consumed.
     */
    private static function consumeEntry(string $key): mixed
    {
        $lock = Cache::lock('lock:'.$key, 5);

        if (! $lock->get()) {
            return null;
        }

        try {
            return Cache::pull($key);
        } finally {
            $lock->release();
        }
    }

    private static function stateKey(string $state): string
    {
        return 'oauth-state:'.$state;
    }

    private static function handoffKey(string $token): string
    {
        return 'oauth-handoff:'.hash('sha256', $token);
    }
}
