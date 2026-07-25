<?php

namespace Database\Seeders;

use AIArmada\Persons\Enums\TitleUsagePosition;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleCategory;
use Illuminate\Database\Seeder;

class TitleSeeder extends Seeder
{
    public function run(): void
    {
        $academicCat = TitleCategory::where('code', 'academic')->first();
        $stateHonourCat = TitleCategory::where('code', 'state_honour')->first();
        $religiousCat = TitleCategory::where('code', 'religious')->first();
        $professionalCat = TitleCategory::where('code', 'professional')->first();

        // Leading pre-nominals (before_name, academic)
        Title::create(['category_id' => $academicCat->id, 'name' => 'Profesor', 'short_form' => 'Prof', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 10]);
        Title::create(['category_id' => $academicCat->id, 'name' => 'Profesor Madya', 'short_form' => 'Prof. Madya', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 11]);

        // Honorifics (before_name, state_honour)
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Tun', 'short_form' => 'Tun', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 20]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Toh Puan', 'short_form' => 'Toh Puan', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 21]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Tan Sri', 'short_form' => 'Tan Sri', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 30]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Puan Sri', 'short_form' => 'Puan Sri', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 31]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Seri Utama', 'short_form' => 'Datuk Seri Utama', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 40]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Patinggi', 'short_form' => 'Datuk Patinggi', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 50]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Amar', 'short_form' => 'Datuk Amar', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 60]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Seri Panglima', 'short_form' => 'Datuk Seri Panglima', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 70]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Seri', 'short_form' => 'Datuk Seri', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 80]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Dato\' Seri', 'short_form' => 'Dato\' Seri', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 80]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Paduka', 'short_form' => 'Datuk Paduka', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 80]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Datin Paduka', 'short_form' => 'Datin Paduka', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 80]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Datuk Wira', 'short_form' => 'Datuk Wira', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 90]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Dato\' Wira', 'short_form' => 'Dato\' Wira', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 90]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Dato\' Setia', 'short_form' => 'Dato\' Setia', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 90]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Datuk', 'short_form' => 'Datuk', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 100]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Dato\'', 'short_form' => 'Dato\'', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 100]);
        Title::create(['category_id' => $stateHonourCat->id, 'name' => 'Datin', 'short_form' => 'Datin', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 100]);

        // Trailing pre-nominals (before_name, religious)
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Syeikh', 'short_form' => 'Syeikh', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 110]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Syeikhul Maqari', 'short_form' => 'Syeikhul Maqari', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 111]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Maulana', 'short_form' => 'Maulana', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 112]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Habib', 'short_form' => 'Habib', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 113]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Tuan Guru', 'short_form' => 'Tuan Guru', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 114]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Pendeta', 'short_form' => 'Pendeta', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 115]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Ustaz', 'short_form' => 'Ustaz', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 116]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Ustazah', 'short_form' => 'Ustazah', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 117]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Imam Muda', 'short_form' => 'Imam Muda', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 118]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'PU', 'short_form' => 'PU', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 119]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Dai', 'short_form' => 'Dai', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 119]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Hafiz', 'short_form' => 'Hafiz', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 120]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Hafizah', 'short_form' => 'Hafizah', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 121]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Qari', 'short_form' => 'Qari', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 122]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Qariah', 'short_form' => 'Qariah', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 123]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Mufti', 'short_form' => 'Mufti', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 124]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Kadi', 'short_form' => 'Kadi', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 125]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Haji', 'short_form' => 'Hj.', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 140]);
        Title::create(['category_id' => $religiousCat->id, 'name' => 'Hajah', 'short_form' => 'Hjh.', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 141]);

        // Trailing pre-nominals (before_name, professional)
        Title::create(['category_id' => $professionalCat->id, 'name' => 'Ir.', 'short_form' => 'Ir.', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 130]);
        Title::create(['category_id' => $professionalCat->id, 'name' => 'Ar.', 'short_form' => 'Ar.', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 131]);

        // Trailing pre-nominals (before_name, academic)
        Title::create(['category_id' => $academicCat->id, 'name' => 'Dr.', 'short_form' => 'Dr.', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::BeforeName, 'sort_order' => 135]);

        // Post-nominals (after_name, academic)
        Title::create(['category_id' => $academicCat->id, 'name' => 'PhD', 'short_form' => 'PhD', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 10]);
        Title::create(['category_id' => $academicCat->id, 'name' => 'MSc', 'short_form' => 'MSc', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 20]);
        Title::create(['category_id' => $academicCat->id, 'name' => 'MA', 'short_form' => 'MA', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 21]);
        Title::create(['category_id' => $academicCat->id, 'name' => 'BSc', 'short_form' => 'BSc', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 30]);
        Title::create(['category_id' => $academicCat->id, 'name' => 'BA', 'short_form' => 'BA', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 31]);
        Title::create(['category_id' => $academicCat->id, 'name' => 'Lc', 'short_form' => 'Lc', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 32]);
        Title::create(['category_id' => $academicCat->id, 'name' => 'Hons', 'short_form' => 'Hons', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 33]);
        Title::create(['category_id' => $academicCat->id, 'name' => 'Diploma', 'short_form' => 'Dpl', 'country_id' => null, 'language_code' => 'ms', 'usage_position' => TitleUsagePosition::AfterName, 'sort_order' => 40]);
    }
}
