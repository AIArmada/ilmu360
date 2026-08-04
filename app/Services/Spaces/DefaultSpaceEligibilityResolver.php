<?php

declare(strict_types=1);

namespace App\Services\Spaces;

use App\Contracts\SpaceEligibilityResolver;
use App\Models\Space;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class DefaultSpaceEligibilityResolver implements SpaceEligibilityResolver
{
    /**
     * @return Builder<Space>
     */
    public function catalogQuery(): Builder
    {
        return Space::query()->whereNull('venue_id');
    }

    /**
     * @return Builder<Space>
     */
    public function institutionQuery(string $institutionId): Builder
    {
        return $this->catalogQuery()
            ->where(function (Builder $query) use ($institutionId): void {
                $query
                    ->whereDoesntHave('institutions')
                    ->orWhereHas('institutions', function (Builder $institutionQuery) use ($institutionId): void {
                        $institutionQuery->whereKey($institutionId);
                    });
            });
    }

    /**
     * @return Builder<Space>
     */
    public function venueQuery(string $venueId): Builder
    {
        return Space::query()
            ->where(function (Builder $query) use ($venueId): void {
                $query
                    ->whereNull('venue_id')
                    ->orWhere('venue_id', $venueId);
            });
    }

    /**
     * @param  list<string>  $spaceIds
     */
    public function validateInstitutionSelection(string $institutionId, array $spaceIds): void
    {
        $spaceIds = $this->normalizeIds($spaceIds);

        if ($spaceIds === []) {
            return;
        }

        $allowedIds = $this->institutionQuery($institutionId)
            ->whereKey($spaceIds)
            ->pluck('id')
            ->map(strval(...))
            ->all();

        if (array_diff($spaceIds, $allowedIds) !== []) {
            throw ValidationException::withMessages([
                'space_ids' => __('Ruang yang dipilih tidak tersedia untuk institusi ini.'),
            ]);
        }
    }

    /**
     * @param  list<string>  $spaceIds
     */
    public function validateVenueSelection(string $venueId, array $spaceIds): void
    {
        $spaceIds = $this->normalizeIds($spaceIds);

        if ($spaceIds === []) {
            return;
        }

        $allowedIds = $this->venueQuery($venueId)
            ->whereKey($spaceIds)
            ->pluck('id')
            ->map(strval(...))
            ->all();

        if (array_diff($spaceIds, $allowedIds) !== []) {
            throw ValidationException::withMessages([
                'space_ids' => __('Ruang yang dipilih tidak tersedia untuk venue ini.'),
            ]);
        }
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return list<string>
     */
    private function normalizeIds(iterable $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            if (! is_scalar($id)) {
                continue;
            }

            $value = trim((string) $id);

            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}
