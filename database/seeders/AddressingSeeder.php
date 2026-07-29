<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Addressing\Actions\SeedAddressCitiesAction;
use AIArmada\Addressing\Actions\SeedAddressCountriesAction;
use AIArmada\Addressing\Actions\SeedAddressCountryReferencesAction;
use AIArmada\Addressing\Actions\SeedAddressStatesAction;
use AIArmada\Addressing\Actions\SeedCountryGeographiesAction;
use AIArmada\Addressing\Contracts\CountryGeographyProvider;
use Illuminate\Database\Seeder;
use ReflectionClass;
use RuntimeException;

class AddressingSeeder extends Seeder
{
    /**
     * Country codes whose cities are kept at 100 % in non-production.
     * Everything else is sampled to 10 %.
     *
     * @var list<string>
     */
    public const array FULL_CITY_COUNTRIES = ['MY'];

    public function run(
        SeedAddressCountriesAction $seedCountries,
        SeedAddressCountryReferencesAction $seedCountryReferences,
        SeedAddressCitiesAction $seedCities,
        SeedAddressStatesAction $seedStates,
        SeedCountryGeographiesAction $seedGeographies,
    ): void {
        $countryResult = $seedCountries->execute();
        $countryReferenceResult = $seedCountryReferences->execute();
        $stateResult = $seedStates->execute();
        $cityResult = $seedCities->execute($this->citySeedRows($seedCities));

        $geographyResult = $this->seedCountryGeographies($seedGeographies);

        $this->command->info(sprintf(
            'Addressing seeded: countries %d created / %d updated / %d skipped; country references %d currency links / %d timezone links; states %d created / %d updated / %d skipped; cities %d created / %d updated / %d skipped; country geographies seeded for %s; areas %d created / %d updated / %d skipped.',
            $countryResult['created'],
            $countryResult['updated'],
            $countryResult['skipped'],
            $countryReferenceResult['currency_links'],
            $countryReferenceResult['timezone_links'],
            $stateResult['created'],
            $stateResult['updated'],
            $stateResult['skipped'],
            $cityResult['created'],
            $cityResult['updated'],
            $cityResult['skipped'],
            implode(', ', $geographyResult['countries']),
            $geographyResult['created'],
            $geographyResult['updated'],
            $geographyResult['skipped'],
        ));
    }

    /**
     * @return list<string>
     */
    private function configuredCountryCodes(): array
    {
        /** @var list<class-string> $providerClasses */
        $providerClasses = config('addressing.geography.providers', []);

        return array_values(array_filter(array_map(
            static function (string $class): ?string {
                if (! is_a($class, CountryGeographyProvider::class, true)) {
                    return null;
                }

                return (new $class)->countryCode();
            },
            $providerClasses,
        )));
    }

    /**
     * @return array{countries: list<string>, created: int, updated: int, skipped: int}
     */
    private function seedCountryGeographies(SeedCountryGeographiesAction $action): array
    {
        $countries = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($this->configuredCountryCodes() as $countryCode) {
            $result = $action->execute($countryCode);
            $countries[] = $countryCode;
            $areaResult = $result['areas'][$countryCode] ?? ['created' => 0, 'updated' => 0, 'skipped' => 0];
            $created += (int) ($areaResult['created'] ?? 0);
            $updated += (int) ($areaResult['updated'] ?? 0);
            $skipped += (int) ($areaResult['skipped'] ?? 0);
        }

        return [
            'countries' => $countries,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * Keep local/test databases small while keeping configured countries complete
     * and retaining representative coverage for every other country. Production
     * continues to seed the complete dataset.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function citySeedRows(SeedAddressCitiesAction $seedCities): ?array
    {
        if (app()->isProduction()) {
            return null;
        }

        $actionPath = new ReflectionClass($seedCities)->getFileName();

        if (! is_string($actionPath)) {
            throw new RuntimeException('Unable to locate the address city seed data.');
        }

        $dataPath = dirname($actionPath, 3).'/resources/data/cities.json';
        $gzPath = $dataPath.'.gz';
        $handle = file_exists($gzPath) ? gzopen($gzPath, 'r') : null;

        if ($handle === null) {
            $contents = file_get_contents($dataPath);

            if ($contents === false) {
                throw new RuntimeException('Unable to read the address city seed data.');
            }
        } else {
            $contents = '';
            while (! gzeof($handle)) {
                $contents .= gzgets($handle);
            }
            gzclose($handle);
        }

        /** @var array<int, array<string, mixed>> $cities */
        $cities = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        unset($contents);

        // Seed only MY cities (100 %) to keep memory manageable in non-production.
        $myCities = array_values(array_filter(
            $cities,
            static fn (array $city): bool => ($city['country_code'] ?? null) === 'MY',
        ));
        unset($cities);

        return $myCities;
    }
}
