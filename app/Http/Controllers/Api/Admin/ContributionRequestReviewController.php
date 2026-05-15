<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\Admin\AdminContributionRequestReviewService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Contribution Request Review', 'Explicit admin workflow endpoints for approving or rejecting contribution requests. These actions mirror the Filament moderation workflow and are not part of the generic admin CRUD surface.')]
class ContributionRequestReviewController extends Controller
{
    public function __construct(
        private readonly AdminContributionRequestReviewService $reviewService,
    ) {}

    #[PathParameter('recordKey', 'Existing contribution-request route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Get contribution-request review schema',
        description: 'Returns the approval/rejection contract for one contribution request, including the conditional rejection reason field.',
    )]
    public function schema(string $recordKey, Request $request): JsonResponse
    {
        return response()->json($this->reviewService->schema(
            recordKey: $recordKey,
            actor: $this->currentUser($request),
        ));
    }

    #[PathParameter('recordKey', 'Existing contribution-request route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Review a contribution request',
        description: 'Approves or rejects one pending contribution request. Rejections require a reason_code from the returned review schema.',
    )]
    public function review(string $recordKey, Request $request): JsonResponse
    {
        return response()->json($this->reviewService->review(
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
