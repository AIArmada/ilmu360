<?php

namespace App\Actions\Institutions;

use AIArmada\Addressing\Models\State;
use App\Actions\Slugs\Concerns\BuildsUniqueSlug;
use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\Concerns\ResolvesLocationSuffix;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Models\Institution;
use App\Support\Location\AddressAssignments;
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
            $this->areaName($assignments[AddressAssignments::ADMINISTRATIVE_SUBDIVISION] ?? null),
        ]);
        $district = $this->firstFilled([
            $this->areaName($assignments[AddressAssignments::ADMINISTRATIVE_DISTRICT] ?? null),
        ]);
        $state = $this->firstFilled([
            $address['state'] ?? null,
            $this->stateName($address['state_id'] ?? null),
            $this->stateName($address['state_id'] ?? null),
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
}
