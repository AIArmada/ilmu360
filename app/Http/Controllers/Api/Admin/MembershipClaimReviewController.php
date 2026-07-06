<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use AIArmada\Membership\Actions\ApproveMembershipApplicationAction;
use AIArmada\Membership\Actions\RejectMembershipApplicationAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Filament\Resources\MembershipClaims\MembershipClaimResource;
use App\Http\Controllers\Controller;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Support\Api\Admin\AdminResourceRegistry;
use App\Support\Membership\MembershipClaimPresenter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Membership Claim Review', 'Explicit admin workflow endpoints for approving or rejecting membership claims. These actions mirror the Filament moderation workflow and are not part of the generic admin CRUD surface.')]
class MembershipClaimReviewController extends Controller
{
    public function __construct(
        private AdminResourceRegistry $registry,
        private ApproveMembershipApplicationAction $approveAction,
        private RejectMembershipApplicationAction $rejectAction,
    ) {}

    #[PathParameter('recordKey', 'Existing membership claim route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Get membership-claim review schema',
        description: 'Returns the approval/rejection contract for one membership claim, including the role options accepted when approving the claim.',
    )]
    public function schema(string $recordKey, Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        /** @var MembershipApplication $application */
        $application = $this->registry->resolveRecord(MembershipClaimResource::class, $recordKey);

        return response()->json([
            'data' => [
                'schema' => [
                    'defaults' => ['action' => 'approve', 'granted_role' => null, 'reviewer_note' => null],
                    'fields' => [
                        ['name' => 'action', 'type' => 'string', 'required' => true, 'default' => 'approve', 'allowed_values' => ['approve', 'reject']],
                        ['name' => 'granted_role', 'type' => 'string', 'required' => false, 'allowed_values' => array_keys(MembershipClaimPresenter::approvalRoleOptions($application))],
                        ['name' => 'reviewer_note', 'type' => 'string', 'required' => false, 'max_length' => 2000],
                    ],
                    'conditional_rules' => [
                        ['field' => 'granted_role', 'required_when' => ['action' => ['approve']]],
                    ],
                ],
            ],
        ]);
    }

    #[PathParameter('recordKey', 'Existing membership claim route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Review a membership claim',
        description: 'Approves or rejects one pending membership claim. Approvals require a `granted_role` from the returned review schema.',
    )]
    public function review(string $recordKey, Request $request): JsonResponse
    {
        $user = $this->requireAdmin($request);

        /** @var MembershipApplication $application */
        $application = $this->registry->resolveRecord(MembershipClaimResource::class, $recordKey);

        $action = (string) $request->input('action', '');
        $reviewerNote = filled($request->input('reviewer_note')) ? (string) $request->input('reviewer_note') : null;

        match ($action) {
            'approve' => $this->approveAction->handle(
                $application,
                $user,
                MemberRole::tryFrom((string) $request->input('granted_role')) ?? MemberRole::Editor,
                $reviewerNote,
            ),
            'reject' => $this->rejectAction->handle($application, $user, $reviewerNote),
            default => abort(422, 'Unsupported action.'),
        };

        return response()->json([
            'data' => [
                'record' => ['id' => $application->getKey(), 'status' => $application->status->value],
            ],
        ]);
    }

    private function requireAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasAnyRole(['super_admin', 'admin', 'moderator']), 403);

        return $user;
    }
}
