<?php

declare(strict_types=1);

namespace App\Support\Share;

use App\Models\CommunityRelease;
use Carbon\CarbonImmutable;

/**
 * Public reader for community releases: latest release cells plus honest
 * insufficient-data/freshness states. Cells are coarse by construction;
 * no subject-level or precise recent drilldown exists on any public
 * read surface.
 */
final class CommunityReleaseReader
{
    /**
     * @return array<string, mixed>
     */
    public function latest(): array
    {
        // Latest PERIOD wins over latest publish: a backfilled older
        // month must never displace the current release on the public
        // surface.
        $release = CommunityRelease::query()
            ->orderByDesc('period_end')
            ->orderByDesc('published_at')
            ->first();

        if (! $release instanceof CommunityRelease) {
            return [
                'status' => 'insufficient-data',
                'message' => 'No community release has been published yet.',
                'freshness' => ['read_at' => CarbonImmutable::now('UTC')->toIso8601String()],
            ];
        }

        return [
            'status' => 'released',
            'period' => ['start' => (string) $release->period_start, 'end' => (string) $release->period_end],
            'sharer_count' => (int) $release->sharer_count,
            'participant_count' => (int) $release->participant_count,
            'cells' => $release->cells,
            'freshness' => [
                'published_at' => $release->published_at?->toIso8601String(),
                'read_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            ],
        ];
    }
}
