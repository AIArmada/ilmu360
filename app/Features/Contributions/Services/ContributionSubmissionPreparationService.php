<?php

declare(strict_types=1);

namespace App\Features\Contributions\Services;

use App\Features\Contributions\Actions\EnsureUniqueContributionCreateAction;
use App\Features\Contributions\Actions\NormalizeContributionSubmissionAction;
use App\Features\Contributions\Actions\ResolveContributionChangedPayloadAction;
use App\Features\Contributions\Api\ContributionSubmissionPreparation;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;

final readonly class ContributionSubmissionPreparationService implements ContributionSubmissionPreparation
{
    public function __construct(
        private NormalizeContributionSubmissionAction $normalizer,
        private ResolveContributionChangedPayloadAction $changedPayload,
        private EnsureUniqueContributionCreateAction $uniquenessGuard,
    ) {}

    public function normalize(array $state): array
    {
        return $this->normalizer->handle($state);
    }

    public function changedPayload(array $state, array $originalData): array
    {
        return $this->changedPayload->handle($state, $originalData);
    }

    public function ensureUniqueCreate(ContributionSubjectType $subjectType, array $state, string $validationKeyPrefix = ''): void
    {
        $this->uniquenessGuard->handle($subjectType, $state, $validationKeyPrefix);
    }

    public function institutionMapsIdentityTakenByOther(?string $cid, ?string $placeId, string $excludeInstitutionId): bool
    {
        return $this->uniquenessGuard->institutionMapsIdentityTakenByOther($cid, $placeId, $excludeInstitutionId);
    }
}
