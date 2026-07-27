<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Addressing\Actions\SeedAddressCitiesAction;
use AIArmada\Addressing\Actions\SeedAddressCountriesAction;
use AIArmada\Addressing\Actions\SeedAddressCountryReferencesAction;
use AIArmada\Addressing\Actions\SeedAddressStatesAction;
use AIArmada\Addressing\Actions\SeedCountryGeographiesAction;
use Illuminate\Database\Seeder;
use ReflectionClass;
use RuntimeException;

class AddressingSeeder extends Seeder
{
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
        $geographyResult = $seedGeographies->execute('MY');
        $areaResult = $geographyResult['areas']['MY'] ?? [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        $this->command->info(sprintf(
            'Addressing seeded: countries %d created / %d updated / %d skipped; country references %d currency links / %d timezone links; states %d created / %d updated / %d skipped; cities %d created / %d updated / %d skipped; Malaysia geography provider selected; areas %d created / %d updated / %d skipped.',
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
            $areaResult['created'],
            $areaResult['updated'],
            $areaResult['skipped'],
        ));
    }

    /**
     * Keep local/test databases small while keeping Malaysia complete and
     * retaining representative coverage for every other country. Production
     * continues to seed the complete dataset.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function citySeedRows(SeedAddressCitiesAction $seedCities): ?array
    {
        if (app()->isProduction()) {
            return null;
        }

        $actionPath = (new ReflectionClass($seedCities))->getFileName();

        if (! is_string($actionPath)) {
            throw new RuntimeException('Unable to locate the address city seed data.');
        }

        $dataPath = dirname($actionPath, 3).'/resources/data/cities.json';
        $contents = file_get_contents($dataPath);

        if ($contents === false) {
            throw new RuntimeException('Unable to read the address city seed data.');
        }

        /** @var array<int, array<string, mixed>> $cities */
        $cities = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $citiesByCountry = [];

        foreach ($cities as $city) {
            $countryCode = $city['country_code'] ?? null;

            if (is_string($countryCode) && $countryCode !== '') {
                $citiesByCountry[$countryCode][] = $city;
            }
        }

        $sample = [];

        foreach ($citiesByCountry as $countryCode => $countryCities) {
            $sampleSize = $countryCode === 'MY'
                ? count($countryCities)
                : max(1, (int) ceil(count($countryCities) * 0.1));
            array_push($sample, ...array_slice($countryCities, 0, $sampleSize));
        }

        return $sample;
    }
}
