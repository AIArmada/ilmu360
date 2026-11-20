<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Public event-card collections owned by the Events feature.
 *
 * Directory and detail surfaces in other owners receive already-serialized
 * event cards plus totals, so they never query Event or EventKeyPerson
 * themselves.
 */
interface EventCollections
{
    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forInstitution(
        string $institutionId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array;

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forPerson(
        string $personId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array;

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forVenue(
        string $venueId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array;

    /**
     * @param  list<string>  $referenceIds
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forReferences(
        array $referenceIds,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array;

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forSeries(
        string $seriesId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array;

    /**
     * Public non-speaker key-person participations for a person.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function personParticipations(
        string $personId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array;
}
