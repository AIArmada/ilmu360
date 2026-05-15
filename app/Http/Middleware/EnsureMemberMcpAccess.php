<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Mcp\McpAuthenticatedUserResolver;
use App\Support\Mcp\McpTokenManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMemberMcpAccess
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
                && $user->hasMemberMcpAccess()
                && $this->tokenManager->allowsServer($user, McpTokenManager::MEMBER_SERVER),
            403,
        );

        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }
}
