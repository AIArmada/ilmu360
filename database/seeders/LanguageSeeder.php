<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\CommerceSupport\Database\Seeders\LanguageSeeder as CommerceLanguageSeeder;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([CommerceLanguageSeeder::class]);
    }
}
