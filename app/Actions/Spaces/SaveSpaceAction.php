<?php

declare(strict_types=1);

namespace App\Actions\Spaces;

use App\Models\Space;
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

        $space->fill([
            'name' => $this->normalizeRequiredString($data['name'] ?? $space->name, 'name'),
            'slug' => $this->normalizeRequiredString($data['slug'] ?? $space->slug, 'slug'),
            'capacity' => array_key_exists('capacity', $data)
                ? $this->normalizeCapacity($data['capacity'])
                : $space->capacity,
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : ($creating ? true : (bool) $space->is_active),
        ]);

        $this->ensureUniqueSlug($space, (string) $space->slug);
        $space->save();

        if (array_key_exists('institutions', $data)) {
            $space->auditSync(
                'institutions',
                $this->normalizeInstitutionIds($data['institutions']),
                true,
                ['institutions.id', 'institutions.name'],
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

    private function ensureUniqueSlug(Space $space, string $slug): void
    {
        $query = Space::query()->where('slug', $slug);

        if ($space->exists) {
            $query->whereKeyNot($space->getKey());
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'slug' => __('The slug has already been taken.'),
            ]);
        }
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
