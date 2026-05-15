<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\Admin\AdminMembershipClaimReviewService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Membership Claim Review', 'Explicit admin workflow endpoints for approving or rejecting membership claims. These actions mirror the Filament moderation workflow and are not part of the generic admin CRUD surface.')]
class MembershipClaimReviewController extends Controller
{
    public function __construct(
        private readonly AdminMembershipClaimReviewService $reviewService,
    ) {}

    #[PathParameter('recordKey', 'Existing membership claim route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Get membership-claim review schema',
        description: 'Returns the approval/rejection contract for one membership claim, including the role options accepted when approving the claim.',
    )]
    public function schema(string $recordKey, Request $request): JsonResponse
    {
        return response()->json($this->reviewService->schema(
            recordKey: $recordKey,
            actor: $this->currentUser($request),
        ));
    }

    #[PathParameter('recordKey', 'Existing membership claim route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Review a membership claim',
        description: 'Approves or rejects one pending membership claim. Approvals require a `granted_role_slug` from the returned review schema.',
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
