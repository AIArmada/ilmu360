<?php

declare(strict_types=1);

namespace App\Platform\Mcp\EntryPoints\Http\Middleware;

use App\Models\User;
use App\Platform\Mcp\McpAuthenticatedUserResolver;
use App\Platform\Mcp\McpTokenManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminMcpAccess
{
    public function __construct(
        private readonly McpTokenManager $tokenManager,
        private readonly McpAuthenticatedUserResolver $userResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->userResolver->resolve($request->user());

        abort_unless(
            $user instanceof User
                && $user->hasAdminMcpAccess()
                && $this->tokenManager->allowsServer($user, McpTokenManager::ADMIN_SERVER),
            403,
        );

        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }
}
