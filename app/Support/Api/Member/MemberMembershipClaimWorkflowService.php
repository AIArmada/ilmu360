<?php

declare(strict_types=1);

namespace App\Support\Api\Member;

use App\Actions\Membership\CancelMembershipClaimAction;
use App\Actions\Membership\ResolveMembershipClaimSubjectAction;
use App\Actions\Membership\ResolveMembershipClaimSubjectPresentationAction;
use App\Actions\Membership\SubmitMembershipClaimAction;
use App\Enums\MemberSubjectType;
use App\Models\MembershipClaim;
use App\Models\User;
use App\Support\Api\Frontend\FrontendMediaSyncService;
use App\Support\Membership\MembershipClaimPresenter;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class MemberMembershipClaimWorkflowService
{
    public function __construct(
        private ResolveMembershipClaimSubjectAction $resolveMembershipClaimSubjectAction,
        private ResolveMembershipClaimSubjectPresentationAction $resolveMembershipClaimSubjectPresentationAction,
        private SubmitMembershipClaimAction $submitMembershipClaimAction,
        private CancelMembershipClaimAction $cancelMembershipClaimAction,
        private FrontendMediaSyncService $frontendMediaSyncService,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>}
     */
    public function list(User $actor): array
    {
        $claims = $actor->membershipClaims()
            ->with(['reviewer', 'media'])
            ->latest('created_at')
            ->get();

        return [
            'data' => $claims->map(fn (MembershipClaim $claim): array => $this->claimData($claim, $actor))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array{claim: array<string, mixed>, subject: array<string, mixed>}}
     */
    public function submit(string $subjectType, string $subject, array $payload, User $actor): array
    {
        if (! $actor->canSubmitDirectoryFeedback()) {
            abort(403, $actor->directoryFeedbackBanMessage());
        }

        $supportedSubjectTypes = array_values(array_unique(array_merge(
            array_map(static fn (MemberSubjectType $type): string => $type->value, MemberSubjectType::claimableCases()),
            MemberSubjectType::claimableRouteSegments(),
        )));

        $validated = Validator::make(array_merge($payload, ['subject_type' => $subjectType]), [
            'subject_type' => ['required', 'string', Rule::in($supportedSubjectTypes)],
            'justification' => ['required', 'string', 'max:2000'],
            'evidence' => ['required', 'array', 'min:1', 'max:8'],
            'evidence.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:'.$this->maxUploadSizeKb()],
        ])->validate();

        $claimSubject = $this->resolveMembershipClaimSubjectAction->handle($subjectType, $subject);

        try {
            $claim = $this->submitMembershipClaimAction->handle(
                $claimSubject,
                $actor,
                (string) $validated['justification'],
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'justification' => match ($exception->getMessage()) {
                    'membership_claim_already_member' => __('You are already a member of this record.'),
                    'membership_claim_duplicate_pending' => __('You already have a pending claim for this record.'),
                    'membership_claim_pending_invitation' => __('You already have a pending invitation for this record. Please accept that invitation instead.'),
                    default => __('The membership claim could not be submitted.'),
                },
            ]);
        }

        $this->frontendMediaSyncService->syncMultiple(
            $claim,
            is_array($validated['evidence']) ? $validated['evidence'] : null,
            'evidence',
        );

        $presentation = $this->resolveMembershipClaimSubjectPresentationAction->handle($claimSubject);
        $claim = $claim->fresh(['reviewer', 'media']) ?? $claim;

        return [
            'data' => [
                'claim' => $this->claimData($claim, $actor, $presentation),
                'subject' => $presentation,
            ],
        ];
    }

    /**
     * @return array{data: array{claim: array<string, mixed>}}
     */
    public function cancel(string $claimId, User $actor): array
    {
        $claim = $actor->membershipClaims()
            ->with(['reviewer', 'media'])
            ->whereKey($claimId)
            ->first();

        abort_unless($claim instanceof MembershipClaim, 404);

        try {
            $claim = $this->cancelMembershipClaimAction->handle($claim, $actor);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'claim' => __('Only pending claims can be cancelled.'),
            ]);
        }

        return [
            'data' => [
                'claim' => $this->claimData($claim, $actor),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $subjectPresentation
     * @return array<string, mixed>
     */
    private function claimData(MembershipClaim $claim, User $currentUser, ?array $subjectPresentation = null): array
    {
        $subjectPresentation ??= MembershipClaimPresenter::subjectPresentation($claim);
        $evidenceItems = $claim->relationLoaded('media')
            ? $claim->media->where('collection_name', 'evidence')->values()
            : $claim->getMedia('evidence');

        return [
            'id' => $claim->getKey(),
            'subject_type' => $this->enumValue($claim->subject_type),
            'subject_label' => MembershipClaimPresenter::labelForSubject($claim->subject_type),
            'subject_title' => $subjectPresentation['subject_title'] ?? (string) $claim->subject_id,
            'subject_public_url' => $subjectPresentation['redirect_url'] ?? null,
            'status' => $this->enumValue($claim->status),
            'status_label' => MembershipClaimPresenter::labelForStatus($claim->status),
            'role_label' => MembershipClaimPresenter::roleLabel($claim),
            'justification' => $claim->justification,
            'granted_role_slug' => $claim->granted_role_slug,
            'reviewer_note' => $claim->reviewer_note,
            'created_at' => $this->optionalDateTimeString($claim->created_at),
            'reviewed_at' => $this->optionalDateTimeString($claim->reviewed_at),
            'cancelled_at' => $this->optionalDateTimeString($claim->cancelled_at),
            'can_cancel' => $claim->isPending() && (string) $claim->claimant_id === (string) $currentUser->getKey(),
            'reviewer' => $claim->reviewer?->only(['id', 'name', 'email']),
            'evidence' => $evidenceItems->map(fn (Media $media): array => [
                'id' => $media->getKey(),
                'name' => $media->name !== '' ? $media->name : $media->file_name,
                'url' => $media->getAvailableUrl(['thumb']) ?: $media->getUrl(),
            ])->all(),
        ];
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function optionalDateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function maxUploadSizeKb(): int
    {
        return (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);
    }
}
