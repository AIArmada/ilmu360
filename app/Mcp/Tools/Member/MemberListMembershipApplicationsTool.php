<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Models\MembershipApplication;
use App\Models\User;
use App\Support\Membership\MembershipApplicationPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[IsReadOnly]
#[IsIdempotent]
class MemberListMembershipApplicationsTool extends AbstractMemberTool
{
    protected string $name = 'member-list-membership-applications';

    protected string $description = 'Use this when you need to list the authenticated member\'s membership applications. Do not use for admin-level membership application management.';

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeMember($request);
            $this->validateArguments($request, []);

            $applications = $actor->membershipApplications()
                ->with(['reviewer', 'media'])
                ->latest('created_at')
                ->get();

            return [
                'data' => $applications->map(fn (MembershipApplication $application): array => $this->applicationData($application, $actor))->all(),
            ];
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationData(MembershipApplication $application, User $actor): array
    {
        $subjectPresentation = MembershipApplicationPresenter::subjectPresentation($application);
        $evidenceItems = $application->relationLoaded('media')
            ? $application->media->where('collection_name', 'evidence')->values()
            : $application->getMedia('evidence');

        return [
            'id' => $application->getKey(),
            'subject_type' => $application->subject_type instanceof \BackedEnum ? $application->subject_type->value : (string) $application->subject_type,
            'subject_label' => MembershipApplicationPresenter::labelForSubject($application->subject_type),
            'subject_title' => $subjectPresentation['subject_title'] ?? (string) $application->subject_id,
            'subject_public_url' => $subjectPresentation['redirect_url'] ?? null,
            'status' => $application->status->value,
            'status_label' => MembershipApplicationPresenter::labelForStatus($application->status),
            'role_label' => MembershipApplicationPresenter::roleLabel($application),
            'justification' => $application->justification,
            'granted_role' => $application->granted_role,
            'reviewer_note' => $application->reviewer_note,
            'created_at' => $application->created_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'cancelled_at' => $application->cancelled_at?->toIso8601String(),
            'can_cancel' => $application->status->value === 'pending' && (string) $application->applicant_id === (string) $actor->getKey(),
            'reviewer' => $application->reviewer?->only(['id', 'name', 'email']),
            'evidence' => $evidenceItems->map(fn (Media $media): array => [
                'id' => $media->getKey(),
                'name' => $media->name !== '' ? $media->name : $media->file_name,
                'url' => $media->getAvailableUrl(['thumb']) ?: $media->getUrl(),
            ])->all(),
        ];
    }
}
