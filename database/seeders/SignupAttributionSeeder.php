<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SignupAttribution;
use Illuminate\Database\Seeder;

class SignupAttributionSeeder extends Seeder
{
    public function run(): void
    {
        SignupAttribution::factory()->create();
    }
}
