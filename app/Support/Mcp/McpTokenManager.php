<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

class McpTokenManager
{
    private const string LEGACY_WILDCARD_ABILITY = '*';

    public const string ADMIN_SERVER = 'admin';

    public const string MEMBER_SERVER = 'member';

    public const string ADMIN_ABILITY = 'mcp:admin';

    public const string MEMBER_ABILITY = 'mcp:member';

    /**
     * @return array<string, array{label: string, endpoint: string, description: string, ability: string}>
     */
    public function availableServers(User $user): array
    {
        $servers = [];

        if ($user->hasMemberMcpAccess()) {
            $servers[self::MEMBER_SERVER] = [
                'label' => 'Member MCP',
                'endpoint' => url('/mcp/member'),
                'description' => 'Ahli-scoped MCP server for institution, speaker, reference, and related event access.',
                'ability' => self::MEMBER_ABILITY,
            ];
        }

        if ($user->hasAdminMcpAccess()) {
            $servers[self::ADMIN_SERVER] = [
                'label' => 'Admin MCP',
                'endpoint' => url('/mcp/admin'),
                'description' => 'Admin MCP server for full admin-surface resource access.',
                'ability' => self::ADMIN_ABILITY,
            ];
        }

        return $servers;
    }

    public function canManageTokens(User $user): bool
    {
        return $this->availableServers($user) !== [];
    }

    public function allowsServer(User $user, string $server): bool
    {
        $availableServers = $this->availableServers($user);

        if (! array_key_exists($server, $availableServers)) {
            return false;
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return true;
        }

        $abilities = $this->tokenAbilities($token);

        if (in_array(self::LEGACY_WILDCARD_ABILITY, $abilities, true)) {
            return $server === self::ADMIN_SERVER;
        }

        return in_array($availableServers[$server]['ability'], $abilities, true);
    }

    /**
     * @return array{token: string, token_meta: array<string, mixed>}
     */
    public function issue(User $user, string $server, string $name): array
    {
        $availableServers = $this->availableServers($user);

        abort_unless(array_key_exists($server, $availableServers), 403);

        $normalizedName = trim($name);
        abort_unless($normalizedName !== '', 422);

        $newToken = $user->createToken($normalizedName, [
            $availableServers[$server]['ability'],
        ]);

        return [
            'token' => $newToken->plainTextToken,
            'token_meta' => $this->serializeToken($newToken->accessToken, $server, $availableServers[$server]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $user): array
    {
        $availableServers = $this->availableServers($user);

        return $user->tokens()
            ->latest('created_at')
            ->get(['id', 'name', 'abilities', 'created_at', 'last_used_at'])
            ->map(function (PersonalAccessToken $token) use ($availableServers): ?array {
                $server = $this->serverForToken($token);

                if ($server === null || ! array_key_exists($server, $availableServers)) {
                    return null;
                }

                return $this->serializeToken($token, $server, $availableServers[$server]);
            })
            ->filter()
            ->values()
            ->all();
    }

    public function revoke(User $user, int $tokenId): void
    {
        $token = $user->tokens()->whereKey($tokenId)->firstOrFail();

        abort_unless($this->serverForToken($token) !== null, 404);

        $token->delete();
    }

    private function serverForToken(PersonalAccessToken $token): ?string
    {
        $abilities = $this->tokenAbilities($token);

        return match (true) {
            in_array(self::MEMBER_ABILITY, $abilities, true) => self::MEMBER_SERVER,
            in_array(self::LEGACY_WILDCARD_ABILITY, $abilities, true) => self::ADMIN_SERVER,
            in_array(self::ADMIN_ABILITY, $abilities, true) => self::ADMIN_SERVER,
            default => null,
        };
    }

    /**
     * @param  array{label: string, endpoint: string, description: string, ability: string}  $server
     * @return array<string, mixed>
     */
    private function serializeToken(PersonalAccessToken $token, string $serverKey, array $server): array
    {
        return [
            'id' => $token->getKey(),
            'name' => $token->name,
            'server' => $serverKey,
            'endpoint' => $server['endpoint'],
            'label' => $server['label'],
            'created_at' => $this->formatTimestamp($token->created_at),
            'last_used_at' => $this->formatTimestamp($token->last_used_at),
        ];
    }

    /**
     * @return list<string>
     */
    private function tokenAbilities(PersonalAccessToken $token): array
    {
        return Collection::make($token->abilities)
            ->filter(fn (mixed $ability): bool => is_string($ability) && $ability !== '')
            ->values()
            ->all();
    }

    private function formatTimestamp(mixed $timestamp): ?string
    {
        return $timestamp instanceof CarbonInterface
            ? $timestamp->toIso8601String()
            : null;
    }
}
