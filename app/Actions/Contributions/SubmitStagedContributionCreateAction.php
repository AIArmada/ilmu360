<?php

namespace App\Actions\Contributions;

use App\Enums\ContributionSubjectType;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class SubmitStagedContributionCreateAction
{
    use AsAction;

    public function __construct(
        protected ContributionEntityMutationService $contributionEntityMutationService,
        protected EnsureUniqueContributionCreateAction $ensureUniqueContributionCreateAction,
        protected ResolveContributionSubmissionStateAction $resolveContributionSubmissionStateAction,
        protected SubmitContributionCreateRequestAction $submitContributionCreateRequestAction,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     * @param  (callable(Institution|Speaker): void)|null  $persistRelationships
     */
    public function handle(
        ContributionSubjectType $subjectType,
        array $state,
        User $user,
        ?callable $persistRelationships = null,
        string $validationKeyPrefix = '',
    ): Institution|Speaker {
        $submissionState = $this->resolveContributionSubmissionStateAction->handle($state);
        $state = $submissionState['state'];

        $this->ensureUniqueContributionCreateAction->handle($subjectType, $state, $validationKeyPrefix);

        $entity = match ($subjectType) {
            ContributionSubjectType::Institution => $this->contributionEntityMutationService->createInstitution($state, $user),
            ContributionSubjectType::Speaker => $this->contributionEntityMutationService->createSpeaker($state, $user),
            default => throw new InvalidArgumentException("Unsupported contribution subject type [{$subjectType->value}]"),
        };

        if ($persistRelationships !== null) {
            $persistRelationships($entity);
        }

        $this->submitContributionCreateRequestAction->handle(
            $subjectType,
            $user,
            Arr::except($state, $this->mediaFieldsFor($subjectType)),
            $submissionState['proposer_note'],
            $entity,
        );

        return $entity;
    }

    /**
     * @return list<string>
     */
    protected function mediaFieldsFor(ContributionSubjectType $subjectType): array
    {
        return match ($subjectType) {
            ContributionSubjectType::Institution => ['logo', 'cover', 'gallery'],
            ContributionSubjectType::Speaker => ['avatar', 'cover', 'gallery'],
            default => [],
        };
    }
}
