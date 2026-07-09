<?php

namespace App\Actions\Institutions;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
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
        $city = $this->firstFilled([
            $address['city'] ?? null,
        ]);
        $district = $this->firstFilled([
            $address['admin_area_2_name'] ?? null,
            $this->areaName($address['admin_area_2_id'] ?? null),
            $address['district'] ?? null,
        ]);
        $state = $this->firstFilled([
            $address['admin_area_1_name'] ?? null,
            $this->areaName($address['admin_area_1_id'] ?? null),
            $address['state'] ?? null,
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
