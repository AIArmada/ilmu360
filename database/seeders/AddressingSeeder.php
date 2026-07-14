<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Addressing\Actions\SeedAddressCountriesAction;
use AIArmada\Addressing\Actions\SeedCountryGeographiesAction;
use Illuminate\Database\Seeder;

class AddressingSeeder extends Seeder
{
    public function run(
        SeedAddressCountriesAction $seedCountries,
        SeedCountryGeographiesAction $seedGeographies,
    ): void {
        $countryResult = $seedCountries->execute();
        $geographyResult = $seedGeographies->execute('MY');
        $areaResult = $geographyResult['areas']['MY'] ?? [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        $this->command->info(sprintf(
            'Addressing seeded: countries %d created / %d updated / %d skipped; Malaysia geography provider selected; areas %d created / %d updated / %d skipped.',
            $countryResult['created'],
            $countryResult['updated'],
            $countryResult['skipped'],
            $areaResult['created'],
            $areaResult['updated'],
            $areaResult['skipped'],
        ));
    }
}
