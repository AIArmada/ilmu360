<?php

declare(strict_types=1);

namespace App\Actions\Spaces;

use AIArmada\Events\Models\VenueSpaceType;
use App\Models\Institution;
use App\Models\Space;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final class SaveSpaceAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Space $space = null): Space
    {
        $creating = ! $space instanceof Space;
        $space ??= new Space;

        if (array_key_exists('venue_id', $data)) {
            $venueId = $this->normalizeOptionalString($data['venue_id']);

            if ($venueId === null && ! $creating && $space->venue_id !== null) {
                throw ValidationException::withMessages([
                    'venue_id' => __('Venue-owned spaces cannot be converted into catalog spaces.'),
                ]);
            }

            $space->venue_id = $venueId;
        }

        if (
            $space->venue_id !== null
            && (array_key_exists('institutions', $data) || array_key_exists('institution_space_overrides', $data))
        ) {
            throw ValidationException::withMessages([
                'institutions' => __('Venue-owned spaces cannot be linked to institutions.'),
            ]);
        }

        $space->fill([
            'name' => $this->normalizeRequiredString($data['name'] ?? $space->name, 'name'),
            'slug' => $this->normalizeRequiredString($data['slug'] ?? $space->slug, 'slug'),
            'code' => array_key_exists('code', $data) ? $this->normalizeOptionalString($data['code']) : $space->code,
            'space_type' => array_key_exists('space_type', $data) ? $this->normalizeOptionalString($data['space_type']) : $space->space_type,
            'level' => array_key_exists('level', $data) ? $this->normalizeOptionalString($data['level']) : $space->level,
            'unit_no' => array_key_exists('unit_no', $data) ? $this->normalizeOptionalString($data['unit_no']) : $space->unit_no,
            'block' => array_key_exists('block', $data) ? $this->normalizeOptionalString($data['block']) : $space->block,
            'wing' => array_key_exists('wing', $data) ? $this->normalizeOptionalString($data['wing']) : $space->wing,
            'capacity' => array_key_exists('capacity', $data)
                ? $this->normalizeCapacity($data['capacity'])
                : $space->capacity,
            'status' => array_key_exists('status', $data)
                ? $this->normalizeStatus($data['status'])
                : ($creating ? 'active' : (string) $space->status),
            'visibility' => array_key_exists('visibility', $data)
                ? $this->normalizeVisibility($data['visibility'])
                : ($creating ? 'public' : (string) ($space->visibility ?? 'public')),
        ]);

        $this->validateSpaceType($space->space_type);
        $this->ensureUniqueSlug($space, (string) $space->slug);
        $space->save();

        $hasInstitutionIds = array_key_exists('institutions', $data);
        $hasInstitutionSpaceOverrides = array_key_exists('institution_space_overrides', $data)
            && $data['institution_space_overrides'] !== [];

        if ($hasInstitutionIds || $hasInstitutionSpaceOverrides) {
            $existingCapacities = $space->institutions()
                ->get()
                ->mapWithKeys(fn (Institution $institution): array => [
                    (string) $institution->getKey() => ($pivot = $institution->getRelationValue('pivot')) instanceof Pivot
                        ? $pivot->getAttribute('capacity')
                        : null,
                ])
                ->all();
            $institutionIds = $this->normalizeInstitutionIds($data['institutions'] ?? []);
            $overrides = $hasInstitutionSpaceOverrides
                ? $this->normalizeInstitutionSpaceOverrides($data['institution_space_overrides'])
                : [];

            $institutionIds = array_values(array_unique([
                ...$institutionIds,
                ...array_keys($overrides),
            ]));

            $space->auditSync(
                'institutions',
                $institutionIds,
                true,
                ['institutions.id', 'institutions.name'],
            );

            if ($hasInstitutionSpaceOverrides) {
                $space->institutions()->sync(collect($institutionIds)->mapWithKeys(
                    fn (string $institutionId): array => [$institutionId => ['capacity' => $overrides[$institutionId] ?? null]],
                )->all());
            } else {
                $space->institutions()->sync(collect($institutionIds)->mapWithKeys(
                    fn (string $institutionId): array => [$institutionId => [
                        'capacity' => $existingCapacities[$institutionId] ?? null,
                    ]],
                )->all());
            }
        }

        return $space->fresh(['institutions']) ?? $space;
    }

    /**
     * @return list<string>
     */
    private function normalizeInstitutionIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $institutionIds = [];

        foreach ($value as $institutionId) {
            if (! is_scalar($institutionId)) {
                continue;
            }

            $normalized = trim((string) $institutionId);

            if ($normalized !== '') {
                $institutionIds[] = $normalized;
            }
        }

        return array_values(array_unique($institutionIds));
    }

    /**
     * @return array<string, int|null>
     */
    private function normalizeInstitutionSpaceOverrides(mixed $value): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'institution_space_overrides' => __('The institution space overrides must be an array.'),
            ]);
        }

        $overrides = [];

        foreach ($value as $entry) {
            if (! is_array($entry) || ! is_scalar($entry['institution_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'institution_space_overrides' => __('Each institution space override requires an institution ID.'),
                ]);
            }

            $institutionId = trim((string) $entry['institution_id']);
            $capacity = $this->normalizeCapacity($entry['capacity'] ?? null);

            if ($institutionId === '') {
                throw ValidationException::withMessages([
                    'institution_space_overrides' => __('Each institution space override requires an institution ID.'),
                ]);
            }

            $overrides[$institutionId] = $capacity;
        }

        return $overrides;
    }

    private function normalizeCapacity(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw ValidationException::withMessages([
                'capacity' => __('The capacity must be an integer.'),
            ]);
        }

        $capacity = (int) $value;

        if ($capacity < 1) {
            throw ValidationException::withMessages([
                'capacity' => __('The capacity must be at least 1.'),
            ]);
        }

        return $capacity;
    }

    private function validateSpaceType(?string $spaceType): void
    {
        if (
            $spaceType !== null
            && ! VenueSpaceType::query()
                ->where('code', $spaceType)
                ->where('is_active', true)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'space_type' => __('The selected space type is invalid.'),
            ]);
        }
    }

    private function ensureUniqueSlug(Space $space, string $slug): void
    {
        $query = Space::query()
            ->where('slug', $slug)
            ->where(function (Builder $scope) use ($space): void {
                if ($space->venue_id === null) {
                    $scope->whereNull('venue_id');

                    return;
                }

                $scope->where('venue_id', $space->venue_id);
            });

        if ($space->exists) {
            $query->whereKeyNot($space->getKey());
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'slug' => __('The slug has already been taken.'),
            ]);
        }
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = is_scalar($value) ? trim((string) $value) : '';

        if (! in_array($status, ['active', 'inactive'], true)) {
            throw ValidationException::withMessages([
                'status' => __('The selected status is invalid.'),
            ]);
        }

        return $status;
    }

    private function normalizeVisibility(mixed $value): string
    {
        $visibility = is_scalar($value) ? trim((string) $value) : '';

        if (! in_array($visibility, ['public', 'unlisted', 'private'], true)) {
            throw ValidationException::withMessages([
                'visibility' => __('The selected visibility is invalid.'),
            ]);
        }

        return $visibility;
    }

    private function normalizeRequiredString(mixed $value, string $field): string
    {
        $normalized = $this->normalizeOptionalString($value);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => __('This field is required.'),
            ]);
        }

        return $normalized;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
