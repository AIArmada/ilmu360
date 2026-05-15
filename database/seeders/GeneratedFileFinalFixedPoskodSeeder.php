<?php

namespace Database\Seeders;

use App\Enums\InstitutionType;
use App\Models\Address;
use App\Models\Country;
use App\Models\District;
use App\Models\Institution;
use App\Models\State;
use App\Models\Subdistrict;
use App\Support\Institutions\GeneratedPoskodInstitutionData;
use App\Support\Location\FederalTerritoryLocation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * @phpstan-type CsvRecord array{'No.': string, 'Nama': string, 'Alamat': string, 'Negeri': string, 'Daerah': string, 'Poskod': string}
 */
class GeneratedFileFinalFixedPoskodSeeder extends Seeder
{
    private const string CSV_PATH = 'seeders/Generated_File_Final_Fixed_Poskod.csv';

    /**
     * @var array<string, string>
     */
    private const array STATE_ALIASES = [
        'N SEMBILAN' => 'Negeri Sembilan',
        'PULAU PINANG' => 'Penang',
        'MELAKA' => 'Malacca',
        'W P KUALA LUMPUR' => 'Kuala Lumpur',
        'W P PUTRAJAYA' => 'Putrajaya',
        'W P LABUAN' => 'Labuan',
    ];

    /**
     * Rows whose source data is too incomplete to resolve a district safely.
     *
     * @var list<string>
     */
    private const array ALLOWED_NULL_DISTRICT_ROWS = ['6082'];

    /**
     * Explicit district fixes for blank / non-canonical source rows.
     *
     * @var array<int, string>
     */
    private const array DISTRICT_OVERRIDES = [
        '500' => 'Kuala Kangsar',
        '1880' => 'Jerantut',
        '1882' => 'Maran',
        '4422' => 'Kluang',
        '4668' => 'Segamat',
        '6034' => 'Kinta',
        '6079' => 'Jerantut',
        '6090' => 'Kuala Kangsar',
        '6091' => 'Kuala Kangsar',
        '6092' => 'Kuala Kangsar',
        '6093' => 'Kuala Kangsar',
        '6094' => 'Kuantan',
        '6104' => 'Kuantan',
        '6107' => 'Lipis',
    ];

    /**
     * Explicit subdistrict fixes for rows that do not expose the locality in a way we can
     * safely discover from the text alone.
     *
     * @var array<int, string>
     */
    private const array SUBDISTRICT_OVERRIDES = [
        '1880' => 'Bandar Pusat Jengka',
        '1882' => 'Bandar Tun Abdul Razak',
        '6079' => 'Bandar Pusat Jengka',
        '6091' => 'Padang Rengas',
        '6092' => 'Padang Rengas',
        '6093' => 'Padang Rengas',
    ];

    private Country $malaysia;

    /**
     * @var array<string, State>
     */
    private array $statesByKey = [];

    /**
     * @var array<int, array<string, District>>
     */
    private array $districtsByState = [];

    /**
     * @var array<int, array<string, Subdistrict>>
     */
    private array $subdistrictsByDistrict = [];

    /**
     * @var array<int, array<string, Subdistrict>>
     */
    private array $subdistrictsByState = [];

    /**
     * @var array<string, true>
     */
    private array $canonicalSlugs = [];

    public function run(): void
    {
        $csvPath = database_path(self::CSV_PATH);

        if (! File::exists($csvPath)) {
            throw new RuntimeException('CSV file not found: '.$csvPath);
        }

        $this->bootGeographyLookups();
        $this->canonicalSlugs = array_fill_keys(GeneratedPoskodInstitutionData::allCanonicalSlugs(), true);

        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV file: '.$csvPath);
        }

        $header = fgetcsv($handle, escape: '\\');

        if (! is_array($header)) {
            fclose($handle);

            throw new RuntimeException('Unable to read CSV header: '.$csvPath);
        }

        $imported = 0;
        $resolvedSubdistricts = 0;

        /**
         * @var list<string> $nullDistrictRows
         */
        $nullDistrictRows = [];

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $record = $this->mapCsvRow($header, $row);
            $state = $this->resolveState($record['Negeri']);
            $district = $this->resolveDistrict($state, $record);
            $subdistrict = $this->resolveSubdistrict($state, $district, $record);
            $slug = GeneratedPoskodInstitutionData::canonicalSlug($record['Nama'], $record['No.']);

            if (! $district instanceof District && ! FederalTerritoryLocation::isFederalTerritoryStateId($state->getKey())) {
                $nullDistrictRows[] = $record['No.'];
            }

            $line1 = $this->nullableString($record['Alamat']);
            $postcode = $this->normalizePostcode($record['Poskod']);

            $institution = Institution::query()
                ->where('slug', $slug)
                ->first();

            if (! $institution instanceof Institution) {
                $institution = Institution::query()
                    ->where('name', $record['Nama'])
                    ->whereHas('address', function ($addressQuery) use ($line1, $postcode, $state, $district, $subdistrict): void {
                        $addressQuery
                            ->where('type', 'main')
                            ->where('line1', $line1)
                            ->where('postcode', $postcode)
                            ->where('country_id', $this->malaysia->getKey())
                            ->where('state_id', $state->getKey())
                            ->where('district_id', $district?->getKey())
                            ->where('subdistrict_id', $subdistrict?->getKey());
                    })
                    ->get()
                    ->first(function (Institution $candidate): bool {
                        $candidateSlug = $candidate->slug;

                        return ! is_string($candidateSlug)
                            || ! isset($this->canonicalSlugs[$candidateSlug]);
                    });
            }

            if ($institution instanceof Institution) {
                Institution::withoutEvents(function () use ($institution, $slug, $record): void {
                    $institution->forceFill([
                        'slug' => $slug,
                        'name' => $record['Nama'],
                        'type' => InstitutionType::Masjid->value,
                        'status' => 'verified',
                        'is_active' => true,
                    ])->saveQuietly();
                });
            } else {
                $institution = Institution::withoutEvents(fn (): Institution => Institution::query()->create([
                    'slug' => $slug,
                    'name' => $record['Nama'],
                    'type' => InstitutionType::Masjid->value,
                    'status' => 'verified',
                    'is_active' => true,
                ]));
            }

            Address::withoutEvents(function () use ($institution, $line1, $postcode, $state, $district, $subdistrict): void {
                $institution->address()->updateOrCreate(
                    ['type' => 'main'],
                    [
                        'line1' => $line1,
                        'postcode' => $postcode,
                        'country_id' => $this->malaysia->getKey(),
                        'state_id' => $state->getKey(),
                        'district_id' => $district?->getKey(),
                        'subdistrict_id' => $subdistrict?->getKey(),
                        'city_id' => null,
                        'lat' => null,
                        'lng' => null,
                    ]
                );
            });

            if ($subdistrict instanceof Subdistrict) {
                $resolvedSubdistricts++;
            }

            $imported++;
        }

        fclose($handle);

        sort($nullDistrictRows);

        if ($nullDistrictRows !== self::ALLOWED_NULL_DISTRICT_ROWS) {
            throw new RuntimeException('Unexpected rows without a district mapping: '.implode(', ', $nullDistrictRows));
        }

        $this->command->info(sprintf(
            'Imported %d postcode rows (%d rows with district mapping, %d rows with subdistrict mapping).',
            $imported,
            $imported - count($nullDistrictRows),
            $resolvedSubdistricts,
        ));
    }

    private function bootGeographyLookups(): void
    {
        $malaysia = Country::query()->where('iso2', 'MY')->first();

        if (! $malaysia instanceof Country) {
            throw new RuntimeException('Malaysia was not found. Run ProductionSeeder first.');
        }

        $this->malaysia = $malaysia;

        /** @var Collection<int, State> $states */
        $states = State::query()
            ->where('country_id', $malaysia->getKey())
            ->with(['districts.subdistricts', 'subdistricts'])
            ->get();

        foreach ($states as $state) {
            $this->statesByKey[$this->normalizeKey($state->name)] = $state;
            $this->districtsByState[$state->getKey()] = [];
            $this->subdistrictsByState[$state->getKey()] = [];

            foreach ($state->districts as $district) {
                $this->districtsByState[$state->getKey()][$this->normalizeKey($district->name)] = $district;
                $this->subdistrictsByDistrict[$district->getKey()] = [];

                foreach ($district->subdistricts as $subdistrict) {
                    $this->subdistrictsByDistrict[$district->getKey()][$this->normalizeKey($subdistrict->name)] = $subdistrict;
                }
            }

            foreach ($state->subdistricts()->whereNull('district_id')->get() as $subdistrict) {
                $this->subdistrictsByState[$state->getKey()][$this->normalizeKey($subdistrict->name)] = $subdistrict;
            }
        }

        $this->ensureSubdistrict('Betong', 'Pusa');
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, string|null>  $row
     *
     * @phpstan-return CsvRecord
     */
    private function mapCsvRow(array $header, array $row): array
    {
        $normalizedHeader = array_map(
            static fn (string $value): string => ltrim($value, "\xEF\xBB\xBF"),
            $header,
        );

        $mapped = array_combine($normalizedHeader, array_pad($row, count($normalizedHeader), ''));

        /** @var CsvRecord $normalized */
        $normalized = [
            'No.' => trim((string) ($mapped['No.'] ?? '')),
            'Nama' => GeneratedPoskodInstitutionData::normalizeInstitutionName((string) ($mapped['Nama'] ?? '')),
            'Alamat' => GeneratedPoskodInstitutionData::normalizeAddressLine((string) ($mapped['Alamat'] ?? '')),
            'Negeri' => trim((string) ($mapped['Negeri'] ?? '')),
            'Daerah' => trim((string) ($mapped['Daerah'] ?? '')),
            'Poskod' => trim((string) ($mapped['Poskod'] ?? '')),
        ];

        if ($normalized['No.'] === '' || $normalized['Nama'] === '' || $normalized['Negeri'] === '') {
            throw new RuntimeException('Required postcode CSV fields are missing for row: '.json_encode($normalized, JSON_THROW_ON_ERROR));
        }

        return $normalized;
    }

    private function resolveState(string $rawState): State
    {
        $key = $this->normalizeKey($rawState);
        $canonicalName = self::STATE_ALIASES[$key] ?? $rawState;
        $state = $this->statesByKey[$this->normalizeKey($canonicalName)] ?? null;

        if (! $state instanceof State) {
            throw new RuntimeException('Unable to resolve state: '.$rawState);
        }

        return $state;
    }

    /**
     * @phpstan-param CsvRecord $record
     */
    private function resolveDistrict(State $state, array $record): ?District
    {
        if (FederalTerritoryLocation::isFederalTerritoryStateId($state->getKey())) {
            return null;
        }

        $districtName = $this->resolveDistrictName($record);

        if ($districtName === null) {
            return null;
        }

        $district = $this->districtsByState[$state->getKey()][$this->normalizeKey($districtName)] ?? null;

        if ($district instanceof District) {
            return $district;
        }

        throw new RuntimeException(sprintf(
            'Unable to resolve district "%s" for row %s (%s).',
            $districtName,
            $record['No.'],
            $record['Nama'],
        ));
    }

    /**
     * @phpstan-param CsvRecord $record
     */
    private function resolveDistrictName(array $record): ?string
    {
        if (isset(self::DISTRICT_OVERRIDES[$record['No.']])) {
            return self::DISTRICT_OVERRIDES[$record['No.']];
        }

        $rawDistrict = $record['Daerah'];
        $districtKey = $this->normalizeKey($rawDistrict);

        if ($districtKey === '') {
            return null;
        }

        if ($districtKey === 'PUSA') {
            return 'Betong';
        }

        if ($districtKey === 'JENGKA') {
            return $this->inferJengkaDistrict($record);
        }

        return $rawDistrict;
    }

    /**
     * @phpstan-param CsvRecord $record
     */
    private function inferJengkaDistrict(array $record): string
    {
        $searchText = $this->searchableText($record);
        $postcode = $this->normalizePostcode($record['Poskod']);

        if (
            $this->containsAny($searchText, ['PULAU TAWAR', 'BANDAR PUSAT JENGKA', 'LEPAR UTARA', 'SUNGAI TEKAM', 'BANDAR JENGKA']) ||
            in_array($postcode, ['27000', '27020', '27090'], true)
        ) {
            return 'Jerantut';
        }

        return 'Maran';
    }

    /**
     * @phpstan-param CsvRecord $record
     */
    private function resolveSubdistrict(State $state, ?District $district, array $record): ?Subdistrict
    {
        if (FederalTerritoryLocation::isFederalTerritoryStateId($state->getKey())) {
            $overrideName = self::SUBDISTRICT_OVERRIDES[$record['No.']] ?? null;

            if (is_string($overrideName)) {
                $subdistrict = $this->lookupSubdistrictByState($state, $overrideName);

                if ($subdistrict instanceof Subdistrict) {
                    return $subdistrict;
                }
            }

            $searchText = $this->searchableText($record);

            if ($searchText === '') {
                return null;
            }

            return $this->matchSubdistrictFromState($state, $searchText);
        }

        if (! $district instanceof District) {
            return null;
        }

        $overrideName = self::SUBDISTRICT_OVERRIDES[$record['No.']] ?? $this->resolveSpecialSubdistrictName($district, $record);

        if (is_string($overrideName)) {
            $subdistrict = $this->lookupSubdistrict($district, $overrideName);

            if ($subdistrict instanceof Subdistrict) {
                return $subdistrict;
            }
        }

        $searchText = $this->searchableText($record);

        if ($searchText === '') {
            return null;
        }

        return $this->matchSubdistrictFromText($district, $searchText);
    }

    /**
     * @phpstan-param CsvRecord $record
     */
    private function resolveSpecialSubdistrictName(District $district, array $record): ?string
    {
        $districtNameKey = $this->normalizeKey($district->name);
        $districtKey = $this->normalizeKey($record['Daerah']);
        $searchText = $this->searchableText($record);

        if ($districtKey === 'PUSA' && $districtNameKey === 'BETONG') {
            if ($this->containsAny($searchText, ['MALUDAM', 'MELUDAM'])) {
                return 'Maludam';
            }

            if ($this->containsAny($searchText, ['TRISO'])) {
                return 'Triso';
            }

            return 'Pusa';
        }

        if ($districtKey !== 'JENGKA') {
            return null;
        }

        if ($districtNameKey === 'JERANTUT') {
            if ($this->containsAny($searchText, ['PULAU TAWAR'])) {
                return 'Pulau Tawar';
            }

            if ($this->containsAny($searchText, ['BANDAR PUSAT JENGKA', 'BANDAR JENGKA', 'LEPAR UTARA', 'SUNGAI TEKAM'])) {
                return 'Bandar Pusat Jengka';
            }
        }

        if ($districtNameKey === 'MARAN') {
            if ($this->containsAny($searchText, ['CHENOR'])) {
                return 'Chenor';
            }

            if ($this->containsAny($searchText, ['UITM', 'BANDAR TUN ABDUL RAZAK', 'ULU JEMPOL', 'FELDA JENGKA'])) {
                return 'Bandar Tun Abdul Razak';
            }
        }

        return null;
    }

    private function lookupSubdistrict(District $district, string $subdistrictName): ?Subdistrict
    {
        return $this->subdistrictsByDistrict[$district->getKey()][$this->normalizeKey($subdistrictName)] ?? null;
    }

    private function lookupSubdistrictByState(State $state, string $subdistrictName): ?Subdistrict
    {
        return $this->subdistrictsByState[$state->getKey()][$this->normalizeKey($subdistrictName)] ?? null;
    }

    private function matchSubdistrictFromText(District $district, string $searchText): ?Subdistrict
    {
        $districtKey = $this->normalizeKey($district->name);

        /** @var list<array{key: string, subdistrict: Subdistrict, is_same_as_district: bool}> $matches */
        $matches = [];

        foreach ($this->subdistrictsByDistrict[$district->getKey()] ?? [] as $key => $subdistrict) {
            if ($key === '' || in_array($key, ['BANDAR', 'KAMPUNG', 'KOTA', 'KUALA', 'MUKIM', 'PEKAN'], true)) {
                continue;
            }

            if (str_contains($searchText, $key)) {
                $matches[] = [
                    'key' => $key,
                    'subdistrict' => $subdistrict,
                    'is_same_as_district' => $key === $districtKey,
                ];
            }
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, static function (array $left, array $right): int {
            if ($left['is_same_as_district'] !== $right['is_same_as_district']) {
                return $left['is_same_as_district'] ? 1 : -1;
            }

            return strlen($right['key']) <=> strlen($left['key']);
        });

        return $matches[0]['subdistrict'];
    }

    private function matchSubdistrictFromState(State $state, string $searchText): ?Subdistrict
    {
        /** @var list<array{key: string, subdistrict: Subdistrict}> $matches */
        $matches = [];

        foreach ($this->subdistrictsByState[$state->getKey()] ?? [] as $key => $subdistrict) {
            if ($key === '' || in_array($key, ['BANDAR', 'KAMPUNG', 'KOTA', 'KUALA', 'MUKIM', 'PEKAN', 'PRECINCT'], true)) {
                continue;
            }

            if (str_contains($searchText, $key)) {
                $matches[] = [
                    'key' => $key,
                    'subdistrict' => $subdistrict,
                ];
            }
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn (array $left, array $right): int => strlen($right['key']) <=> strlen($left['key']));

        return $matches[0]['subdistrict'];
    }

    private function ensureSubdistrict(string $districtName, string $subdistrictName): void
    {
        foreach ($this->districtsByState as $districtIndex) {
            foreach ($districtIndex as $district) {
                if ($this->normalizeKey($district->name) !== $this->normalizeKey($districtName)) {
                    continue;
                }

                $subdistrict = Subdistrict::query()->firstOrCreate(
                    [
                        'district_id' => $district->getKey(),
                        'name' => $subdistrictName,
                    ],
                    [
                        'country_id' => $district->country_id,
                        'state_id' => $district->state_id,
                        'country_code' => 'MY',
                    ]
                );

                $this->subdistrictsByDistrict[$district->getKey()][$this->normalizeKey($subdistrict->name)] = $subdistrict;

                return;
            }
        }

        throw new RuntimeException('Unable to ensure subdistrict for missing district: '.$districtName);
    }

    /**
     * @phpstan-param CsvRecord $record
     */
    private function searchableText(array $record): string
    {
        return $this->normalizeKey($record['Nama'].' '.$record['Alamat']);
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        return array_any($needles, fn ($needle) => str_contains($haystack, $this->normalizeKey($needle)));
    }

    private function normalizeKey(?string $value): string
    {
        $normalized = strtoupper((string) $value);
        $normalized = str_replace(['&', '/'], [' AND ', ' '], $normalized);
        $normalized = preg_replace('/[^A-Z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    private function normalizePostcode(?string $postcode): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $postcode) ?? '';

        if ($digits === '') {
            return null;
        }

        return str_pad($digits, 5, '0', STR_PAD_LEFT);
    }

    private function nullableString(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
