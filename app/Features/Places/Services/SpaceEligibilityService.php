<?php

namespace App\Features\Places\Services;

use App\Models\Space;

use App\Features\Places\Api\SpaceEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final readonly class SpaceEligibilityService implements SpaceEligibility
{
    public function catalogOptions(bool $activeOnly = false): array
    {
        return $this->optionLabels($this->catalogQuery(), $activeOnly);
    }

    public function institutionOptions(string $institutionId, bool $activeOnly = false): array
    {
        return $this->optionLabels($this->institutionQuery($institutionId), $activeOnly);
    }

    public function venueOptions(string $venueId, bool $activeOnly = false): array
    {
        return $this->optionLabels($this->venueQuery($venueId), $activeOnly);
    }

    public function validateInstitutionSelection(string $institutionId, array $spaceIds): void
    {
        $this->validateSelection(
            $this->institutionQuery($institutionId),
            $spaceIds,
            __('Ruang yang dipilih tidak tersedia untuk institusi ini.'),
        );
    }

    public function validateVenueSelection(string $venueId, array $spaceIds): void
    {
        $this->validateSelection(
            $this->venueQuery($venueId),
            $spaceIds,
            __('Ruang yang dipilih tidak tersedia untuk venue ini.'),
        );
    }

    /** @return Builder<covariant Model> */
    private function catalogQuery(): Builder
    {
        return Space::query()->whereNull('venue_id');
    }

    /** @return Builder<covariant Model> */
    private function institutionQuery(string $institutionId): Builder
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

    /** @return Builder<covariant Model> */
    private function venueQuery(string $venueId): Builder
    {
        return Space::query()
            ->where(function (Builder $query) use ($venueId): void {
                $query
                    ->whereNull('venue_id')
                    ->orWhere('venue_id', $venueId);
            });
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return array<string, string>
     */
    private function optionLabels(Builder $query, bool $activeOnly): array
    {
        if ($activeOnly) {
            $query->where('status', 'active');
        }

        return $query
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
            ->all();
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $spaceIds
     */
    private function validateSelection(Builder $query, array $spaceIds, string $message): void
    {
        $normalizedIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $spaceIds),
            static fn (string $id): bool => $id !== '',
        )));

        if ($normalizedIds === []) {
            return;
        }

        $allowedIds = $query
            ->whereKey($normalizedIds)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if (array_diff($normalizedIds, $allowedIds) !== []) {
            throw ValidationException::withMessages(['space_ids' => $message]);
        }
    }
}
