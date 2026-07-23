<?php

namespace App\Actions\Institutions;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\State;
use App\Actions\Slugs\Concerns\BuildsUniqueSlug;
use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\Concerns\ResolvesLocationSuffix;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Models\Institution;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateInstitutionSlugAction
{
    use AsAction;
    use BuildsUniqueSlug;
    use InteractsWithOrderedSlugModels;
    use ResolvesLocationSuffix;

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

        return $this->buildUniqueSlug(
            Institution::class,
            $nameSlug,
            [],
            $locationSuffix,
            $ignoreInstitutionId,
        );
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
            $this->districtNameFromSubdistrict($address['admin_area_2_id'] ?? null),
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

    private function districtNameFromSubdistrict(mixed $subdistrictId): ?string
    {
        $subdistrictId = $this->uuidValue($subdistrictId);

        if ($subdistrictId === null) {
            return null;
        }

        $subdistrict = AddressArea::query()->find($subdistrictId);

        if (! $subdistrict instanceof AddressArea || ! is_string($subdistrict->parent_id) || $subdistrict->parent_id === '') {
            return null;
        }

        return $this->areaName($subdistrict->parent_id);
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
}
