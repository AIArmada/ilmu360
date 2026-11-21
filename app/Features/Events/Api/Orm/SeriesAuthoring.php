<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use App\Shared\Directory\FollowableSubject;

/**
 * Admin series authoring and deletion boundary.
 *
 * Root transports (Filament/admin API/MCP) use this interface while the Series
 * model remains conventional. Every method takes scalar identifiers or plain
 * arrays; no Eloquent models cross this boundary.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Events/FeatureServiceProvider.php, Engagement/EntryPoints, Events/Services, Events/EntryPoints, Contributions/EntryPoints, ShareTracking/Policies
 */
interface SeriesAuthoring
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?string $seriesId = null): string;

    public function delete(string $seriesId): bool;

    /**
     * Resolve a followable series and publish its visibility fact.
     */
    public function findFollowableSeries(string $identifier): ?FollowableSubject;

    /**
     * @param  list<string>  $seriesIds
     */
    public function deleteMany(array $seriesIds): int;

    /** @return array<string, string> */
    public function titleOptions(): array;

    /**
     * Documented transport: a hydrated Series model for route/slug/title
     * presentation contracts.
     */
    public function findSeriesBySlug(string $slug): ?object;

    public function isPubliclyReachable(string $seriesId): bool;
}
