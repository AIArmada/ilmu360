<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

use App\Shared\Contributions\Enums\ContributionSubjectType;

/** Cross-surface contribution validation and comparison. */
interface ContributionSubmissionPreparation
{
    /**
     * @param  array<string, mixed>  $state
     * @return array{state: array<string, mixed>, proposer_note: string|null}
     */
    public function normalize(array $state): array;

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $originalData
     * @return array<string, mixed>
     */
    public function changedPayload(array $state, array $originalData): array;

    /** @param array<string, mixed> $state */
    public function ensureUniqueCreate(ContributionSubjectType $subjectType, array $state, string $validationKeyPrefix = ''): void;

    public function institutionMapsIdentityTakenByOther(?string $cid, ?string $placeId, string $excludeInstitutionId): bool;
}
