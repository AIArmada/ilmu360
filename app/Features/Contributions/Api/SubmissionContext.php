<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

use App\Models\User;

/**
 * Submission context boundary for the event submission entry point.
 *
 * The owner keeps orchestration inside its service; this contract exposes the
 * normalization and authorization steps the event submission transport calls,
 * with owner models passed opaquely so the boundary exposes no private types.
 */
interface SubmissionContext
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function authorizedScopedInstitutionFor(
        ?object $scopedInstitution,
        ?User $submitter,
        string $validationKeyPrefix,
        array $state = [],
    ): ?object;

    public function authorizedEventContainerFor(
        ?object $eventContainer,
        ?User $submitter,
        ?object $scopedInstitution,
        string $validationKeyPrefix,
    ): ?object;

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeScopedInstitutionStateFor(
        array $validated,
        ?object $scopedInstitution,
        string $validationKeyPrefix,
    ): array;

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeSpaceSelection(array $validated): array;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function resolveSubmissionCountryId(array $validated, string $validationKeyPrefix): string;

    /**
     * @return ContributionOrganizer|null Opaque organizer identity (kind + id), or null.
     */
    public function resolvePrimaryOrganizer(mixed $primaryOrganizerId): mixed;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function assertConditionalRequirements(array $validated, ?string $organizerKind, string $validationKeyPrefix): void;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function assertSpaceSelectionIsEligible(
        array $validated,
        ?string $targetInstitutionId,
        ?string $targetVenueId,
        string $validationKeyPrefix,
    ): void;

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: string|null, 1: string|null}
     */
    public function resolveTargetLocationFor(array $validated, object $primaryOrganizer): array;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function resolveSelectedOccurrenceIdFor(
        array $validated,
        ?object $eventContainer,
        string $validationKeyPrefix,
    ): ?string;
}
