<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Spaces;

use AIArmada\Events\Models\VenueSpaceType;
use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a space through the owning Directory feature.
 */
final readonly class SaveSpaceAction
{
    public function __construct(private SyncInstitutionSpacesAction $syncInstitutionSpaces) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Model $space = null): Model
    {
        $creating = ! $space instanceof Model;
        $space ??= DirectoryModel::new('space');
        $space = DirectoryModel::assert($space, 'space');

        if (array_key_exists('venue_id', $data)) {
            $venueId = $this->normalizeOptionalString($data['venue_id']);

            if ($venueId === null && ! $creating && $space->getAttribute('venue_id') !== null) {
                throw ValidationException::withMessages([
                    'venue_id' => __('Venue-owned spaces cannot be converted into catalog spaces.'),
                ]);
            }

            $space->setAttribute('venue_id', $venueId);
        }

        if (
            $space->getAttribute('venue_id') !== null
            && (array_key_exists('institutions', $data) || array_key_exists('institution_space_overrides', $data))
        ) {
            throw ValidationException::withMessages([
                'institutions' => __('Venue-owned spaces cannot be linked to institutions.'),
            ]);
        }

        $space->fill([
            'name' => $this->normalizeRequiredString($data['name'] ?? $space->getAttribute('name'), 'name'),
            'slug' => $this->normalizeRequiredString($data['slug'] ?? $space->getAttribute('slug'), 'slug'),
            'code' => array_key_exists('code', $data) ? $this->normalizeOptionalString($data['code']) : $space->getAttribute('code'),
            'space_type' => array_key_exists('space_type', $data) ? $this->normalizeOptionalString($data['space_type']) : $space->getAttribute('space_type'),
            'level' => array_key_exists('level', $data) ? $this->normalizeOptionalString($data['level']) : $space->getAttribute('level'),
            'unit_no' => array_key_exists('unit_no', $data) ? $this->normalizeOptionalString($data['unit_no']) : $space->getAttribute('unit_no'),
            'block' => array_key_exists('block', $data) ? $this->normalizeOptionalString($data['block']) : $space->getAttribute('block'),
            'wing' => array_key_exists('wing', $data) ? $this->normalizeOptionalString($data['wing']) : $space->getAttribute('wing'),
            'capacity' => array_key_exists('capacity', $data)
                ? $this->normalizeCapacity($data['capacity'])
                : $space->getAttribute('capacity'),
            'status' => array_key_exists('status', $data)
                ? $this->normalizeStatus($data['status'])
                : ($creating ? 'active' : (string) $space->getAttribute('status')),
            'visibility' => array_key_exists('visibility', $data)
                ? $this->normalizeVisibility($data['visibility'])
                : ($creating ? 'public' : (string) ($space->getAttribute('visibility') ?? 'public')),
        ]);

        $this->validateSpaceType($space->getAttribute('space_type'));
        $this->ensureUniqueSlug($space, (string) $space->getAttribute('slug'));
        $space->save();

        $hasInstitutionIds = array_key_exists('institutions', $data);
        $hasInstitutionSpaceOverrideField = array_key_exists('institution_space_overrides', $data);

        if ($hasInstitutionIds || $hasInstitutionSpaceOverrideField) {
            $existingCapacities = $space->institutions()
                ->get()
                ->mapWithKeys(fn (Model $institution): array => [
                    (string) $institution->getKey() => ($pivot = $institution->getRelationValue('pivot')) instanceof Pivot
                        ? $pivot->getAttribute('capacity')
                        : null,
                ])
                ->all();
            $overrides = $hasInstitutionSpaceOverrideField
                ? $this->normalizeInstitutionSpaceOverrides($data['institution_space_overrides'])
                : [];
            $preserveExistingCapacities = ! $hasInstitutionIds && $overrides !== [];
            $institutionIds = $hasInstitutionIds
                ? $this->normalizeInstitutionIds($data['institutions'])
                : array_keys($existingCapacities);

            $institutionIds = array_values(array_unique([
                ...$institutionIds,
                ...array_keys($overrides),
            ]));

            $links = $hasInstitutionSpaceOverrideField
                ? collect($institutionIds)->mapWithKeys(
                    fn (string $institutionId): array => [$institutionId => [
                        'capacity' => array_key_exists($institutionId, $overrides)
                            ? $overrides[$institutionId]
                            : ($preserveExistingCapacities ? ($existingCapacities[$institutionId] ?? null) : null),
                    ]],
                )->all()
                : collect($institutionIds)->mapWithKeys(
                    fn (string $institutionId): array => [$institutionId => [
                        'capacity' => $existingCapacities[$institutionId] ?? null,
                    ]],
                )->all();

            $this->syncInstitutionSpaces->handle(
                $space,
                array_map(
                    static fn (array $link): ?int => $link['capacity'],
                    $links,
                ),
            );
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

    private function validateSpaceType(mixed $spaceType): void
    {
        if (! is_string($spaceType) || $spaceType === '') {
            return;
        }

        if (
            ! VenueSpaceType::query()
                ->where('code', $spaceType)
                ->where('is_active', true)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'space_type' => __('The selected space type is invalid.'),
            ]);
        }
    }

    private function ensureUniqueSlug(Model $space, string $slug): void
    {
        $query = DirectoryModel::query('space')
            ->where('slug', $slug)
            ->where(function (Builder $scope) use ($space): void {
                $venueId = $space->getAttribute('venue_id');

                if ($venueId === null) {
                    $scope->whereNull('venue_id');

                    return;
                }

                $scope->where('venue_id', $venueId);
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
