<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api\Orm;

use App\Features\Institutions\Api\InstitutionSummary;
use App\Features\Reports\Api\ReportSubject;
use App\Shared\Directory\FollowableSubject;

/**
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
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
     * Documented transport: a hydrated institution model resolved by its
     * public slug for share-link title and route presentation.
     */
    public function findInstitutionBySlug(string $slug): ?object;

    /**
     * Documented transport: a hydrated institution model resolved by its
     * primary key for the created-contribution response payload.
     */
    public function findInstitutionByKey(string $institutionId): ?object;

    /**
     * Documented transport: the institution the account is a member of, used
     * to authorise institution-scoped submission entry points.
     */
    public function findMemberInstitution(string $institutionId, string $accountId): ?object;

    /**
     * Documented transport: institutions for the supplied ids, ordered by
     * name.
     *
     * @param  list<string>  $institutionIds
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Features\Institutions\Models\Institution>
     */
    public function institutionsByIds(array $institutionIds): object;

    /**
     * Documented transport: institution detail record with directory relation
     * set, resolved by slug or uuid.
     */
    public function findDetailRecord(string $identifier): ?object;

    /**
     * Documented transport: aggregate discovery institution items with the
     * upcoming public event count and directory relations.
     *
     * @param  list<string>  $institutionIds
     */
    public function aggregateSearchItemsQuery(array $institutionIds = []): object;

    /**
     * Documented transport: public institution directory base query with the
     * follow-state select, public event count subquery and location scope.
     */
    public function baseDirectoryQuery(
        ?string $type = null,
        ?string $countryId = null,
        ?string $stateId = null,
        ?string $cityId = null,
        ?string $adminArea1Id = null,
        ?string $adminArea2Id = null,
        ?string $accountId = null,
    ): object;

    /**
     * Documented transport: institutions the account is a member of, ordered
     * by name, with the member count eager loaded.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Features\Institutions\Models\Institution>
     */
    public function institutionsForMember(string $accountId): object;

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
     * Documented transport: a fresh institution query for the caller's
     * read-only submitter constraints. Callers must not write through it.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Features\Institutions\Models\Institution>
     */
    public function newInstitutionQuery(): object;

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
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Features\Institutions\Models\Institution>
     */
    public function followedInstitutions(array $institutionIds): object;

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
