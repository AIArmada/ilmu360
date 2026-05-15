<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\Admin\AdminEventModerationService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Event Moderation', 'Explicit admin workflow endpoints for moderating events through the same transitions used in Filament. These actions are not part of the generic admin CRUD surface.')]
class EventModerationController extends Controller
{
    public function __construct(
        private readonly AdminEventModerationService $moderationService,
    ) {}

    #[PathParameter('recordKey', 'Existing event route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Get event moderation schema',
        description: 'Returns the moderation contract for one event, including the currently allowed actions for its state and any conditional fields.',
    )]
    public function schema(string $recordKey, Request $request): JsonResponse
    {
        return response()->json($this->moderationService->schema(
            recordKey: $recordKey,
            actor: $this->currentUser($request),
        ));
    }

    #[PathParameter('recordKey', 'Existing event route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Moderate an event',
        description: 'Runs one explicit moderation action such as submit for moderation, approve, request changes, reject, cancel, reconsider, remoderate, or revert to draft.',
    )]
    public function moderate(string $recordKey, Request $request): JsonResponse
    {
        return response()->json($this->moderationService->moderate(
            recordKey: $recordKey,
            payload: $request->all(),
            actor: $this->currentUser($request),
        ));
    }

    private function currentUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
