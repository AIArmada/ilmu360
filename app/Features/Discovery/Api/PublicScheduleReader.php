<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Models\User;

/**
 * Public schedule reading boundary.
 *
 * One owner decides which occurrences/sessions are public, what relations the
 * public pages load, and how a leaf is shaped, so every public schedule surface
 * renders the same set.
 */
interface PublicScheduleReader
{
    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, PublicScheduleLeaf>
     */
    public function search(
        ?string $query = null,
        array $filters = [],
        int $perPage = 12,
        string $sort = 'time',
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $radiusKm = null,
    );

    /**
     * @return \Illuminate\Support\Collection<int, PublicScheduleLeaf>
     */
    public function homepageSelection(?User $user);

    /**
     * @param  iterable<int, \App\Features\Events\Models\Event>  $events
     * @return \Illuminate\Support\Collection<int, PublicScheduleLeaf>
     */
    public function leavesForEvents(iterable $events);

    /**
     * Build one leaf from already-loaded models, so pages that own the
     * occurrence/session load and visibility filter keep their query budget.
     *
     * @param  iterable<int, object>  $sessions
     */
    public function leafFor(object $event, object $occurrence, iterable $sessions = []): PublicScheduleLeaf;

    public function findOccurrence(object $event, string $slug): ?EventOccurrence;

    public function findSession(EventOccurrence $occurrence, string $slug): ?EventSession;

    /**
     * @return array<string, mixed>
     */
    public function publicRelations(): array;

    /**
     * @return array<int|string, mixed>
     */
    public function occurrencePageRelations(): array;

    public function hydrateOccurrencePageVenues(object $event): void;
}
