<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\State;
use Illuminate\Database\Seeder;
use Nnjeim\World\Models\Country;

class MalaysiaCitySeeder extends Seeder
{
    /**
     * Add missing Malaysian cities to the World package's cities table.
     */
    public function run(): void
    {
        $malaysia = Country::where('iso2', 'MY')->first();

        if (! $malaysia) {
            $this->command->warn('Malaysia country not found. Make sure WorldSeeder has been run.');

            return;
        }

        // Additional Malaysian cities/towns that may be missing
        $citiesByState = [
            'Selangor' => [
                'Gombak',
                'Kajang',
                'Bangi',
                'Cyberjaya',
                'Puchong',
                'Sungai Buloh',
                'Damansara',
                'Ara Damansara',
                'Kota Damansara',
                'Setia Alam',
                'Bukit Jelutong',
            ],
            'Johor' => [
                'Senai',
                'Pasir Gudang',
                'Tampoi',
                'Gelang Patah',
                'Nusajaya',
                'Bukit Indah',
            ],
            'Penang' => [
                'George Town',
                'Bayan Lepas',
                'Butterworth',
                'Bukit Mertajam',
                'Nibong Tebal',
                'Kepala Batas',
            ],
            'Perak' => [
                'Ipoh',
                'Taiping',
                'Teluk Intan',
                'Sitiawan',
                'Kampar',
                'Batu Gajah',
            ],
            'Kedah' => [
                'Alor Setar',
                'Sungai Petani',
                'Kulim',
                'Jitra',
            ],
            'Kelantan' => [
                'Kota Bharu',
                'Pasir Mas',
                'Tanah Merah',
                'Machang',
            ],
            'Terengganu' => [
                'Kuala Terengganu',
                'Kemaman',
                'Dungun',
                'Marang',
            ],
            'Pahang' => [
                'Kuantan',
                'Temerloh',
                'Bentong',
                'Raub',
                'Jerantut',
                'Cameron Highlands',
                'Genting Highlands',
            ],
            'Negeri Sembilan' => [
                'Seremban',
                'Nilai',
                'Port Dickson',
                'Senawang',
            ],
            'Malacca' => [
                'Melaka',
                'Ayer Keroh',
                'Alor Gajah',
                'Jasin',
            ],
            'Sabah' => [
                'Kota Kinabalu',
                'Sandakan',
                'Tawau',
                'Lahad Datu',
                'Keningau',
                'Semporna',
            ],
            'Sarawak' => [
                'Kuching',
                'Miri',
                'Sibu',
                'Bintulu',
                'Limbang',
            ],
            'Kuala Lumpur' => [
                'Kuala Lumpur',
                'Bangsar',
                'Cheras',
                'Kepong',
                'Setapak',
                'Wangsa Maju',
                'Bukit Bintang',
                'KLCC',
            ],
            'Putrajaya' => [
                'Putrajaya',
            ],
            'Labuan' => [
                'Victoria',
            ],
        ];

        $addedCount = 0;

        foreach ($citiesByState as $stateName => $cities) {
            $state = State::where('country_id', $malaysia->id)
                ->where('name', $stateName)
                ->first();

            if (! $state) {
                $this->command->warn("State not found: {$stateName}");

                continue;
            }

            foreach ($cities as $cityName) {
                // Check if city already exists
                $exists = City::where('state_id', $state->id)
                    ->where('name', $cityName)
                    ->exists();

                if (! $exists) {
                    City::create([
                        'country_id' => $malaysia->id,
                        'state_id' => $state->id,
                        'name' => $cityName,
                        'country_code' => 'MY',
                    ]);
                    $addedCount++;
                }
            }
        }

        $this->command->info("Added {$addedCount} new cities to Malaysia.");
    }
}
