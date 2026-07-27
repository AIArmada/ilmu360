<?php

namespace Database\Seeders;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Persons\Enums\TitleUsagePosition;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleCategory;
use Illuminate\Database\Seeder;

class TitleSeeder extends Seeder
{
    private string $malaysiaCountryId;

    /** @var array<string, int> */
    private array $nextSortOrders = [];

    public function run(): void
    {
        $malaysiaCountryId = AddressCountry::query()
            ->where('iso2', 'MY')
            ->value('id');

        if (! is_string($malaysiaCountryId)) {
            throw new \RuntimeException('Malaysia must be seeded before application titles.');
        }

        $this->malaysiaCountryId = $malaysiaCountryId;
        $academicCat = TitleCategory::where('code', 'academic')->first();
        $stateHonourCat = TitleCategory::where('code', 'state_honour')->first();
        $religiousCat = TitleCategory::where('code', 'religious')->first();
        $professionalCat = TitleCategory::where('code', 'professional')->first();

        // Leading pre-nominals (before_name, academic)
        $this->createTitle(['category_id' => $academicCat->id, 'name' => 'Profesor', 'short_form' => 'Prof', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 10]);
        $this->createTitle(['category_id' => $academicCat->id, 'name' => 'Profesor Madya', 'short_form' => 'Prof. Madya', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 11]);

        // Honorifics (before_name, state_honour)
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Tun', 'short_form' => 'Tun', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 20]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Toh Puan', 'short_form' => 'Toh Puan', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 21]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Tan Sri', 'short_form' => 'Tan Sri', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 30]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Puan Sri', 'short_form' => 'Puan Sri', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 31]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Seri Utama', 'short_form' => 'Datuk Seri Utama', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 40]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Patinggi', 'short_form' => 'Datuk Patinggi', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 50]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Amar', 'short_form' => 'Datuk Amar', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 60]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Seri Panglima', 'short_form' => 'Datuk Seri Panglima', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 70]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Seri', 'short_form' => 'Datuk Seri', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 80]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Dato\' Seri', 'short_form' => 'Dato\' Seri', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 80]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Paduka', 'short_form' => 'Datuk Paduka', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 80]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Datin Paduka', 'short_form' => 'Datin Paduka', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 80]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Wira', 'short_form' => 'Datuk Wira', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 90]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Dato\' Wira', 'short_form' => 'Dato\' Wira', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 90]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Dato\' Setia', 'short_form' => 'Dato\' Setia', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 90]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Datuk', 'short_form' => 'Datuk', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 100]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Dato\'', 'short_form' => 'Dato\'', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 100]);
        $this->createTitle(['category_id' => $stateHonourCat->id, 'name' => 'Datin', 'short_form' => 'Datin', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 100]);

        // Trailing pre-nominals (before_name, religious)
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Syeikh', 'short_form' => 'Syeikh', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 110]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Syeikhul Maqari', 'short_form' => 'Syeikhul Maqari', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 111]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Maulana', 'short_form' => 'Maulana', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 112]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Habib', 'short_form' => 'Habib', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 113]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Tuan Guru', 'short_form' => 'Tuan Guru', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 114]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Pendeta', 'short_form' => 'Pendeta', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 115]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Ustaz', 'short_form' => 'Ustaz', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 116]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Ustazah', 'short_form' => 'Ustazah', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 117]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Imam Muda', 'short_form' => 'Imam Muda', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 118]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'PU', 'short_form' => 'PU', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 119]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Dai', 'short_form' => 'Dai', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 119]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Hafiz', 'short_form' => 'Hafiz', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 120]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Hafizah', 'short_form' => 'Hafizah', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 121]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Qari', 'short_form' => 'Qari', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 122]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Qariah', 'short_form' => 'Qariah', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 123]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Mufti', 'short_form' => 'Mufti', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 124]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Kadi', 'short_form' => 'Kadi', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 125]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Haji', 'short_form' => 'Hj.', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 140]);
        $this->createTitle(['category_id' => $religiousCat->id, 'name' => 'Hajah', 'short_form' => 'Hjh.', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 141]);

        // Trailing pre-nominals (before_name, professional)
        $this->createTitle(['category_id' => $professionalCat->id, 'name' => 'Ir.', 'short_form' => 'Ir.', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 130]);
        $this->createTitle(['category_id' => $professionalCat->id, 'name' => 'Ar.', 'short_form' => 'Ar.', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 131]);

        // Trailing pre-nominals (before_name, academic)
        $this->createTitle(['category_id' => $academicCat->id, 'name' => 'Dr.', 'short_form' => 'Dr.', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 135]);

        // Post-nominals (after_name, academic)
        $this->createTitle(['category_id' => $academicCat->id, 'name' => 'PhD', 'short_form' => 'PhD', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 10]);
        $this->createTitle(['category_id' => $academicCat->id, 'name' => 'MSc', 'short_form' => 'MSc', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 20]);
        $this->createTitle(['category_id' => $academicCat->id, 'name' => 'MA', 'short_form' => 'MA', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 21]);
        $this->createTitle(['category_id' => $academicCat->id, 'name' => 'BSc', 'short_form' => 'BSc', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 30]);
        $this->createTitle(['category_id' => $academicCat->id, 'name' => 'BA', 'short_form' => 'BA', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 31]);
        $this->createTitle(['category_id' => $academicCat->id, 'name' => 'Lc', 'short_form' => 'Lc', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 32]);
        $this->createTitle(['category_id' => $academicCat->id, 'name' => 'Hons', 'short_form' => 'Hons', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 33]);
        $this->createTitle(['category_id' => $academicCat->id, 'name' => 'Diploma', 'short_form' => 'Dpl', 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 40]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTitle(array $attributes): void
    {
        $attributes['country_id'] = $this->malaysiaCountryId;
        $usagePosition = $attributes['usage_position'];
        $usagePositionValue = $usagePosition instanceof TitleUsagePosition
            ? $usagePosition->value
            : (string) $usagePosition;
        $sortOrderKey = $attributes['category_id'].'|'.$usagePositionValue;
        $this->nextSortOrders[$sortOrderKey] = ($this->nextSortOrders[$sortOrderKey] ?? 0) + 10;
        $attributes['sort_order'] = $this->nextSortOrders[$sortOrderKey];

        Title::updateOrCreate(
            [
                'category_id' => $attributes['category_id'],
                'name' => $attributes['name'],
                'country_id' => $attributes['country_id'],
            ],
            $attributes,
        );
    }
}
