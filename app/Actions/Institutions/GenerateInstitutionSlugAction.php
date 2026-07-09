<?php

namespace App\Actions\Institutions;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\State;
use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Models\Institution;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateInstitutionSlugAction
{
    use AsAction;
    use InteractsWithOrderedSlugModels;

    public function __construct(
        private readonly SyncCanonicalSlugAction $syncCanonicalSlugAction,
    ) {}

    public function syncInstitutionSlugsForName(string $name): bool
    {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            return false;
        }

        $institutions = Institution::query()
            ->where('institutions.name', $normalizedName)
            ->with(['addresses'])
            ->get();

        return $this->syncOrderedModels($institutions, fn (Institution $institution): bool => $this->syncInstitutionSlug($institution));
    }

    public function syncInstitutionSlug(Institution $institution): bool
    {
        $slug = $this->forInstitution($institution);

        return $this->syncCanonicalSlugAction->persist($institution, $slug);
    }

    /**
     * @param  array<string, mixed>  $address
     */
    public function handle(string $name, array $address = [], ?string $ignoreInstitutionId = null): string
    {
        $normalizedName = trim($name);
        $nameSlug = Str::slug($normalizedName);

        if ($nameSlug === '') {
            $nameSlug = 'institution';
        }

        $locationSuffix = $this->locationSuffix($address);
        $sequence = $this->nextSequenceForExactName(
            $normalizedName,
            $locationSuffix,
            $ignoreInstitutionId,
        );

        // Start numbering from exact-name duplicates in the same locality, then
        // still guard the final slug in case a literal numeric name already
        // occupies the expected slot (for example "Masjid Example 2").
        do {
            $candidateParts = [$nameSlug];

            if ($sequence > 1) {
                $candidateParts[] = (string) $sequence;
            }

            if ($locationSuffix !== '') {
                $candidateParts[] = $locationSuffix;
            }

            $candidate = implode('-', $candidateParts);
            $sequence++;
        } while ($this->slugExists($candidate, $ignoreInstitutionId));

        return $candidate;
    }

    public function forInstitution(Institution $institution): string
    {
        $institution->loadMissing(['addresses']);

        $address = $institution->primaryAddress();

        return $this->handle(
            $institution->name,
            [
                'country_id' => $address?->country_id,
                'city' => $address?->city,
                'state_id' => $address?->state_id,
                'admin_area_1_id' => $address?->admin_area_1_id,
                'admin_area_2_id' => $address?->admin_area_2_id,
                'state' => $address?->state,
                'country_code' => $address?->country_code,
            ],
            (string) $institution->getKey(),
        );
    }

    private function nextSequenceForExactName(string $name, string $locationSuffix, ?string $ignoreInstitutionId): int
    {
        $matchingInstitutions = Institution::query()
            ->where('institutions.name', $name)
            ->with(['addresses'])
            ->get()
            ->filter(fn (Institution $institution): bool => $this->locationSuffixForInstitution($institution) === $locationSuffix);

        if ($ignoreInstitutionId !== null && $ignoreInstitutionId !== '') {
            $existingSequence = $this->existingModelSequence($matchingInstitutions, $ignoreInstitutionId);

            if ($existingSequence !== null) {
                return $existingSequence;
            }

            $matchingInstitutions = $matchingInstitutions
                ->reject(fn (Institution $institution): bool => (string) $institution->getKey() === $ignoreInstitutionId)
                ->values();
        }

        $matchingCount = $matchingInstitutions->count();

        return $matchingCount > 0 ? $matchingCount + 1 : 1;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function locationSuffix(array $address): string
    {
        // Product storage: admin_area_1 = district, admin_area_2 = subdistrict, state via state/state_id.
        $city = $this->firstFilled([
            $address['city'] ?? null,
            $address['admin_area_2_name'] ?? null,
            $this->areaName($address['admin_area_2_id'] ?? null),
        ]);
        $district = $this->firstFilled([
            $address['admin_area_1_name'] ?? null,
            $this->areaName($address['admin_area_1_id'] ?? null),
        ]);
        $state = $this->firstFilled([
            $address['state'] ?? null,
            $this->stateName($address['state_id'] ?? null),
            $this->stateNameFromDistrict($address['admin_area_1_id'] ?? null),
            $this->stateNameFromSubdistrict($address['admin_area_2_id'] ?? null),
        ]);
        $countryCode = $this->resolveCountryCode($address);
        $segments = [];

        foreach ([
            $this->slugSegment($city),
            $this->slugSegment($district),
            $this->slugSegment($state),
            $this->countryCodeSegment($countryCode),
        ] as $segment) {
            if ($segment === null) {
                continue;
            }

            if (($segments[array_key_last($segments)] ?? null) === $segment) {
                continue;
            }

            $segments[] = $segment;
        }

        return implode('-', $segments);
    }

    private function locationSuffixForInstitution(Institution $institution): string
    {
        $institution->loadMissing(['addresses']);

        $address = $institution->primaryAddress();

        return $this->locationSuffix([
            'country_id' => $address?->country_id,
            'city' => $address?->city,
            'state_id' => $address?->state_id,
            'admin_area_1_id' => $address?->admin_area_1_id,
            'admin_area_2_id' => $address?->admin_area_2_id,
            'state' => $address?->state,
            'country_code' => $address?->country_code,
        ]);
    }

    private function slugExists(string $slug, ?string $ignoreInstitutionId): bool
    {
        return Institution::query()
            ->where('slug', $slug)
            ->when(
                $ignoreInstitutionId !== null && $ignoreInstitutionId !== '',
                fn ($query) => $query->where('institutions.id', '!=', $ignoreInstitutionId),
            )
            ->exists();
    }

    private function slugSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $segment = Str::slug($value);

        return $segment !== '' ? $segment : null;
    }

    private function countryCodeSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $segment = Str::lower(trim($value));

        return $segment !== '' ? $segment : null;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function resolveCountryCode(array $address): ?string
    {
        $countryId = $this->uuidValue($address['country_id'] ?? null);

        if ($countryId !== null) {
            $resolved = AddressCountry::query()->whereKey($countryId)->value('iso2');

            if (is_string($resolved) && trim($resolved) !== '') {
                return trim($resolved);
            }
        }

        $countryCode = $address['country_code'] ?? null;

        if (is_string($countryCode) && trim($countryCode) !== '') {
            return trim($countryCode);
        }

        return null;
    }

    private function areaName(mixed $areaId): ?string
    {
        $areaId = $this->uuidValue($areaId);

        if ($areaId === null) {
            return null;
        }

        $resolved = AddressArea::query()->whereKey($areaId)->value('name');

        return is_string($resolved) && trim($resolved) !== '' ? $resolved : null;
    }

    private function stateName(mixed $stateId): ?string
    {
        $stateId = $this->uuidValue($stateId);

        if ($stateId === null) {
            return null;
        }

        $resolved = State::query()->whereKey($stateId)->value('name');

        return is_string($resolved) && trim($resolved) !== '' ? $resolved : null;
    }

    private function stateNameFromDistrict(mixed $districtId): ?string
    {
        $districtId = $this->uuidValue($districtId);

        if ($districtId === null) {
            return null;
        }

        $district = AddressArea::query()->find($districtId);

        if (! $district instanceof AddressArea || ! is_string($district->parent_id) || $district->parent_id === '') {
            return null;
        }

        return $this->areaName($district->parent_id);
    }

    private function stateNameFromSubdistrict(mixed $subdistrictId): ?string
    {
        $subdistrictId = $this->uuidValue($subdistrictId);

        if ($subdistrictId === null) {
            return null;
        }

        $subdistrict = AddressArea::query()->find($subdistrictId);

        if (! $subdistrict instanceof AddressArea || ! is_string($subdistrict->parent_id) || $subdistrict->parent_id === '') {
            return null;
        }

        $parent = AddressArea::query()->find($subdistrict->parent_id);

        if (! $parent instanceof AddressArea) {
            return null;
        }

        if ((int) $parent->level === 1) {
            return $this->firstFilled([$parent->name]);
        }

        if (is_string($parent->parent_id) && $parent->parent_id !== '') {
            return $this->areaName($parent->parent_id);
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private function uuidValue(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
