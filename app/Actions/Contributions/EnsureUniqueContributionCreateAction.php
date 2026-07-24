<?php

namespace App\Actions\Contributions;

use App\Enums\ContributionSubjectType;
use App\Forms\SharedFormSchema;
use App\Models\Institution;
use App\Models\Person;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class EnsureUniqueContributionCreateAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $state
     */
    public function handle(ContributionSubjectType $subjectType, array $state, string $validationKeyPrefix = ''): void
    {
        match ($subjectType) {
            ContributionSubjectType::Institution => $this->ensureUniqueInstitution($state, $validationKeyPrefix),
            ContributionSubjectType::Person => $this->ensureUniqueSpeaker($state, $validationKeyPrefix),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function ensureUniqueInstitution(array $state, string $validationKeyPrefix): void
    {
        if (! $this->institutionDuplicateExists($state)) {
            return;
        }

        throw ValidationException::withMessages([
            $this->validationKey('name', $validationKeyPrefix) => __(
                'An institution with the same name and locality already exists in the same country. Please submit an update instead of creating a new record.'
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function ensureUniqueSpeaker(array $state, string $validationKeyPrefix): void
    {
        if (! $this->speakerDuplicateExists($state)) {
            return;
        }

        throw ValidationException::withMessages([
            $this->validationKey('name', $validationKeyPrefix) => __(
                'A speaker with the same name, gender, title set, and country already exists. Please submit an update instead of creating a new record.'
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function institutionDuplicateExists(array $state): bool
    {
        $name = $this->normalizeComparableString($state['name'] ?? null);

        if ($name === null) {
            return false;
        }

        $address = is_array($state['address'] ?? null)
            ? SharedFormSchema::prepareAddressPersistenceData($state['address'])
            : [];

        $countryId = $this->normalizeNullableUuid($address['country_id'] ?? null);
        $stateId = $this->normalizeNullableUuid($address['state_id'] ?? null);
        $cityId = $this->normalizeNullableUuid($address['city_id'] ?? null);
        $adminArea1Id = $this->normalizeNullableUuid($address['admin_area_1_id'] ?? null);
        $adminArea2Id = $this->normalizeNullableUuid($address['admin_area_2_id'] ?? null);

        return Institution::query()
            ->whereIn('status', ['verified', 'pending'])
            ->whereHas('addresses', function (Builder $query) use ($countryId, $stateId, $cityId, $adminArea1Id, $adminArea2Id): void {
                $query->where('country_id', $countryId);

                foreach ([
                    'state_id' => $stateId,
                    'city_id' => $cityId,
                    'admin_area_1_id' => $adminArea1Id,
                    'admin_area_2_id' => $adminArea2Id,
                ] as $column => $value) {
                    if ($value !== null) {
                        $query->where($column, $value);
                    }
                }
            })
            ->get(['id', 'name'])
            ->contains(fn (Institution $institution): bool => $this->normalizeComparableString($institution->name) === $name);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function speakerDuplicateExists(array $state): bool
    {
        $name = $this->normalizeComparableString($state['name'] ?? null);
        $gender = $this->normalizeComparableString($state['gender'] ?? null);

        if ($name === null || $gender === null) {
            return false;
        }

        $address = is_array($state['address'] ?? null)
            ? SharedFormSchema::prepareAddressPersistenceData($state['address'])
            : [];
        $countryId = $this->normalizeNullableUuid($address['country_id'] ?? null);

        if ($countryId === null) {
            return false;
        }

        return Person::query()
            ->whereIn('status', ['verified', 'pending'])
            ->where('gender', $gender)
            ->whereHas('addresses', fn (Builder $query): Builder => $query->where('country_id', $countryId))
            ->get(['name', 'gender'])
            ->contains(fn (Person $speaker): bool => $this->normalizeComparableString($speaker->name) === $name
                && $this->normalizeComparableString($speaker->gender) === $gender);
    }

    private function normalizeComparableString(mixed $value): ?string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        if (! is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return $normalized;
    }

    private function normalizeNullableUuid(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return Str::isUuid($normalized) ? $normalized : null;
    }

    private function validationKey(string $key, string $validationKeyPrefix): string
    {
        $prefix = trim($validationKeyPrefix);

        if ($prefix === '') {
            return $key;
        }

        return rtrim($prefix, '.').'.'.$key;
    }
}
