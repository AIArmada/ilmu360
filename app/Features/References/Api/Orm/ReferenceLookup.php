<?php

declare(strict_types=1);

namespace App\Features\References\Api\Orm;

use App\Features\References\Api\ReferenceSummary;
use App\Features\Reports\Api\ReportSubject;
use App\Shared\Directory\FollowableSubject;

/**
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: References/FeatureServiceProvider.php, Engagement/Queries, Engagement/Services, Engagement/EntryPoints, References/Queries, Events/EntryPoints
 */
interface ReferenceLookup
{
    /**
     * Resolve a followable reference and publish its visibility fact.
     */
    public function findFollowableSubject(string $identifier): ?FollowableSubject;

    /**
     * Resolve a reference only when it is eligible for a directory card.
     */
    public function findDirectoryFollowableSubject(string $identifier): ?FollowableSubject;

    public function referenceMorphType(): string;

    public function exists(string $referenceId): bool;

    /**
     * Documented transport: a hydrated reference model resolved by slug or
     * uuid with owner scoping removed and public visibility applied, for
     * share-link title and route presentation.
     */
    public function findReferenceByIdentifier(string $identifier): ?object;

    public function resolveIdentifier(string $identifier): ?string;

    public function find(string $identifier): ?ReferenceSummary;

    public function findReportSubject(string $id): ?ReportSubject;

    public function resolveReportSubject(string $identifier): ?ReportSubject;

    /** @return array<string, string> */
    public function activeTitles(): array;

    /** @return array<string, string> */
    public function searchOptions(string $search, int $limit = 50): array;

    /**
     * @param  list<string>  $referenceIds
     * @return array<string, string>
     */
    public function optionLabels(array $referenceIds): array;

    /** @return array<string, string> */
    public function adminOptions(string $search = '', int $limit = 50): array;

    /**
     * @param  list<string>  $referenceIds
     * @return array<string, string>
     */
    public function adminLabels(array $referenceIds): array;

    public function title(string $referenceId): ?string;

    /**
     * Documented transport: followed references for the supplied ids with
     * media loaded, ordered by title.
     *
     * @param  list<string>  $referenceIds
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Features\References\Models\Reference>
     */
    public function followedReferences(array $referenceIds): object;

    /**
     * Documented transport: references for the supplied ids, ordered by title.
     *
     * @param  list<string>  $referenceIds
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Features\References\Models\Reference>
     */
    public function referencesByIds(array $referenceIds): object;

    /**
     * Documented transport: reference detail record with media, social
     * profiles and parent chain, resolved by slug or uuid.
     */
    public function findDetailRecord(string $identifier): ?object;

    /**
     * Documented transport: public reference directory base query with the
     * follow-state select, event-count subquery and directory relations.
     */
    public function baseDirectoryQuery(?string $accountId = null): object;
}
