<?php

declare(strict_types=1);

namespace Database\Seeders\AIArmada;

use Illuminate\Database\Seeder;

class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            EventRoleSeeder::class,
            EventTaxonomySeeder::class,
            EventTopicSeeder::class,
            EventSourceSeeder::class,
            EventDisciplineSeeder::class,
            EventIssueSeeder::class,
        ]);
    }
}
