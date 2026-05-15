<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\State;
use Illuminate\Database\Seeder;

class StateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $states = [
            ['name' => 'Johor', 'slug' => 'johor'],
            ['name' => 'Kedah', 'slug' => 'kedah'],
            ['name' => 'Kelantan', 'slug' => 'kelantan'],
            ['name' => 'Melaka', 'slug' => 'melaka'],
            ['name' => 'Negeri Sembilan', 'slug' => 'negeri-sembilan'],
            ['name' => 'Pahang', 'slug' => 'pahang'],
            ['name' => 'Perak', 'slug' => 'perak'],
            ['name' => 'Perlis', 'slug' => 'perlis'],
            ['name' => 'Pulau Pinang', 'slug' => 'pulau-pinang'],
            ['name' => 'Sabah', 'slug' => 'sabah'],
            ['name' => 'Sarawak', 'slug' => 'sarawak'],
            ['name' => 'Selangor', 'slug' => 'selangor'],
            ['name' => 'Terengganu', 'slug' => 'terengganu'],
            ['name' => 'Wilayah Persekutuan Kuala Lumpur', 'slug' => 'wp-kuala-lumpur'],
            ['name' => 'Wilayah Persekutuan Putrajaya', 'slug' => 'wp-putrajaya'],
            ['name' => 'Wilayah Persekutuan Labuan', 'slug' => 'wp-labuan'],
        ];

        foreach ($states as $state) {
            State::query()->updateOrCreate(['slug' => $state['slug']], $state);
        }
    }
}
