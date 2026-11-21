<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api\Orm;

use App\Features\Contributions\Api\ContributionOrganizer;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Models\User;

/**
 * Submission context boundary for the event submission entry point.
 *
 * The owner keeps orchestration inside its service; this contract exposes the
 * normalization and authorization steps the event submission transport calls,
 * with owner models passed opaquely so the boundary exposes no private types.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, Events/Support, Events/EntryPoints, Contributions/Services
 */
interface SubmissionContext
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function authorizedScopedInstitutionFor(
        ?Institution $scopedInstitution,
        ?User $submitter,
        string $validationKeyPrefix,
        array $state = [],
    ): ?Institution;

    public function authorizedEventContainerFor(
        ?Event $eventContainer,
        ?User $submitter,
        ?Institution $scopedInstitution,
        string $validationKeyPrefix,
    ): ?Event;

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeScopedInstitutionStateFor(
        array $validated,
        ?Institution $scopedInstitution,
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

    public function resolvePrimaryOrganizer(mixed $primaryOrganizerId): ?ContributionOrganizer;

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
    public function resolveTargetLocationFor(array $validated, ContributionOrganizer $primaryOrganizer): array;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function resolveSelectedOccurrenceIdFor(
        array $validated,
        ?Event $eventContainer,
        string $validationKeyPrefix,
    ): ?string;
}
