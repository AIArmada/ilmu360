<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

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
     * @param  list<string>  $seriesIds
     */
    public function deleteMany(array $seriesIds): int;
}
