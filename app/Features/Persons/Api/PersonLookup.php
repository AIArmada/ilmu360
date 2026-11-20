<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

use App\Features\Reports\Api\ReportSubject;
use App\Shared\Directory\FollowableSubject;

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
     * Documented transport: a hydrated person model resolved by its public
     * slug for share-link title and route presentation.
     */
    public function findPersonBySlug(string $slug): ?object;

    /**
     * Documented transport: a hydrated person model resolved by its primary
     * key for the created-contribution response payload.
     */
    public function findPersonByKey(string $personId): ?object;

    /**
     * Documented transport: persons the account is a member of, ordered by
     * name, with the member count eager loaded.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Features\Persons\Models\Person>
     */
    public function personsForMember(string $accountId): object;

    /**
     * Verified/pending speakers keyed by id, ordered by name, using the
     * formatted display name (including title assignments).
     *
     * @return array<string, string>
     */
    public function submissionNameOptions(): array;

    /**
     * Documented transport: a fresh person query for the caller's read-only
     * submitter constraints. Callers must not write through it.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Features\Persons\Models\Person>
     */
    public function newPersonQuery(): object;

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
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Features\Persons\Models\Person>
     */
    public function followedPersons(array $personIds): object;

    /**
     * Documented transport: persons for the supplied ids, ordered by name.
     *
     * @param  list<string>  $personIds
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Features\Persons\Models\Person>
     */
    public function personsByIds(array $personIds): object;

    /**
     * Documented transport: person detail record with the directory relation
     * set, resolved by slug or uuid.
     */
    public function findDetailRecord(string $identifier): ?object;

    /**
     * Documented transport: public person directory base query with the
     * follow-state select, upcoming public event count and directory
     * relations.
     */
    public function baseDirectoryQuery(?string $accountId = null): object;

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
