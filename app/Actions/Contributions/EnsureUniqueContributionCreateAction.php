<?php

namespace App\Actions\Contributions;

use App\Enums\ContributionSubjectType;
use App\Enums\InstitutionStatus;
use App\Forms\SharedFormSchema;
use App\Models\Institution;
use App\Models\Person;
use App\Support\Location\AddressAssignments;
use App\Support\Location\GooglePlaceIdentity;
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
            ContributionSubjectType::Person => $this->ensureUniquePerson($state, $validationKeyPrefix),
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
                'An institution with the same Google Maps place or name and locality already exists. Please submit an update instead of creating a new record.'
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function ensureUniquePerson(array $state, string $validationKeyPrefix): void
    {
        if (! $this->personDuplicateExists($state)) {
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
        $address = is_array($state['address'] ?? null)
            ? SharedFormSchema::prepareAddressPersistenceData($state['address'])
            : [];

        $placeId = $this->normalizeIdentityString($address['google_place_id'] ?? null);
        $cid = GooglePlaceIdentity::canonicalCid($address['google_cid'] ?? null)
            ?? GooglePlaceIdentity::cidFromFeatureId($address['google_feature_id'] ?? null)
            ?? $this->cidFromGoogleMapsUrl($address['google_maps_url'] ?? null);

        if ($this->institutionMapsDuplicateExists($cid, $placeId)) {
            return true;
        }

        $name = $this->normalizeComparableString($state['name'] ?? null);

        if ($name === null) {
            return false;
        }

        $countryId = $this->normalizeNullableUuid($address['country_id'] ?? null);
        $stateId = $this->normalizeNullableUuid($address['state_id'] ?? null);
        $cityId = $this->normalizeNullableUuid($address['city_id'] ?? null);
        $assignments = AddressAssignments::normalize((array) ($address['area_assignments'] ?? []));

        return Institution::query()
            ->whereIn('status', InstitutionStatus::publiclyVisibleValues())
            ->whereHas('addresses', function (Builder $query) use ($countryId, $stateId, $cityId, $assignments): void {
                $query->where('country_id', $countryId);

                foreach ([
                    'state_id' => $stateId,
                    'city_id' => $cityId,
                ] as $column => $value) {
                    if ($value !== null) {
                        $query->where($column, $value);
                    }
                }

                foreach ($assignments as $role => $areaId) {
                    $query->whereHas('areaAssignments', static function (Builder $assignmentQuery) use ($role, $areaId): void {
                        $assignmentQuery->where('role', $role)->where('address_area_id', $areaId)->where('is_primary', true);
                    });
                }
            })
            ->get(['id', 'name'])
            ->contains(fn (Institution $institution): bool => $this->normalizeComparableString($institution->name) === $name);
    }

    private function institutionMapsDuplicateExists(?string $cid, ?string $placeId): bool
    {
        if ($cid === null && $placeId === null) {
            return false;
        }

        $featureCid = $cid !== null ? GooglePlaceIdentity::hexCidFromDecimal($cid) : null;

        $candidateInstitutions = Institution::query()
            ->whereIn('status', InstitutionStatus::publiclyVisibleValues())
            ->whereHas('addresses', function (Builder $query) use ($cid, $placeId, $featureCid): void {
                $query->where(function (Builder $identityQuery) use ($cid, $placeId, $featureCid): void {
                    if ($cid !== null) {
                        $identityQuery->where('google_cid', $cid)
                            ->orWhere('google_maps_url', 'like', '%cid='.$cid.'%')
                            ->orWhere('google_maps_url', 'like', '%ludocid='.$cid.'%');

                        if ($featureCid !== null) {
                            $identityQuery->orWhereRaw('LOWER(google_feature_id) LIKE ?', ['%:0x%'.$featureCid])
                                ->orWhereRaw('LOWER(google_maps_url) LIKE ?', ['%:0x%'.$featureCid.'%']);
                        }
                    }

                    if ($placeId !== null) {
                        $identityQuery->orWhere('google_place_id', $placeId);
                    }
                });
            })
            ->with(['addresses' => static fn ($query) => $query->select([
                'addresses.id',
                'google_cid',
                'google_place_id',
                'google_feature_id',
                'google_maps_url',
            ])])
            ->get();

        foreach ($candidateInstitutions as $institution) {
            foreach ($institution->addresses as $address) {
                if ($placeId !== null && $address->google_place_id === $placeId) {
                    return true;
                }

                if ($cid !== null && GooglePlaceIdentity::canonicalCid($address->google_cid) === $cid) {
                    return true;
                }

                if ($cid !== null && GooglePlaceIdentity::cidFromFeatureId($address->google_feature_id) === $cid) {
                    return true;
                }

                if ($cid !== null
                    && is_string($address->google_maps_url)
                    && in_array($cid, GooglePlaceIdentity::cidsFromGoogleMapsUrl($address->google_maps_url), true)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function cidFromGoogleMapsUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return GooglePlaceIdentity::cidsFromGoogleMapsUrl($value)[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function personDuplicateExists(array $state): bool
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
            ->contains(fn (Person $person): bool => $this->normalizeComparableString($person->name) === $name
                && $this->normalizeComparableString($person->gender) === $gender);
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

    private function normalizeIdentityString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
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
