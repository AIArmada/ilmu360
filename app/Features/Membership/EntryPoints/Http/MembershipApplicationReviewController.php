<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Http;

use App\Features\Membership\Api\MembershipApplications;
use App\Platform\Http\FrontendController;
use App\Shared\Auth\AccountActor;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin Membership Application Review', 'Explicit admin workflow endpoints for approving or rejecting membership applications. These actions mirror the Filament moderation workflow and are not part of the generic admin CRUD surface.')]
class MembershipApplicationReviewController extends FrontendController
{
    public function __construct(
        private readonly MembershipApplications $membershipApplications,
    ) {}

    #[PathParameter('recordKey', 'Existing membership application route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Get membership-application review schema',
        description: 'Returns the approval/rejection contract for one membership application, including the role options accepted when approving the application.',
    )]
    public function schema(string $recordKey, Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $application = $this->membershipApplications->find($recordKey);
        abort_unless($application !== null, 404);

        return response()->json([
            'data' => [
                'schema' => [
                    'action' => 'review_membership_application',
                    'defaults' => ['action' => 'approve', 'granted_role' => null, 'reviewer_note' => null],
                    'fields' => [
                        ['name' => 'action', 'type' => 'string', 'required' => true, 'default' => 'approve', 'allowed_values' => ['approve', 'reject']],
                        ['name' => 'granted_role', 'type' => 'string', 'required' => false, 'allowed_values' => array_keys($this->membershipApplications->reviewRoleOptions())],
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
        $account = $this->requireAdmin($request);

        $application = $this->membershipApplications->find($recordKey);
        abort_unless($application !== null, 404);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'granted_role' => [
                Rule::requiredIf(fn (): bool => (string) $request->input('action') === 'approve'),
                'nullable',
                'string',
                Rule::in(array_keys($this->membershipApplications->reviewRoleOptions())),
            ],
            'reviewer_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $action = (string) $validated['action'];
        $reviewerNote = filled($validated['reviewer_note'] ?? null) ? (string) $validated['reviewer_note'] : null;

        $detail = match ($action) {
            'approve' => $this->membershipApplications->approve(
                $application->application->id,
                $account->actorId(),
                (string) $validated['granted_role'],
                $reviewerNote,
            ),
            'reject' => $this->membershipApplications->reject(
                $application->application->id,
                $account->actorId(),
                $reviewerNote,
            ),
            default => abort(422, 'Unsupported action.'),
        };

        return response()->json([
            'data' => [
                'record' => [
                    'id' => $detail->application->id,
                    'status' => $detail->application->status,
                ],
            ],
        ]);
    }

    private function requireAdmin(Request $request): AccountActor
    {
        $account = $this->requireAccountActor($request);
        abort_unless($account->hasAnyRole(['super_admin', 'admin', 'moderator']), 403);

        return $account;
    }
}
