<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use App\Support\Mcp\McpTokenManager;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('AccountSettings', 'Authenticated account-settings read, update, and MCP token endpoints for client applications.')]
class AccountSettingsMcpTokenController extends FrontendController
{
    public function __construct(
        private readonly McpTokenManager $tokenManager,
    ) {}

    #[Endpoint(
        title: 'List MCP tokens',
        description: 'Returns the current authenticated user\'s MCP-scoped bearer tokens together with the MCP servers the user may self-serve.',
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        abort_unless($this->tokenManager->canManageTokens($user), 403);

        return response()->json([
            'data' => [
                'tokens' => $this->tokenManager->list($user),
                'servers' => $this->tokenManager->availableServers($user),
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    #[BodyParameter('name', 'Client-defined MCP token label.', type: 'string', infer: false, example: 'VS Code Member MCP')]
    #[BodyParameter('server', 'The MCP server audience to issue for.', type: 'string', infer: false, example: 'member')]
    #[Endpoint(
        title: 'Issue an MCP token',
        description: 'Issues a new MCP-scoped Sanctum bearer token for one allowed MCP server audience and returns the plain-text bearer token once.',
    )]
    public function store(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $availableServers = $this->tokenManager->availableServers($user);

        abort_unless($availableServers !== [], 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'server' => ['required', 'string', Rule::in(array_keys($availableServers))],
        ]);

        $issuedToken = $this->tokenManager->issue(
            $user,
            (string) $validated['server'],
            (string) $validated['name'],
        );

        return response()->json([
            'data' => $issuedToken,
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ], 201);
    }

    #[Endpoint(
        title: 'Revoke an MCP token',
        description: 'Revokes one previously issued MCP-scoped bearer token belonging to the current authenticated user.',
    )]
    public function destroy(Request $request, string $tokenId): JsonResponse
    {
        $user = $this->requireUser($request);

        abort_unless($this->tokenManager->canManageTokens($user), 403);

        $normalizedTokenId = filter_var($tokenId, FILTER_VALIDATE_INT);
        abort_unless(is_int($normalizedTokenId), 404);

        $this->tokenManager->revoke($user, $normalizedTokenId);

        return response()->json([
            'message' => 'MCP token revoked successfully.',
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }
}
