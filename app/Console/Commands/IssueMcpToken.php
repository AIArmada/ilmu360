<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Mcp\McpTokenManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'mcp:token',
    description: 'Issue a Sanctum bearer token for an MCP server.'
)]
class IssueMcpToken extends Command
{
    protected $signature = 'mcp:token {email : The user email} {name=opencode-mcp : The token name} {--server=admin : The MCP server audience (admin or member)}';

    protected $description = 'Issue a Sanctum bearer token for an MCP server.';

    public function __construct(
        private readonly McpTokenManager $tokenManager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = (string) $this->argument('name');
        $server = strtolower(trim((string) $this->option('server')));

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->components->error("User [{$email}] was not found.");

            return self::FAILURE;
        }

        if (! in_array($server, [McpTokenManager::ADMIN_SERVER, McpTokenManager::MEMBER_SERVER], true)) {
            $this->components->error('The MCP server audience must be either [admin] or [member].');

            return self::FAILURE;
        }

        if (! array_key_exists($server, $this->tokenManager->availableServers($user))) {
            $expectedAccess = $server === McpTokenManager::ADMIN_SERVER
                ? 'application admin access'
                : 'member MCP access';

            $this->components->error("User [{$email}] does not have {$expectedAccess}.");

            return self::FAILURE;
        }

        $token = $this->tokenManager->issue($user, $server, $name)['token'];

        $this->line('Bearer '.$token);

        return self::SUCCESS;
    }
}
