<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api;

use App\Features\Reports\Api\ReportSubject;
use App\Shared\Directory\FollowableSubject;

/**
 * Consumers: Institutions/FeatureServiceProvider.php, Engagement/Queries, Engagement/Services, Engagement/EntryPoints, Prayer/Support, Events/Queries
 */
interface InstitutionLookup
{
    /**
     * Resolve a followable institution and publish its visibility fact.
     */
    public function findFollowableSubject(string $identifier): ?FollowableSubject;

    /**
     * Resolve an institution only when it is eligible for a directory card.
     */
    public function findDirectoryFollowableSubject(string $identifier): ?FollowableSubject;

    /**
     * Every institution name keyed by id, ordered by name.
     *
     * @return array<string, string>
     */
    public function allNameOptions(): array;

    public function primaryAddressId(string $institutionId): ?string;

    /**
     * @return array{found: bool, capacity: int|null}
     */
    public function institutionSpaceCapacity(string $institutionId, string $venueSpaceId): array;

    /**
     * Immutable institution identity for share URL construction.
     *
     * @return array{id: string, slug: string, title: string}|null
     */
    public function shareIdentityBySlug(string $slug): ?array;

    /**
     * Immutable institution identity for the created-contribution response.
     *
     * @return array{id: string, slug: string, name: string, status: string}|null
     */
    public function contributionResponseById(string $institutionId): ?array;

    /**
     * Documented transport: the institution the account is a member of, used
     * to authorise institution-scoped submission entry points.
     */
    public function findMemberInstitution(string $institutionId, string $accountId): ?InstitutionDirectoryRecord;

    /**
     * Documented transport: institutions for the supplied ids, ordered by
     * name.
     *
     * @param  list<string>  $institutionIds
     * @return list<InstitutionDirectoryRecord>
     */
    public function institutionsByIds(array $institutionIds): array;

    /**
     * Documented transport: institutions the account is a member of, ordered
     * by name, with the member count eager loaded.
     *
     * @return list<InstitutionDirectoryRecord>
     */
    public function institutionsForMember(string $accountId): array;

    /**
     * Publicly visible institutions keyed by id, ordered by name, using the
     * primary-name display label.
     *
     * @return array<string, string>
     */
    public function visibleDisplayNameOptions(): array;

    /**
     * Institutions with a verified/pending status keyed by id, ordered by
     * name, matching the contribution form search across name and nicknames.
     *
     * @return array<string, string>
     */
    public function searchDisplayNameOptions(string $search, int $limit = 50): array;

    /**
     * Display labels for the supplied institution ids.
     *
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    public function displayNamesByIds(array $institutionIds): array;

    /**
     * Membership-application institution options keyed by id, ordered by
     * name, labelled with the display name and primary-address hierarchy.
     *
     * @return array<string, string>
     */
    public function membershipApplicationOptions(string $search = ''): array;

    /**
     * Membership-application label for one institution id.
     */
    public function membershipApplicationLabel(string $institutionId): ?string;

    /**
     * Documented transport: followed institutions for the supplied ids with
     * media loaded, ordered by name.
     *
     * @param  list<string>  $institutionIds
     * @return list<InstitutionDirectoryRecord>
     */
    public function followedInstitutions(array $institutionIds): array;

    public function resolveIdentifier(string $identifier): ?string;

    public function find(string $identifier): ?InstitutionSummary;

    /** @return list<\App\Shared\Directory\DirectoryOption> */
    public function searchPrayerInstitutions(string $search, int $limit = 50): array;

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    public function prayerInstitutionLabels(array $institutionIds): array;

    /**
     * @param  list<string>  $institutionIds
     * @return list<string>
     */
    public function selectablePrayerInstitutionIds(array $institutionIds): array;

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, string|null>
     */
    public function rawNamesByIds(array $institutionIds): array;

    public function findReportSubject(string $id): ?ReportSubject;

    public function resolveReportSubject(string $identifier): ?ReportSubject;

    /** @return list<string> */
    public function publicSearchIds(string $search): array;

    public function institutionMorphType(): string;

    public function exists(string $institutionId): bool;

    public function assertExists(string $institutionId): void;

    public function displayName(string $institutionId): ?string;

    public function visibleDisplayName(string $institutionId): ?string;

    /**
     * Public institution options constrained by the same location scope as the
     * event listing filters.
     *
     * @param  array<string, string>  $areaAssignments
     * @return array<string, string>
     */
    public function locationOptions(
        ?string $countryId,
        ?string $stateId,
        array $areaAssignments,
        string $search = '',
        int $limit = 50,
    ): array;

    /**
     * @param  array<string, string>  $areaAssignments
     */
    public function locationContains(
        string $institutionId,
        ?string $countryId,
        ?string $stateId,
        array $areaAssignments,
    ): bool;

    /** @return array<string, string> */
    public function nameOptions(string $search = '', int $limit = 50): array;

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    public function namesByIds(array $institutionIds): array;
}
