<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

use App\Features\Reports\Api\ReportSubject;
use App\Shared\Directory\FollowableSubject;

/**
 * Consumers: Persons/FeatureServiceProvider.php, Engagement/Queries, Engagement/Services, Engagement/EntryPoints, References/Models, References/Services
 */
interface PersonLookup
{
    /**
     * Resolve a followable person and publish its visibility fact.
     */
    public function findFollowableSubject(string $identifier): ?FollowableSubject;

    /**
     * Resolve a person only when they are eligible for a directory card.
     */
    public function findDirectoryFollowableSubject(string $identifier): ?FollowableSubject;

    /**
     * Pending/verified authors available to the reference author picker.
     *
     * @return array<string, string>
     */
    public function authorOptions(string $search = '', int $limit = 50): array;

    /**
     * @param  list<string>  $personIds
     * @return array<string, string>
     */
    public function authorLabels(array $personIds): array;

    /**
     * Immutable person identity for share URL construction.
     *
     * @return array{id: string, slug: string, title: string}|null
     */
    public function shareIdentityBySlug(string $slug): ?array;

    /**
     * Immutable person identity for the created-contribution response.
     *
     * @return array{id: string, slug: string, name: string, status: string}|null
     */
    public function contributionResponseById(string $personId): ?array;

    /**
     * Documented transport: persons the account is a member of, ordered by
     * name, with the member count eager loaded.
     *
     * @return list<PersonDirectoryRecord>
     */
    public function personsForMember(string $accountId): array;

    /**
     * Verified/pending speakers keyed by id, ordered by name, using the
     * formatted display name (including title assignments).
     *
     * @return array<string, string>
     */
    public function submissionNameOptions(): array;

    /**
     * Membership-application person options keyed by slug, ordered by name,
     * using the formatted display name.
     *
     * @return array<string, string>
     */
    public function membershipApplicationOptions(string $search = ''): array;

    /**
     * Membership-application label for one person slug.
     */
    public function membershipApplicationLabel(string $slug): ?string;

    /**
     * Documented transport: followed persons for the supplied ids with media
     * loaded, ordered by name.
     *
     * @param  list<string>  $personIds
     * @return list<PersonDirectoryRecord>
     */
    public function followedPersons(array $personIds): array;

    /**
     * Documented transport: persons for the supplied ids, ordered by name.
     *
     * @param  list<string>  $personIds
     * @return list<PersonDirectoryRecord>
     */
    public function personsByIds(array $personIds): array;

    public function resolveIdentifier(string $identifier): ?string;

    public function find(string $identifier): ?PersonSummary;

    public function findReportSubject(string $id): ?ReportSubject;

    public function resolveReportSubject(string $identifier): ?ReportSubject;

    /** @return list<string> */
    public function publicSearchIds(string $search): array;

    public function exists(string $personId): bool;

    public function assertExists(string $personId): void;

    public function personMorphType(): string;

    /** @return array<string, string> */
    public function directoryOptions(string $search = '', int $limit = 50): array;

    /**
     * @param  list<string>  $personIds
     * @return array<string, string>
     */
    public function directoryLabels(array $personIds): array;

    /** @return array<string, string> */
    public function nameOptions(string $search = '', int $limit = 50): array;

    /**
     * @param  list<string>  $personIds
     * @return array<string, string>
     */
    public function namesByIds(array $personIds): array;
}
