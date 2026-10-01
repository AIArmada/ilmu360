<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressAreaHierarchyResolver;
use AIArmada\Addressing\Support\CountryAddressProfileResolver;
use App\Actions\Institutions\ImportInstitutionGraphAction;
use App\Data\InstitutionData;
use App\Enums\InstitutionStatus;
use App\Enums\InstitutionType;
use App\Models\Institution;
use App\Models\InstitutionImportExclusion;
use App\Support\Institutions\GeneratedPoskodInstitutionData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Canonical national masjid-directory feed importer.
 *
 * Consumes database/seeders/masjid_feed_v1.csv only. Every row carries its
 * own source/external_ref identity plus opaque curated nama_display/slug
 * bytes, which are written as-is: no name normalization, no slug derivation,
 * no trimming or case rewrites. Only address line1 keeps the historical
 * normalizeAddressLine() behavior.
 *
 * Matching is by (source, external_ref) byte identity exclusively. New rows
 * import as Verified; existing Pending rows refresh but stay Pending;
 * Verified, Rejected, and Inactive rows are completely untouched, including
 * their graphs and timestamps. Deleted source identities stay excluded
 * through InstitutionImportExclusion even after deleted_models pruning.
 *
 * Lifecycle hooks stay enabled: imports run with normal model events, cache,
 * and search side effects, and source-backed slugs are protected from
 * regeneration by the slug generator. New Verified rows receive verified_at
 * and last_state_change_at; pending refreshes retain their status
 * timestamps.
 *
 * The whole feed is preflighted (header, width, required identity fields,
 * max lengths, institution type, canonical state codes, coordinates and
 * their pair shape, in-feed duplicates) before any write, so a malformed
 * feed fails loudly with zero partial writes. Each row then imports in its
 * own transaction holding a row lock across the identity lookup, a
 * post-lock exclusion recheck, the moderation guard, and the write, so a
 * row verified concurrently can never be overwritten back to Pending and
 * an identity deleted concurrently is skipped, never recreated.
 *
 * @phpstan-type FeedRow array{line: int, values: array<string, string>, width: int}
 */
class MalaysiaPoskodMasjidSeeder extends Seeder
{
    private const string DEFAULT_CSV_PATH = 'seeders/masjid_feed_v1.csv';

    /**
     * @var list<string>
     */
    private const array EXPECTED_HEADER = [
        'source',
        'external_ref',
        'source_no',
        'institution_type',
        'nama_display',
        'slug',
        'line1',
        'line2',
        'line3',
        'city',
        'state_name',
        'state_code',
        'district_name',
        'subdistrict_name',
        'locality_name',
        'postcode',
        'latitude',
        'longitude',
        'curation_status',
    ];

    /**
     * @var list<string>
     */
    private const array REQUIRED_FIELDS = [
        'source',
        'external_ref',
        'institution_type',
        'nama_display',
        'slug',
        'state_code',
    ];

    /**
     * Feed columns validated against the DTO/database max lengths during
     * preflight, before the first write.
     *
     * @var array<string, int>
     */
    private const array MAX_LENGTH_FIELDS = [
        'source' => 255,
        'external_ref' => 255,
        'nama_display' => 255,
        'slug' => 255,
        'line2' => 255,
        'line3' => 255,
        'city' => 255,
        'postcode' => 20,
    ];

    private const string ROLE_DISTRICT = 'administrative_district';

    private const string ROLE_SUBDISTRICT = 'administrative_subdivision';

    private const string ROLE_LOCALITY = 'postal_locality';

    private string $csvPath;

    private AddressCountry $malaysia;

    private CountryAddressProfileResolver $profiles;

    private AddressAreaHierarchyResolver $hierarchyResolver;

    /**
     * @var array<string, State>
     */
    private array $statesByCode = [];

    /**
     * Request-local resolution caches. Feed area and city names repeat
     * heavily across rows; the lookup is cached but every unresolved
     * occurrence is still reported per row.
     *
     * @var array<string, AddressArea|null>
     */
    private array $areaCache = [];

    /**
     * @var array<string, City|null>
     */
    private array $cityCache = [];

    /**
     * @var list<string>
     */
    private array $unresolvedAreas = [];

    public function __construct(?string $csvPath = null)
    {
        $this->csvPath = $csvPath ?? database_path(self::DEFAULT_CSV_PATH);
    }

    /**
     * Non-blank optional areas that could not be resolved to a structured
     * area. Their feed text is retained in the address feed_geography
     * metadata; nothing is guessed.
     *
     * @return list<string>
     */
    public function unresolvedAreaReports(): array
    {
        return $this->unresolvedAreas;
    }

    public function run(): void
    {
        $this->statesByCode = [];
        $this->areaCache = [];
        $this->cityCache = [];
        $this->unresolvedAreas = [];

        $rows = $this->readFeedRows();

        $this->bootGeography();
        $this->preflight($rows);

        $action = app(ImportInstitutionGraphAction::class);
        $created = 0;
        $refreshed = 0;
        $excluded = 0;
        $skipped = [
            InstitutionStatus::Verified->value => 0,
            InstitutionStatus::Rejected->value => 0,
            InstitutionStatus::Inactive->value => 0,
        ];

        foreach ($rows as $row) {
            $outcome = DB::transaction(function () use ($row, $action): string {
                $values = $row['values'];
                $source = $values['source'];
                $externalRef = $values['external_ref'];

                if (InstitutionImportExclusion::excludes($source, $externalRef)) {
                    return 'excluded';
                }

                $existing = Institution::query()
                    ->where('source', $source)
                    ->where('external_ref', $externalRef)
                    ->lockForUpdate()
                    ->first();

                // A concurrent delete may have committed while the lock query
                // waited, leaving no row behind. Recheck after the locked
                // lookup so the excluded identity is skipped, never recreated.
                if (InstitutionImportExclusion::excludes($source, $externalRef)) {
                    return 'excluded';
                }

                if ($existing instanceof Institution && $existing->status !== InstitutionStatus::Pending) {
                    return $existing->status->value;
                }

                $data = InstitutionData::validateAndCreate(
                    $this->buildPayload($row['line'], $values, $existing)
                );

                $action->handle($data, $existing);

                return $existing instanceof Institution ? 'refreshed' : 'created';
            });

            match ($outcome) {
                'created' => $created++,
                'refreshed' => $refreshed++,
                'excluded' => $excluded++,
                default => $skipped[$outcome] = ($skipped[$outcome] ?? 0) + 1,
            };
        }

        $this->report($created, $refreshed, $excluded, $skipped);
    }

    /**
     * @return list<FeedRow>
     */
    private function readFeedRows(): array
    {
        if (! File::exists($this->csvPath)) {
            throw new RuntimeException('CSV file not found: '.$this->csvPath);
        }

        $handle = fopen($this->csvPath, 'r');

        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV file: '.$this->csvPath);
        }

        $header = fgetcsv($handle, escape: '\\');

        if (! is_array($header)) {
            fclose($handle);

            throw new RuntimeException('Unable to read CSV header: '.$this->csvPath);
        }

        $header[0] = ltrim((string) $header[0], "\xEF\xBB\xBF");

        if ($header !== self::EXPECTED_HEADER) {
            fclose($handle);

            throw new RuntimeException(
                'Unexpected feed header. Expected ['.implode(',', self::EXPECTED_HEADER).'] got ['.implode(',', $header).'].'
            );
        }

        $rows = [];
        $line = 1;

        while (($record = fgetcsv($handle, escape: '\\')) !== false) {
            $line++;

            if ($this->isBlankLine($record)) {
                continue;
            }

            $values = [];

            foreach (self::EXPECTED_HEADER as $index => $column) {
                $values[$column] = (string) ($record[$index] ?? '');
            }

            $rows[] = [
                'line' => $line,
                'values' => $values,
                'width' => count($record),
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, string|null>  $record
     */
    private function isBlankLine(array $record): bool
    {
        foreach ($record as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<FeedRow>  $rows
     */
    private function preflight(array $rows): void
    {
        $errors = [];
        $seenIdentities = [];
        $seenSlugs = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $values = $row['values'];

            if (($row['width'] ?? 0) !== count(self::EXPECTED_HEADER)) {
                $errors[] = "row {$line}: expected ".count(self::EXPECTED_HEADER).' columns, got '.($row['width'] ?? 0).'.';

                continue;
            }

            foreach (self::REQUIRED_FIELDS as $field) {
                if (trim($values[$field]) === '') {
                    $errors[] = "row {$line}: missing required '{$field}'.";
                }
            }

            foreach (self::MAX_LENGTH_FIELDS as $field => $max) {
                if (mb_strlen($values[$field]) > $max) {
                    $errors[] = "row {$line}: '{$field}' exceeds {$max} characters.";
                }
            }

            $normalizedLine1 = GeneratedPoskodInstitutionData::normalizeAddressLine($values['line1']);

            if (mb_strlen($normalizedLine1) > 255) {
                $errors[] = "row {$line}: 'line1' exceeds 255 characters.";
            }

            $stateCode = trim($values['state_code']);

            if ($stateCode !== '' && ! isset($this->statesByCode[$stateCode])) {
                $errors[] = "row {$line}: unknown state_code '{$values['state_code']}'.";
            }

            $type = trim($values['institution_type']);

            if ($type !== '' && InstitutionType::tryFrom($type) === null) {
                $errors[] = "row {$line}: invalid institution_type '{$values['institution_type']}'.";
            }

            $latitude = trim($values['latitude']);
            $longitude = trim($values['longitude']);

            if (($latitude === '') !== ($longitude === '')) {
                $errors[] = "row {$line}: latitude and longitude must both be present or both be blank.";
            }

            foreach (['latitude' => [-90.0, 90.0], 'longitude' => [-180.0, 180.0]] as $field => [$min, $max]) {
                $raw = trim($values[$field]);

                if ($raw === '') {
                    continue;
                }

                if (! is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
                    $errors[] = "row {$line}: invalid {$field} '{$values[$field]}'.";
                }
            }

            $identityKey = $values['source']."\0".$values['external_ref'];

            if (isset($seenIdentities[$identityKey])) {
                $errors[] = "row {$line}: duplicate source/external_ref already seen on row {$seenIdentities[$identityKey]}.";
            } else {
                $seenIdentities[$identityKey] = $line;
            }

            $slug = $values['slug'];

            if ($slug !== '') {
                if (isset($seenSlugs[$slug]) && $seenSlugs[$slug]['identity'] !== $identityKey) {
                    $errors[] = "row {$line}: slug '{$slug}' collides with a different identity on row {$seenSlugs[$slug]['line']}.";
                } else {
                    $seenSlugs[$slug] = ['line' => $line, 'identity' => $identityKey];
                }
            }
        }

        if ($errors !== []) {
            throw new RuntimeException('Feed preflight failed with '.count($errors)." error(s):\n".implode("\n", $errors));
        }
    }

    private function bootGeography(): void
    {
        $malaysia = AddressCountry::query()->where('iso2', 'MY')->first();

        if (! $malaysia instanceof AddressCountry) {
            throw new RuntimeException('Malaysia was not found. Run the addressing seeder first.');
        }

        $this->malaysia = $malaysia;
        $this->profiles = app(CountryAddressProfileResolver::class);
        $this->hierarchyResolver = app(AddressAreaHierarchyResolver::class);

        foreach (State::query()->where('country_id', $malaysia->getKey())->get() as $state) {
            $code = trim((string) $state->code);

            if ($code !== '') {
                $this->statesByCode[$code] = $state;
            }
        }
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private function buildPayload(int $line, array $values, ?Institution $existing): array
    {
        $state = $this->statesByCode[trim($values['state_code'])] ?? null;

        if (! $state instanceof State) {
            throw new RuntimeException(
                "row {$line}: unknown state_code '{$values['state_code']}' for {$values['source']}/{$values['external_ref']}."
            );
        }

        $identity = "{$values['source']}/{$values['external_ref']}";
        $selectedIdsByRole = [];

        $district = $this->resolveOptionalArea($line, $identity, self::ROLE_DISTRICT, $values['district_name'], $state, $selectedIdsByRole);

        if ($district instanceof AddressArea) {
            $selectedIdsByRole[self::ROLE_DISTRICT] = (string) $district->getKey();
        }

        $subdistrict = $this->resolveOptionalArea($line, $identity, self::ROLE_SUBDISTRICT, $values['subdistrict_name'], $state, $selectedIdsByRole);

        if ($subdistrict instanceof AddressArea) {
            $selectedIdsByRole[self::ROLE_SUBDISTRICT] = (string) $subdistrict->getKey();
        }

        $locality = $this->resolveOptionalArea($line, $identity, self::ROLE_LOCALITY, $values['locality_name'], $state, $selectedIdsByRole);

        $cityName = trim($values['city']);
        $city = $cityName === '' ? null : $this->findCity($cityName, $state);

        return [
            'name' => $values['nama_display'],
            'slug' => $values['slug'],
            'type' => trim($values['institution_type']),
            'status' => $existing instanceof Institution
                ? InstitutionStatus::Pending->value
                : InstitutionStatus::Verified->value,
            'source' => $values['source'],
            'external_ref' => $values['external_ref'],
            'address' => [
                'country_id' => (string) $this->malaysia->getKey(),
                'state_id' => (string) $state->getKey(),
                'city_id' => $city instanceof City ? (string) $city->getKey() : null,
                'line1' => $this->emptyToNull(GeneratedPoskodInstitutionData::normalizeAddressLine($values['line1'])),
                'line2' => $this->emptyToNull($values['line2']),
                'line3' => $this->emptyToNull($values['line3']),
                'city' => $this->emptyToNull($values['city']),
                'state' => $state->name,
                'postcode' => $this->emptyToNull($values['postcode']),
                'latitude' => $this->emptyToNull($values['latitude']),
                'longitude' => $this->emptyToNull($values['longitude']),
                'area_assignments' => array_filter([
                    self::ROLE_DISTRICT => $district instanceof AddressArea ? (string) $district->getKey() : null,
                    self::ROLE_SUBDISTRICT => $subdistrict instanceof AddressArea ? (string) $subdistrict->getKey() : null,
                    self::ROLE_LOCALITY => $locality instanceof AddressArea ? (string) $locality->getKey() : null,
                ]),
                'metadata' => [
                    'feed_geography' => array_filter([
                        'district_name' => $this->emptyToNull($values['district_name']),
                        'subdistrict_name' => $this->emptyToNull($values['subdistrict_name']),
                        'locality_name' => $this->emptyToNull($values['locality_name']),
                    ]),
                ],
            ],
        ];
    }

    /**
     * Resolve an optional feed area label through the canonical country
     * profile and hierarchy resolver: the profile scopes the lookup to the
     * selected parent roles (so district-less states and postal refinements
     * behave), and the resolver matches exact names case-insensitively under
     * that scope over active, temporally valid hierarchy links. No geography
     * is created and nothing is guessed.
     *
     * @param  array<string, string>  $selectedIdsByRole
     */
    private function resolveOptionalArea(
        int $line,
        string $identity,
        string $role,
        string $rawName,
        State $state,
        array $selectedIdsByRole,
    ): ?AddressArea {
        $name = trim($rawName);

        if ($name === '') {
            return null;
        }

        $countryId = (string) $this->malaysia->getKey();
        $cacheKey = $role."\0".$name."\0".$state->getKey()."\0".json_encode($selectedIdsByRole);

        if (! array_key_exists($cacheKey, $this->areaCache)) {
            $this->areaCache[$cacheKey] = $this->lookupScopedArea($role, $name, $state, $selectedIdsByRole, $countryId);
        }

        $area = $this->areaCache[$cacheKey];

        if (! $area instanceof AddressArea) {
            $this->unresolvedAreas[] = "row {$line} ({$identity}): {$role} '{$name}' unresolved; feed text retained.";
        }

        return $area;
    }

    /**
     * @param  array<string, string>  $selectedIdsByRole
     */
    private function lookupScopedArea(
        string $role,
        string $name,
        State $state,
        array $selectedIdsByRole,
        string $countryId,
    ): ?AddressArea {
        $definition = $this->profiles->definitionForRole($countryId, $role);

        if ($definition === null) {
            return null;
        }

        $parentId = $this->profiles->parentAreaIdForRole($countryId, $role, (string) $state->getKey(), $selectedIdsByRole);

        if ($parentId === null) {
            return null;
        }

        return $this->hierarchyResolver->resolveWithinHierarchy(
            $name,
            $countryId,
            $parentId,
            CountryAddressProfileResolver::hierarchyType($definition['hierarchy'], $definition['level']),
            CountryAddressProfileResolver::areaTypesForLevel($definition['level']),
        );
    }

    private function findCity(string $name, State $state): ?City
    {
        $key = $state->getKey()."\0".$name;

        if (! array_key_exists($key, $this->cityCache)) {
            $city = City::query()->where('state_id', $state->getKey())->where('name', $name)->first();
            $this->cityCache[$key] = $city instanceof City ? $city : null;
        }

        return $this->cityCache[$key];
    }

    private function emptyToNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, int>  $skipped
     */
    private function report(int $created, int $refreshed, int $excluded, array $skipped): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->info(sprintf(
            'Feed import: %d created, %d pending refreshed, %d excluded, %d verified skipped, %d rejected skipped, %d inactive skipped.',
            $created,
            $refreshed,
            $excluded,
            $skipped[InstitutionStatus::Verified->value] ?? 0,
            $skipped[InstitutionStatus::Rejected->value] ?? 0,
            $skipped[InstitutionStatus::Inactive->value] ?? 0,
        ));

        if ($this->unresolvedAreas !== []) {
            $this->command->warn(count($this->unresolvedAreas).' optional area(s) unresolved; feed text retained:');

            foreach (array_slice($this->unresolvedAreas, 0, 25) as $report) {
                $this->command->warn('  '.$report);
            }

            if (count($this->unresolvedAreas) > 25) {
                $this->command->warn('  … and '.(count($this->unresolvedAreas) - 25).' more.');
            }
        }
    }
}
