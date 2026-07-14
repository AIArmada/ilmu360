<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use AIArmada\Membership\Actions\ApproveMembershipApplicationAction;
use AIArmada\Membership\Actions\RejectMembershipApplicationAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Http\Controllers\Controller;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Support\Api\Admin\AdminResourceRegistry;
use App\Support\Membership\MembershipApplicationPresenter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin Membership Application Review', 'Explicit admin workflow endpoints for approving or rejecting membership applications. These actions mirror the Filament moderation workflow and are not part of the generic admin CRUD surface.')]
class MembershipApplicationReviewController extends Controller
{
    public function __construct(
        private AdminResourceRegistry $registry,
        private ApproveMembershipApplicationAction $approveAction,
        private RejectMembershipApplicationAction $rejectAction,
    ) {}

    #[PathParameter('recordKey', 'Existing membership application route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Get membership-application review schema',
        description: 'Returns the approval/rejection contract for one membership application, including the role options accepted when approving the application.',
    )]
    public function schema(string $recordKey, Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        /** @var MembershipApplication $application */
        $application = $this->registry->resolveRecord(MembershipApplicationResource::class, $recordKey);

        return response()->json([
            'data' => [
                'schema' => [
                    'action' => 'review_membership_application',
                    'defaults' => ['action' => 'approve', 'granted_role' => null, 'reviewer_note' => null],
                    'fields' => [
                        ['name' => 'action', 'type' => 'string', 'required' => true, 'default' => 'approve', 'allowed_values' => ['approve', 'reject']],
                        ['name' => 'granted_role', 'type' => 'string', 'required' => false, 'allowed_values' => array_keys(MembershipApplicationPresenter::approvalRoleOptions($application))],
                        ['name' => 'reviewer_note', 'type' => 'string', 'required' => false, 'max_length' => 2000],
                    ],
                    'conditional_rules' => [
                        ['field' => 'granted_role', 'required_when' => ['action' => ['approve']]],
                    ],
                ],
            ],
        ]);
    }

    #[PathParameter('recordKey', 'Existing membership application route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Review a membership application',
        description: 'Approves or rejects one pending membership application. Approvals require a `granted_role` from the returned review schema.',
    )]
    public function review(string $recordKey, Request $request): JsonResponse
    {
        $user = $this->requireAdmin($request);

        /** @var MembershipApplication $application */
        $application = $this->registry->resolveRecord(MembershipApplicationResource::class, $recordKey);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'granted_role' => [
                Rule::requiredIf(fn (): bool => (string) $request->input('action') === 'approve'),
                'nullable',
                'string',
                Rule::in(array_keys(MembershipApplicationPresenter::approvalRoleOptions($application))),
            ],
            'reviewer_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $action = (string) $validated['action'];
        $reviewerNote = filled($validated['reviewer_note'] ?? null) ? (string) $validated['reviewer_note'] : null;

        match ($action) {
            'approve' => $this->approveAction->handle(
                $application,
                $user,
                MemberRole::from((string) $validated['granted_role']),
                $reviewerNote,
            ),
            'reject' => $this->rejectAction->handle($application, $user, $reviewerNote),
            default => abort(422, 'Unsupported action.'),
        };

        $application->refresh();

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
