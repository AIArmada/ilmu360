<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\State;
use App\Support\Location\FederalTerritoryLocation;
use Illuminate\Database\Seeder;
use Nnjeim\World\Models\Country;

class DistrictSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get Malaysia country ID
        $malaysia = Country::where('iso2', 'MY')->first();

        if (! $malaysia) {
            $this->command->warn('Malaysia country not found. Make sure WorldSeeder has been run.');

            return;
        }

        $districtsByState = [
            'Johor' => ['Johor Bahru', 'Batu Pahat', 'Kluang', 'Kulai', 'Muar', 'Kota Tinggi', 'Segamat', 'Pontian', 'Tangkak', 'Mersing'],
            'Kedah' => ['Kuala Muda', 'Kota Setar', 'Kulim', 'Kubang Pasu', 'Baling', 'Pendang', 'Langkawi', 'Yan', 'Sik', 'Padang Terap', 'Pokok Sena', 'Bandar Baharu'],
            'Kelantan' => ['Kota Bharu', 'Pasir Mas', 'Tumpat', 'Bachok', 'Tanah Merah', 'Pasir Puteh', 'Kuala Krai', 'Machang', 'Gua Musang', 'Jeli', 'Lojing'],
            'Malacca' => ['Melaka Tengah', 'Alor Gajah', 'Jasin'],
            'Negeri Sembilan' => ['Seremban', 'Jempol', 'Port Dickson', 'Tampin', 'Kuala Pilah', 'Rembau', 'Jelebu'],
            'Pahang' => ['Kuantan', 'Temerloh', 'Bentong', 'Maran', 'Rompin', 'Pekan', 'Bera', 'Raub', 'Jerantut', 'Lipis', 'Cameron Highlands'],
            'Perak' => ['Kinta', 'Larut, Matang dan Selama', 'Manjung', 'Hilir Perak', 'Kerian', 'Batang Padang', 'Kuala Kangsar', 'Perak Tengah', 'Hulu Perak', 'Kampar', 'Muallim', 'Bagan Datuk'],
            'Perlis' => ['Perlis'],
            'Penang' => ['Timur Laut', 'Seberang Perai Tengah', 'Seberang Perai Utara', 'Barat Daya', 'Seberang Perai Selatan'],
            'Sabah' => ['Kota Kinabalu', 'Tawau', 'Sandakan', 'Lahad Datu', 'Keningau', 'Kinabatangan', 'Semporna', 'Papar', 'Penampang', 'Beluran', 'Tuaran', 'Ranau', 'Kota Belud', 'Kudat', 'Kota Marudu', 'Beaufort', 'Kunak', 'Tenom', 'Putatan', 'Pitas', 'Tambunan', 'Tongod', 'Sipitang', 'Nabawan', 'Kuala Penyu', 'Telupid', 'Kalabakan'],
            'Sarawak' => ['Kuching', 'Miri', 'Sibu', 'Bintulu', 'Serian', 'Kota Samarahan', 'Sri Aman', 'Marudi', 'Betong', 'Sarikei', 'Kapit', 'Bau', 'Limbang', 'Saratok', 'Mukah', 'Simunjan', 'Lawas', 'Belaga', 'Lundu', 'Asajaya', 'Daro', 'Tatau', 'Meradong', 'Kanowit', 'Lubok Antu', 'Selangau', 'Song', 'Dalat', 'Matu', 'Julau', 'Pakan', 'Tanjung Manis', 'Bukit Mabong', 'Telang Usan', 'Tebedu', 'Subis', 'Sebauh', 'Beluru', 'Kabong', 'Gedong', 'Siburan', 'Pantu', 'Lingga', 'Sebuyau'],
            'Selangor' => ['Petaling', 'Hulu Langat', 'Klang', 'Gombak', 'Kuala Langat', 'Sepang', 'Kuala Selangor', 'Hulu Selangor', 'Sabak Bernam'],
            'Terengganu' => ['Kuala Terengganu', 'Kemaman', 'Dungun', 'Besut', 'Marang', 'Hulu Terengganu', 'Setiu', 'Kuala Nerus'],
        ];

        foreach ($districtsByState as $stateName => $districts) {
            if (FederalTerritoryLocation::isFederalTerritoryStateName($stateName)) {
                continue;
            }

            $state = State::query()
                ->where('country_id', $malaysia->id)
                ->where('name', $stateName)
                ->first();

            if ($state === null) {
                $this->command->warn("State not found: {$stateName}");

                continue;
            }

            foreach ($districts as $districtName) {
                District::query()->updateOrCreate([
                    'state_id' => $state->id,
                    'name' => $districtName,
                ], [
                    'country_id' => $malaysia->id,
                    'country_code' => 'MY',
                ]);
            }
        }
    }
}
