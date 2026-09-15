<?php

namespace App\Actions\Institutions;

use AIArmada\Addressing\Support\LocationSlugSegments;
use AIArmada\CommerceSupport\Support\CanonicalSlug;
use AIArmada\CommerceSupport\Support\StableModelOrder;
use AIArmada\CommerceSupport\Support\UniqueSlug;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Models\Institution;
use App\Support\Location\AddressAssignments;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateInstitutionSlugAction
{
    use AsAction;

    public function __construct(
        private readonly SyncSlugRedirectAction $syncSlugRedirectAction,
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

        return StableModelOrder::sync($institutions, fn (Institution $institution): bool => $this->syncInstitutionSlug($institution));
    }

    public function syncInstitutionSlug(Institution $institution): bool
    {
        $slug = $this->forInstitution($institution);

        return CanonicalSlug::persist($institution, $slug, $this->syncSlugRedirectAction);
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

        return UniqueSlug::build(
            Institution::class,
            $nameSlug,
            [],
            $locationSuffix,
            $ignoreInstitutionId,
        );
    }

    public function forInstitution(Institution $institution): string
    {
        $institution->unsetRelation('addresses');
        $institution->load(['addresses']);

        $address = $institution->primaryAddress();

        return $this->handle(
            $institution->name,
            [
                'country_id' => $address?->country_id,
                'city' => $address?->city,
                'state_id' => $address?->state_id,
                'area_assignments' => AddressAssignments::forAddress($address),
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
        $assignments = (array) ($address['area_assignments'] ?? []);
        $city = $this->firstFilled([
            $address['city'] ?? null,
            LocationSlugSegments::cityName($address['city_id'] ?? null),
            LocationSlugSegments::areaName($assignments[AddressAssignments::ADMINISTRATIVE_SUBDIVISION] ?? null),
        ]);
        $district = $this->firstFilled([
            LocationSlugSegments::areaName($assignments[AddressAssignments::ADMINISTRATIVE_DISTRICT] ?? null),
        ]);
        $state = $this->firstFilled([
            $address['state'] ?? null,
            LocationSlugSegments::stateName($address['state_id'] ?? null),
        ]);
        $countryCode = LocationSlugSegments::countryCode($address);
        $segments = [];

        foreach ([
            $this->slugSegment($city),
            $this->slugSegment($district),
            $this->slugSegment($state),
            $this->codeSegment($countryCode),
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

    private function slugSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $segment = Str::slug($value);

        return $segment !== '' ? $segment : null;
    }

    private function codeSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $segment = Str::lower(trim($value));

        return $segment !== '' ? $segment : null;
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
}
