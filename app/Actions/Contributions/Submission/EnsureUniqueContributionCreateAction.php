<?php

namespace App\Actions\Contributions\Submission;

use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Forms\SharedFormSchema;
use App\Models\ContributionRequest;
use App\Models\Institution;
use App\Models\Person;
use App\Platform\Location\AddressAssignments;
use App\Platform\Location\GooglePlaceIdentity;
use App\Shared\Directory\Enums\InstitutionStatus;
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
        if ($this->institutionDuplicateExists($state)) {
            throw ValidationException::withMessages([
                $this->validationKey('name', $validationKeyPrefix) => __(
                    'An institution with the same Google Maps place or name and locality already exists. Please submit an update instead of creating a new record.'
                ),
            ]);
        }

        if ($this->pendingInstitutionClaimExists($state)) {
            throw ValidationException::withMessages([
                $this->validationKey('name', $validationKeyPrefix) => __(
                    'This institution was already submitted and is awaiting review. Please wait for the review instead of creating a new record.'
                ),
            ]);
        }
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
    public function institutionDuplicateExists(array $state, ?string $excludeInstitutionId = null, bool $preNormalizedAddress = false): bool
    {
        $address = is_array($state['address'] ?? null)
            ? ($preNormalizedAddress ? $state['address'] : SharedFormSchema::prepareAddressPersistenceData($state['address']))
            : [];

        $placeId = $this->normalizeIdentityString($address['google_place_id'] ?? null);
        $cid = GooglePlaceIdentity::canonicalCid($address['google_cid'] ?? null)
            ?? GooglePlaceIdentity::cidFromFeatureId($address['google_feature_id'] ?? null)
            ?? $this->cidFromGoogleMapsUrl($address['google_maps_url'] ?? null);

        if ($this->institutionMapsDuplicateExists($cid, $placeId, $excludeInstitutionId)) {
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
            ->when($excludeInstitutionId !== null, fn (Builder $query) => $query->whereKeyNot($excludeInstitutionId))
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

    /**
     * Check whether another institution already claims a Google Maps identity,
     * so updates cannot move an institution onto an existing place.
     */
    public function institutionMapsIdentityTakenByOther(?string $cid, ?string $placeId, string $excludeInstitutionId): bool
    {
        return $this->institutionMapsDuplicateExists($cid, $placeId, $excludeInstitutionId)
            || $this->pendingMapsClaimExists($cid, $placeId, $excludeInstitutionId);
    }

    /**
     * Check whether a pending create or update request already claims the
     * submitted Maps identity or name and locality. Staged creates persist a
     * pending institution row, so those are already covered by the record
     * check above; this covers pending updates that move an institution onto
     * a new identity plus any request whose entity row is missing.
     *
     * @param  array<string, mixed>  $state
     */
    public function pendingInstitutionClaimExists(array $state, ?string $excludeRequestId = null, bool $preNormalizedAddress = false): bool
    {
        $address = is_array($state['address'] ?? null)
            ? ($preNormalizedAddress ? $state['address'] : SharedFormSchema::prepareAddressPersistenceData($state['address']))
            : [];

        $placeId = $this->normalizeIdentityString($address['google_place_id'] ?? null);
        $cid = GooglePlaceIdentity::canonicalCid($address['google_cid'] ?? null)
            ?? GooglePlaceIdentity::cidFromFeatureId($address['google_feature_id'] ?? null)
            ?? $this->cidFromGoogleMapsUrl($address['google_maps_url'] ?? null);

        if ($this->pendingMapsClaimExists($cid, $placeId, null, $excludeRequestId)) {
            return true;
        }

        $name = $this->normalizeComparableString($state['name'] ?? null);

        if ($name === null) {
            return false;
        }

        return $this->pendingNameClaimExists(
            $name,
            $this->normalizeNullableUuid($address['country_id'] ?? null),
            $this->normalizeNullableUuid($address['state_id'] ?? null),
            $this->normalizeNullableUuid($address['city_id'] ?? null),
            AddressAssignments::normalize((array) ($address['area_assignments'] ?? [])),
            $excludeRequestId,
        );
    }

    private function pendingMapsClaimExists(?string $cid, ?string $placeId, ?string $excludeInstitutionId = null, ?string $excludeRequestId = null): bool
    {
        if ($cid === null && $placeId === null) {
            return false;
        }

        foreach ($this->pendingInstitutionClaims($excludeInstitutionId, $excludeRequestId) as $claim) {
            $address = $this->pendingClaimAddress($claim);
            $claimPlaceId = $this->normalizeIdentityString($address['google_place_id'] ?? null);

            if ($placeId !== null && $claimPlaceId === $placeId) {
                return true;
            }

            if ($cid !== null && in_array($cid, $this->claimCidSet($address), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $assignments
     */
    private function pendingNameClaimExists(
        string $name,
        ?string $countryId,
        ?string $stateId,
        ?string $cityId,
        array $assignments,
        ?string $excludeRequestId = null,
    ): bool {
        $claims = $this->pendingInstitutionClaims(null, $excludeRequestId);
        $targetLocalities = $this->pendingClaimTargetLocalities($claims);

        foreach ($claims as $claim) {
            $proposed = is_array($claim->proposed_data) ? $claim->proposed_data : [];

            if ($this->normalizeComparableString($proposed['name'] ?? null) !== $name) {
                continue;
            }

            foreach ($this->pendingClaimLocalities($claim, $targetLocalities) as $locality) {
                if ($this->pendingLocalityMatches($locality, $countryId, $stateId, $cityId, $assignments)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The moderation queue is small (at most one pending request per entity
     * for updates), so exact matching runs in PHP over a narrow column set
     * instead of JSON queries that cannot canonicalize Maps identities.
     *
     * @return list<ContributionRequest>
     */
    private function pendingInstitutionClaims(?string $excludeInstitutionId = null, ?string $excludeRequestId = null): array
    {
        return ContributionRequest::query()
            ->select(['id', 'type', 'entity_id', 'proposed_data'])
            ->where('subject_type', ContributionSubjectType::Institution->value)
            ->whereIn('type', [ContributionRequestType::Create->value, ContributionRequestType::Update->value])
            ->where('status', ContributionRequestStatus::Pending->value)
            ->when($excludeInstitutionId !== null, fn (Builder $query) => $query->where(
                fn (Builder $nested): Builder => $nested->whereNull('entity_id')->orWhere('entity_id', '!=', $excludeInstitutionId)
            ))
            ->when($excludeRequestId !== null, fn (Builder $query) => $query->whereKeyNot($excludeRequestId))
            ->get()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingClaimAddress(ContributionRequest $claim): array
    {
        $proposed = is_array($claim->proposed_data) ? $claim->proposed_data : [];
        $rawAddress = $proposed['address'] ?? null;

        if (! is_array($rawAddress) || $rawAddress === []) {
            return [];
        }

        return $this->normalizeClaimAddress($rawAddress);
    }

    /**
     * Read a stored claim's address with pure array operations. Claims are
     * already normalized at submission, so re-running persistence
     * normalization here would be wrong: it throws on stale geography and
     * can trigger remote Maps lookups for every claim on every check.
     *
     * @param  array<string, mixed>  $rawAddress
     * @return array<string, mixed>
     */
    public static function normalizeClaimAddress(array $rawAddress): array
    {
        return [
            'country_id' => self::normalizeNullableUuid($rawAddress['country_id'] ?? null),
            'state_id' => self::normalizeNullableUuid($rawAddress['state_id'] ?? null),
            'city_id' => self::normalizeNullableUuid($rawAddress['city_id'] ?? null),
            'area_assignments' => AddressAssignments::normalize((array) ($rawAddress['area_assignments'] ?? [])),
            'google_maps_url' => is_string($rawAddress['google_maps_url'] ?? null) ? $rawAddress['google_maps_url'] : null,
            'google_place_id' => is_string($rawAddress['google_place_id'] ?? null) ? $rawAddress['google_place_id'] : null,
            'google_feature_id' => is_string($rawAddress['google_feature_id'] ?? null) ? $rawAddress['google_feature_id'] : null,
            'google_cid' => is_string($rawAddress['google_cid'] ?? null) || is_int($rawAddress['google_cid'] ?? null) ? $rawAddress['google_cid'] : null,
            'google_entity_id' => is_string($rawAddress['google_entity_id'] ?? null) ? $rawAddress['google_entity_id'] : null,
        ];
    }

    /**
     * Every canonical cid a stored address speaks for: the direct cid, the
     * feature-id derivation, and all cids parsed from its Maps URL. This
     * mirrors the record check, which matches any parsed URL cid.
     *
     * @param  array<string, mixed>  $address
     * @return list<string>
     */
    private function claimCidSet(array $address): array
    {
        $cids = [];
        $directCid = GooglePlaceIdentity::canonicalCid($address['google_cid'] ?? null);

        if ($directCid !== null) {
            $cids[] = $directCid;
        }

        $featureCid = is_string($address['google_feature_id'] ?? null)
            ? GooglePlaceIdentity::cidFromFeatureId($address['google_feature_id'])
            : null;

        if ($featureCid !== null) {
            $cids[] = $featureCid;
        }

        if (is_string($address['google_maps_url'] ?? null)) {
            foreach (GooglePlaceIdentity::cidsFromGoogleMapsUrl($address['google_maps_url']) as $urlCid) {
                $cids[] = $urlCid;
            }
        }

        return array_values(array_unique($cids));
    }

    /**
     * Localities a pending claim speaks for: its proposed address when the
     * request touches the address, otherwise the target institution's current
     * addresses for name-only updates.
     *
     * @param  array<string, list<array{country_id: ?string, state_id: ?string, city_id: ?string, assignments: array<string, string>}>>  $targetLocalities
     * @return list<array{country_id: ?string, state_id: ?string, city_id: ?string, assignments: array<string, string>}>
     */
    private function pendingClaimLocalities(ContributionRequest $claim, array $targetLocalities): array
    {
        $proposed = is_array($claim->proposed_data) ? $claim->proposed_data : [];
        $rawAddress = $proposed['address'] ?? null;

        if (is_array($rawAddress) && $rawAddress !== []) {
            $address = $this->normalizeClaimAddress($rawAddress);

            return [[
                'country_id' => $address['country_id'],
                'state_id' => $address['state_id'],
                'city_id' => $address['city_id'],
                'assignments' => $address['area_assignments'],
            ]];
        }

        if (is_string($claim->entity_id) && $claim->entity_id !== '') {
            return $targetLocalities[$claim->entity_id] ?? [];
        }

        return [];
    }

    /**
     * @param  list<ContributionRequest>  $claims
     * @return array<string, list<array{country_id: ?string, state_id: ?string, city_id: ?string, assignments: array<string, string>}>>
     */
    private function pendingClaimTargetLocalities(array $claims): array
    {
        $entityIds = [];

        foreach ($claims as $claim) {
            $proposed = is_array($claim->proposed_data) ? $claim->proposed_data : [];
            $rawAddress = $proposed['address'] ?? null;

            if ($claim->type === ContributionRequestType::Update
                && is_string($claim->entity_id)
                && $claim->entity_id !== ''
                && (! is_array($rawAddress) || $rawAddress === [])
            ) {
                $entityIds[] = $claim->entity_id;
            }
        }

        $entityIds = array_values(array_unique($entityIds));

        if ($entityIds === []) {
            return [];
        }

        $localities = [];
        $institutions = Institution::query()->whereKey($entityIds)->with('addresses.areaAssignments')->get();

        foreach ($institutions as $institution) {
            foreach ($institution->addresses as $address) {
                $localities[(string) $institution->getKey()][] = [
                    'country_id' => $this->normalizeNullableUuid($address->getAttribute('country_id')),
                    'state_id' => $this->normalizeNullableUuid($address->getAttribute('state_id')),
                    'city_id' => $this->normalizeNullableUuid($address->getAttribute('city_id')),
                    'assignments' => AddressAssignments::normalize(
                        $address->areaAssignments
                            ->where('is_primary', true)
                            ->mapWithKeys(fn ($assignment): array => [(string) $assignment->getAttribute('role') => (string) $assignment->getAttribute('address_area_id')])
                            ->all(),
                    ),
                ];
            }
        }

        return $localities;
    }

    /**
     * Mirror the record name query: country always compares (null included),
     * while state, city, and area assignments only constrain when the
     * incoming submission provides them.
     *
     * @param  array{country_id: ?string, state_id: ?string, city_id: ?string, assignments: array<string, string>}  $locality
     * @param  array<string, string>  $assignments
     */
    private function pendingLocalityMatches(
        array $locality,
        ?string $countryId,
        ?string $stateId,
        ?string $cityId,
        array $assignments,
    ): bool {
        if ($locality['country_id'] !== $countryId) {
            return false;
        }

        foreach (['state_id' => $stateId, 'city_id' => $cityId] as $column => $value) {
            if ($value !== null && $locality[$column] !== $value) {
                return false;
            }
        }

        foreach ($assignments as $role => $areaId) {
            if (($locality['assignments'][$role] ?? null) !== $areaId) {
                return false;
            }
        }

        return true;
    }

    private function institutionMapsDuplicateExists(?string $cid, ?string $placeId, ?string $excludeInstitutionId = null): bool
    {
        if ($cid === null && $placeId === null) {
            return false;
        }

        $featureCid = $cid !== null ? GooglePlaceIdentity::hexCidFromDecimal($cid) : null;

        $candidateInstitutions = Institution::query()
            ->whereIn('status', InstitutionStatus::publiclyVisibleValues())
            ->when($excludeInstitutionId !== null, fn (Builder $query) => $query->whereKeyNot($excludeInstitutionId))
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

    private static function normalizeNullableUuid(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return Str::isUuid($normalized) ? Str::lower($normalized) : null;
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
