<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Shared\Directory\FollowableSubject;

/**
 * Admin series authoring and deletion boundary.
 *
 * Root transports (Filament/admin API/MCP) use this interface while the Series
 * model remains conventional. Every method takes scalar identifiers or plain
 * arrays; no Eloquent models cross this boundary.
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
     * Immutable series identity for share URL construction.
     *
     * @return array{id: string, slug: string, title: string}|null
     */
    public function shareIdentityBySlug(string $slug): ?array;

    public function isPubliclyReachable(string $seriesId): bool;
}
