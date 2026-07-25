<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\CommerceSupport\Actions\SeedLanguagesAction;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        app(SeedLanguagesAction::class)->execute();
    }
}
