<?php

declare(strict_types=1);

namespace App\Features\Places\Services;

use App\Features\Places\Api\PlaceCatalog;
use App\Features\Places\Api\SpaceEligibility;
use App\Features\Places\Models\Venue;

final readonly class EloquentPlaceCatalog implements PlaceCatalog
{
    public function __construct(private SpaceEligibility $spaceEligibilityResolver) {}

    public function venues(?string $search = null, int $limit = 50): array
    {
        $query = Venue::query()
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('name');

        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $query->whereLike('name', '%'.$normalizedSearch.'%');
        }

        return $query
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn (Venue $venue): array => [
                'id' => (string) $venue->id,
                'label' => (string) $venue->name,
            ])
            ->all();
    }

    public function spaces(?string $institutionId = null): array
    {
        $options = is_string($institutionId) && $institutionId !== ''
            ? $this->spaceEligibilityResolver->institutionOptions($institutionId, activeOnly: true)
            : $this->spaceEligibilityResolver->catalogOptions(activeOnly: true);

        return collect($options)
            ->map(static fn (string $label, string $id): array => ['id' => $id, 'label' => $label])
            ->values()
            ->all();
    }
}
