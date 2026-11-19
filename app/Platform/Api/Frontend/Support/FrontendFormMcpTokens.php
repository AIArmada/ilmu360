<?php

declare(strict_types=1);

namespace App\Platform\Api\Frontend\Support;

use App\Models\User;
use App\Platform\Mcp\McpTokenManager;

/**
 * Neutral MCP server catalog for the account-settings form contract.
 */
final readonly class FrontendFormMcpTokens
{
    public function __construct(
        private McpTokenManager $tokens,
    ) {}

    /**
     * @return array<string, array{label: string, endpoint: string, description: string, ability: string}>
     */
    public function availableServers(User $user): array
    {
        return $this->tokens->availableServers($user);
    }
}
