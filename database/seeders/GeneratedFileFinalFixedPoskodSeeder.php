<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Addressing\Models\Addressable;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Support\AddressAreaStateBridge;
use App\Enums\InstitutionType;
use App\Models\Institution;
use App\Support\Institutions\GeneratedPoskodInstitutionData;
use Database\Seeders\Concerns\SeedsPackageAddresses;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * @phpstan-type CsvRecord array{'No.': string, 'Nama': string, 'Alamat': string, 'Negeri': string, 'Daerah': string, 'Poskod': string}
 */
class GeneratedFileFinalFixedPoskodSeeder extends Seeder
{
    use SeedsPackageAddresses;

    private const string DEFAULT_CSV_PATH = 'seeders/Generated_File_Final_Fixed_Poskod.csv';

    private string $csvPath;

    public function __construct(?string $csvPath = null)
    {
        $this->csvPath = $csvPath ?? database_path(self::DEFAULT_CSV_PATH);
    }

    /**
     * @var array<string, string>
     */
    private const array STATE_ALIASES = [
        'N SEMBILAN' => 'Negeri Sembilan',
        'PULAU PINANG' => 'Pulau Pinang',
        'PENANG' => 'Pulau Pinang',
        'MELAKA' => 'Melaka',
        'MALACCA' => 'Melaka',
        'KUALA LUMPUR' => 'Wilayah Persekutuan Kuala Lumpur',
        'PUTRAJAYA' => 'Wilayah Persekutuan Putrajaya',
        'LABUAN' => 'Wilayah Persekutuan Labuan',
        'W P KUALA LUMPUR' => 'Wilayah Persekutuan Kuala Lumpur',
        'W P PUTRAJAYA' => 'Wilayah Persekutuan Putrajaya',
        'W P LABUAN' => 'Wilayah Persekutuan Labuan',
    ];

    /**
     * @var list<string>
     */
    private const array ALLOWED_NULL_DISTRICT_ROWS = ['6082'];

    /**
     * @var array<int|string, string>
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
     * @var array<int|string, string>
     */
    private const array SUBDISTRICT_OVERRIDES = [
        '1880' => 'Bandar Pusat Jengka',
        '1882' => 'Bandar Tun Abdul Razak',
        '6079' => 'Bandar Pusat Jengka',
        '6091' => 'Padang Rengas',
        '6092' => 'Padang Rengas',
        '6093' => 'Padang Rengas',
    ];

    private AddressCountry $malaysia;

    /**
     * @var array<string, AddressArea>
     */
    private array $statesByKey = [];

    /**
     * @var array<string, array<string, AddressArea>>
     */
    private array $districtsByState = [];

    /**
     * @var array<string, array<string, AddressArea>>
     */
    private array $subdistrictsByDistrict = [];

    /**
     * @var array<string, array<string, AddressArea>>
     */
    private array $subdistrictsByState = [];

    public function run(): void
    {
        $csvPath = $this->csvPath;

        if (! File::exists($csvPath)) {
            throw new RuntimeException('CSV file not found: '.$csvPath);
        }

        $this->bootGeographyLookups();

        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV file: '.$csvPath);
        }

        $header = fgetcsv($handle, escape: '\\');

        if (! is_array($header)) {
            fclose($handle);

            throw new RuntimeException('Unable to read CSV header: '.$csvPath);
        }

        $nullDistrictRows = [];
        $resolvedSubdistricts = 0;
        $imported = 0;

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $record = $this->mapCsvRow($header, $row);
            $state = $this->resolveState($record['Negeri']);
            $district = $this->resolveDistrict($state, $record);
            $subdistrict = $this->resolveSubdistrict($state, $district, $record);
            $slug = GeneratedPoskodInstitutionData::canonicalSlug($record['Nama'], $record['No.']);

            if (! $district instanceof AddressArea && ! $this->isFederalTerritory($state)) {
                $nullDistrictRows[] = $record['No.'];
            }

            $institution = Institution::withoutEvents(function () use ($slug, $record): Institution {
                /** @var Institution $institution */
                $institution = Institution::query()->firstOrNew(['slug' => $slug]);
                $institution->fill([
                    'slug' => $slug,
                    'name' => $record['Nama'],
                    'type' => InstitutionType::Masjid->value,
                    'status' => 'verified',
                ]);
                $institution->saveQuietly();

                return $institution;
            });

            Addressable::withoutEvents(function () use ($institution, $record, $state, $district, $subdistrict): void {
                $packageStateId = AddressAreaStateBridge::stateIdForArea($state);

                $this->seedPrimaryPackageAddress($institution, [
                    'line1' => $this->nullableString($record['Alamat']),
                    'postcode' => $this->normalizePostcode($record['Poskod']),
                    'country_id' => (string) $this->malaysia->getKey(),
                    'state_id' => $packageStateId,
                    // Product: area_1 = district, area_2 = subdistrict.
                    'area_assignments' => array_filter([
                        'administrative_district' => $district?->getKey(),
                        'administrative_subdivision' => $subdistrict?->getKey(),
                    ]),
                ]);
            });

            if ($institution->slug !== $slug) {
                $institution->forceFill(['slug' => $slug])->saveQuietly();
            }

            if ($subdistrict instanceof AddressArea) {
                $resolvedSubdistricts++;
            }

            $imported++;
        }

        fclose($handle);

        sort($nullDistrictRows);

        if ($nullDistrictRows !== self::ALLOWED_NULL_DISTRICT_ROWS) {
            throw new RuntimeException('Unexpected rows without a district mapping: '.implode(', ', $nullDistrictRows));
        }

        if ($this->command !== null) {
            $this->command->info(sprintf(
                'Imported %d postcode rows (%d rows with district mapping, %d rows with subdistrict mapping).',
                $imported,
                $imported - count($nullDistrictRows),
                $resolvedSubdistricts,
            ));
        }
    }

    private function bootGeographyLookups(): void
    {
        $malaysia = $this->malaysiaCountry();

        if (! $malaysia instanceof AddressCountry) {
            throw new RuntimeException('Malaysia was not found. Run ProductionSeeder first.');
        }

        $this->malaysia = $malaysia;

        /** @var Collection<int, AddressArea> $states */
        $states = AddressArea::query()
            ->where('country_code', 'MY')
            ->where('level', 1)
            ->orderBy('name')
            ->get();

        foreach ($states as $state) {
            $stateId = (string) $state->getKey();
            $stateNameKey = $this->normalizeKey($state->name);

            $this->statesByKey[$stateNameKey] = $state;

            if (str_starts_with($stateNameKey, 'WILAYAH PERSEKUTUAN ')) {
                $this->statesByKey[str_replace('WILAYAH PERSEKUTUAN ', '', $stateNameKey)] = $state;
            }

            $this->districtsByState[$stateId] = [];
            $this->subdistrictsByState[$stateId] = [];

            $districts = AddressArea::query()
                ->where('parent_id', $stateId)
                ->where('level', 2)
                ->orderBy('name')
                ->get();

            foreach ($districts as $district) {
                $districtId = (string) $district->getKey();
                $this->districtsByState[$stateId][$this->normalizeKey($district->name)] = $district;
                $this->subdistrictsByDistrict[$districtId] = [];

                $subdistricts = AddressArea::query()
                    ->where('parent_id', $districtId)
                    ->where('level', 3)
                    ->orderBy('name')
                    ->get();

                foreach ($subdistricts as $subdistrict) {
                    $this->subdistrictsByDistrict[$districtId][$this->normalizeKey($subdistrict->name)] = $subdistrict;
                }
            }

            $stateSubdistricts = AddressArea::query()
                ->where('parent_id', $stateId)
                ->where('level', 3)
                ->orderBy('name')
                ->get();

            foreach ($stateSubdistricts as $subdistrict) {
                $this->subdistrictsByState[$stateId][$this->normalizeKey($subdistrict->name)] = $subdistrict;
            }
        }

        $this->ensureSubdistrict('Betong', 'Pusa');
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, string|null>  $row
     * @return CsvRecord
     */
    private function mapCsvRow(array $header, array $row): array
    {
        $normalizedHeader = array_map(
            static fn (string $value): string => ltrim($value, "\xEF\xBB\xBF"),
            $header,
        );

        $mapped = array_combine($normalizedHeader, array_pad($row, count($normalizedHeader), ''));

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

    private function resolveState(string $rawState): AddressArea
    {
        $key = $this->normalizeKey($rawState);
        $canonicalName = self::STATE_ALIASES[$key] ?? $rawState;
        $state = $this->statesByKey[$this->normalizeKey($canonicalName)] ?? null;

        if (! $state instanceof AddressArea) {
            throw new RuntimeException('Unable to resolve state: '.$rawState);
        }

        return $state;
    }

    /**
     * @param  CsvRecord  $record
     */
    private function resolveDistrict(AddressArea $state, array $record): ?AddressArea
    {
        if ($this->isFederalTerritory($state)) {
            return null;
        }

        $districtName = $this->resolveDistrictName($record);

        if ($districtName === null) {
            return null;
        }

        $district = $this->districtsByState[(string) $state->getKey()][$this->normalizeKey($districtName)] ?? null;

        if ($district instanceof AddressArea) {
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
     * @param  CsvRecord  $record
     */
    private function resolveDistrictName(array $record): ?string
    {
        if (isset(self::DISTRICT_OVERRIDES[$record['No.']])) {
            return self::DISTRICT_OVERRIDES[$record['No.']];
        }

        $districtKey = $this->normalizeKey($record['Daerah']);

        if ($districtKey === '') {
            return null;
        }

        if ($districtKey === 'PUSA') {
            return 'Betong';
        }

        if ($districtKey === 'JENGKA') {
            return $this->inferJengkaDistrict($record);
        }

        return $record['Daerah'];
    }

    /**
     * @param  CsvRecord  $record
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
     * @param  CsvRecord  $record
     */
    private function resolveSubdistrict(AddressArea $state, ?AddressArea $district, array $record): ?AddressArea
    {
        if ($this->isFederalTerritory($state)) {
            $overrideName = self::SUBDISTRICT_OVERRIDES[$record['No.']] ?? null;

            if (is_string($overrideName)) {
                $subdistrict = $this->lookupSubdistrictByState($state, $overrideName);

                if ($subdistrict instanceof AddressArea) {
                    return $subdistrict;
                }
            }

            return $this->matchSubdistrictFromState($state, $this->searchableText($record));
        }

        if (! $district instanceof AddressArea) {
            return null;
        }

        $overrideName = self::SUBDISTRICT_OVERRIDES[$record['No.']] ?? $this->resolveSpecialSubdistrictName($district, $record);

        if (is_string($overrideName)) {
            $subdistrict = $this->lookupSubdistrict($district, $overrideName);

            if ($subdistrict instanceof AddressArea) {
                return $subdistrict;
            }
        }

        return $this->matchSubdistrictFromText($district, $this->searchableText($record));
    }

    /**
     * @param  CsvRecord  $record
     */
    private function resolveSpecialSubdistrictName(AddressArea $district, array $record): ?string
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

    private function lookupSubdistrict(AddressArea $district, string $subdistrictName): ?AddressArea
    {
        return $this->subdistrictsByDistrict[(string) $district->getKey()][$this->normalizeKey($subdistrictName)] ?? null;
    }

    private function lookupSubdistrictByState(AddressArea $state, string $subdistrictName): ?AddressArea
    {
        return $this->subdistrictsByState[(string) $state->getKey()][$this->normalizeKey($subdistrictName)] ?? null;
    }

    private function matchSubdistrictFromText(AddressArea $district, string $searchText): ?AddressArea
    {
        $districtKey = $this->normalizeKey($district->name);
        $matches = [];

        foreach ($this->subdistrictsByDistrict[(string) $district->getKey()] ?? [] as $key => $subdistrict) {
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

    private function matchSubdistrictFromState(AddressArea $state, string $searchText): ?AddressArea
    {
        $matches = [];

        foreach ($this->subdistrictsByState[(string) $state->getKey()] ?? [] as $key => $subdistrict) {
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

                $subdistrict = AddressArea::query()->firstOrCreate(
                    [
                        'country_id' => $district->country_id,
                        'parent_id' => $district->getKey(),
                        'country_code' => 'MY',
                        'type' => 'subdistrict',
                        'level' => 3,
                        'name' => $subdistrictName,
                        'source' => 'generated_poskod_import',
                        'source_id' => 'generated-poskod-'.strtolower($this->normalizeKey($districtName.'-'.$subdistrictName)),
                    ],
                    [
                        'slug' => str($subdistrictName)->slug()->value(),
                    ],
                );

                $this->subdistrictsByDistrict[(string) $district->getKey()][$this->normalizeKey($subdistrict->name)] = $subdistrict;

                return;
            }
        }

        throw new RuntimeException('Unable to ensure subdistrict for missing district: '.$districtName);
    }

    /**
     * @param  CsvRecord  $record
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

    private function isFederalTerritory(AddressArea $state): bool
    {
        return in_array($this->normalizeKey($state->name), [
            'KUALA LUMPUR',
            'PUTRAJAYA',
            'LABUAN',
            'WILAYAH PERSEKUTUAN KUALA LUMPUR',
            'WILAYAH PERSEKUTUAN PUTRAJAYA',
            'WILAYAH PERSEKUTUAN LABUAN',
        ], true);
    }
}
