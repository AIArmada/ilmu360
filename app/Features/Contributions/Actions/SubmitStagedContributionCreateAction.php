<?php

declare(strict_types=1);

namespace App\Features\Contributions\Actions;

use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Features\Contributions\Api\ContributionSubmissionPreparation;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\Api\StagedContributionCreates;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\References\Api\ReferenceWorkflow;
use App\Platform\Concurrency\MapsIdentityLock;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Owns staged contribution orchestration; subject features own entity writes. */
final readonly class SubmitStagedContributionCreateAction implements StagedContributionCreates
{
    public function __construct(
        private InstitutionWorkflow $institutions,
        private PersonWorkflow $persons,
        private ReferenceWorkflow $references,
        private ContributionSubmissionPreparation $preparation,
        private ContributionRequestLifecycle $requests,
        private MapsIdentityLock $mapsIdentityLock,
    ) {}

    public function submit(
        ContributionSubjectType $subjectType,
        array $state,
        string $proposerId,
        ?callable $persistRelationships = null,
        string $validationKeyPrefix = '',
    ): string {
        $create = (fn (): string => DB::transaction(function () use ($subjectType, $state, $proposerId, $persistRelationships, $validationKeyPrefix): string {
            $normalized = $this->preparation->normalize($state);
            $payload = $normalized['state'];

            $this->preparation->ensureUniqueCreate($subjectType, $payload, $validationKeyPrefix);

            $entityId = match ($subjectType) {
                ContributionSubjectType::Institution => $this->institutions->createFromContribution($payload, $proposerId),
                ContributionSubjectType::Person => $this->persons->createFromContribution($payload, $proposerId),
                ContributionSubjectType::Reference => $this->references->createFromContribution($payload),
                default => throw new InvalidArgumentException("Unsupported contribution subject type [{$subjectType->value}]"),
            };

            // Relationship and media writes must roll back if the request
            // cannot be submitted. Never move this outside the transaction.
            if ($persistRelationships !== null) {
                $persistRelationships($entityId);
            }

            $this->requests->submitCreate(
                $subjectType,
                $proposerId,
                Arr::except($payload, $this->mediaFieldsFor($subjectType)),
                $normalized['proposer_note'],
                $subjectType->value,
                $entityId,
            );

            return $entityId;
        }));

        return $subjectType === ContributionSubjectType::Institution
            ? $this->mapsIdentityLock->handle($state, $create)
            : $create();
    }

    /** @return list<string> */
    private function mediaFieldsFor(ContributionSubjectType $subjectType): array
    {
        return match ($subjectType) {
            ContributionSubjectType::Institution => ['logo', 'cover', 'gallery'],
            ContributionSubjectType::Person => ['avatar', 'cover', 'gallery'],
            ContributionSubjectType::Reference => ['front_cover', 'back_cover', 'gallery'],
            default => [],
        };
    }
}
