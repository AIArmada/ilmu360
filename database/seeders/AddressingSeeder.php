<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Addressing\Actions\ImportAddressAreasAction;
use AIArmada\Addressing\Actions\SeedAddressCountriesAction;
use AIArmada\Addressing\Data\ImportAddressAreaFailureData;
use AIArmada\Addressing\Support\CsvAddressAreaSource;
use Illuminate\Database\Seeder;
use RuntimeException;

class AddressingSeeder extends Seeder
{
    private const string MALAYSIA_AREA_SOURCE = 'ilmu360_malaysia_areas_v1';

    public function run(
        SeedAddressCountriesAction $seedCountries,
        ImportAddressAreasAction $importAreas,
    ): void {
        $countryResult = $seedCountries->execute();

        $areasResult = $importAreas->execute(new CsvAddressAreaSource(
            database_path('seeders/data/malaysia-address-areas.csv'),
            self::MALAYSIA_AREA_SOURCE,
        ));

        if ($areasResult->hasFailures()) {
            throw new RuntimeException($this->formatFailureMessage($areasResult->failures));
        }

        $this->command->info(sprintf(
            'Addressing seeded: countries %d created / %d updated / %d skipped; Malaysia areas %d created / %d updated / %d skipped.',
            $countryResult['created'],
            $countryResult['updated'],
            $countryResult['skipped'],
            $areasResult->created,
            $areasResult->updated,
            $areasResult->skipped,
        ));
    }

    /**
     * @param  array<int, ImportAddressAreaFailureData>  $failures
     */
    private function formatFailureMessage(array $failures): string
    {
        $sample = collect($failures)
            ->take(5)
            ->map(fn (ImportAddressAreaFailureData $failure): string => sprintf(
                '%s: %s%s',
                $failure->sourceId,
                $failure->reason,
                $failure->name !== null ? " ({$failure->name})" : '',
            ))
            ->implode('; ');

        return sprintf('Malaysia address-area import failed with %d failure(s): %s', count($failures), $sample);
    }
}
