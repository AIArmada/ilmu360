<?php

declare(strict_types=1);

namespace App\Features\References\Api\Orm;

use App\Features\References\Api\ReferenceSummary;
use App\Features\References\Models\Reference;
use App\Features\Reports\Api\ReportSubject;
use App\Shared\Directory\FollowableSubject;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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
     * Immutable reference identity for share URL construction.
     *
     * @return array{id: string, slug: string, title: string}|null
     */
    public function shareIdentityByIdentifier(string $identifier): ?array;

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
     * @return EloquentCollection<int, Reference>
     */
    public function followedReferences(array $referenceIds): EloquentCollection;

    /**
     * Documented transport: references for the supplied ids, ordered by title.
     *
     * @param  list<string>  $referenceIds
     * @return EloquentCollection<int, Reference>
     */
    public function referencesByIds(array $referenceIds): EloquentCollection;
}
