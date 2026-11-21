<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Scalar event-schedule reads owned by the Events feature.
 *
 * Ordinary cross-owner reads return immutable rows, counts and page metadata.
 * ORM-shaped framework integration lives in {@see EventOrmPort}.
 */
interface EventScheduleReads
{
    /**
     * Active events for the supplied ids, in starts_at order.
     *
     * @param  list<string>  $eventIds
     * @return array{
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     has_more: bool,
     *     next_page: int|null
     * }
     */
    public function activeEventsForIds(array $eventIds, int $page, int $perPage): array;

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, array{total: int, public: int}>
     */
    public function institutionEventCounts(array $institutionIds): array;

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     last_page: int
     * }
     */
    public function institutionEventsPage(
        string $institutionId,
        string $search,
        string $status,
        string $visibility,
        string $sort,
        int $perPage,
        int $page,
    ): array;

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, array{
     *     events_count: int,
     *     next_event: array{slug: string, title: string, starts_at: string}|null
     * }>
     */
    public function institutionDirectoryFacts(array $institutionIds): array;

    public function eventStatus(string $eventId): ?string;

    public function isPubliclyReachable(string $eventId): bool;

    public function eventTable(): string;

    public function keyPersonTable(): string;

    public function seriesTable(): string;

    public function institutionRegistrationCount(string $institutionId): int;

    /**
     * Distinct speakers on the institution's upcoming public events.
     */
    public function institutionSpeakerCount(string $institutionId): int;

    /**
     * Institution event counts by category, keyed by institution id.
     *
     * @param  list<string>  $institutionIds
     * @return array<string, array{total: int, public: int, upcoming: int, draft: int, pending: int}>
     */
    public function institutionEventStats(array $institutionIds): array;
}
