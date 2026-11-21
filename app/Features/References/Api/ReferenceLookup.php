<?php

declare(strict_types=1);

namespace App\Features\References\Api;

use App\Features\Reports\Api\ReportSubject;
use App\Shared\Directory\FollowableSubject;

/** Immutable reference identity and directory lookup boundary. */
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
     * Followed reference summaries in title order.
     *
     * @param  list<string>  $referenceIds
     * @return list<ReferenceSummary>
     */
    public function followedReferences(array $referenceIds): array;

    /**
     * Reference summaries in title order.
     *
     * @param  list<string>  $referenceIds
     * @return list<ReferenceSummary>
     */
    public function referencesByIds(array $referenceIds): array;
}
