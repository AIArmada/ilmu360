<?php

declare(strict_types=1);

namespace App\Support\Directory;

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use App\Shared\Directory\DirectoryOption;
use App\Shared\Directory\PrayerInstitutionCatalog;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentPrayerInstitutionCatalog implements PrayerInstitutionCatalog
{
    public function search(string $search, int $limit = 50): array
    {
        $query = $this->query();
        $search = trim($search);

        if ($search !== '') {
            $query->searchNameOrNickname($search);
        }

        return $query
            ->orderBy('name')
            ->limit($limit)
            ->with('names')->get(['id', 'name'])
            ->map(static fn (Institution $institution): DirectoryOption => new DirectoryOption(
                id: (string) $institution->id,
                label: $institution->display_name,
            ))
            ->all();
    }

    public function labels(array $institutionIds): array
    {
        if ($institutionIds === []) {
            return [];
        }

        $labels = Institution::query()
            ->whereKey($institutionIds)
            ->with('names')
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->id => $institution->display_name,
            ])
            ->all();

        foreach ($institutionIds as $institutionId) {
            $labels[$institutionId] ??= $institutionId;
        }

        return $labels;
    }

    public function selectableIds(array $institutionIds): array
    {
        if ($institutionIds === []) {
            return [];
        }

        return $this->query()
            ->whereKey($institutionIds)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return Builder<Institution>
     */
    private function query(): Builder
    {
        return Institution::query()
            ->active()
            ->where('status', InstitutionStatus::Verified->value);
    }
}
