<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\State;
use App\Models\Subdistrict;
use App\Support\Location\FederalTerritoryLocation;
use Illuminate\Database\Seeder;
use Nnjeim\World\Models\Country;

class SubdistrictSeeder extends Seeder
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

        $subdistrictsByDistrict = $this->getSubdistrictsData();

        foreach ($subdistrictsByDistrict as $districtOrStateName => $subdistricts) {
            $state = State::query()
                ->where('country_id', $malaysia->id)
                ->where('name', $districtOrStateName)
                ->first();

            if ($state instanceof State && FederalTerritoryLocation::isFederalTerritoryStateId($state->getKey())) {
                foreach ($subdistricts as $subdistrictName) {
                    Subdistrict::query()->updateOrCreate([
                        'state_id' => $state->getKey(),
                        'district_id' => null,
                        'name' => $subdistrictName,
                    ], [
                        'country_id' => $malaysia->id,
                        'country_code' => 'MY',
                    ]);
                }

                continue;
            }

            $district = District::query()
                ->where('country_id', $malaysia->id)
                ->where('name', $districtOrStateName)
                ->first();

            if ($district === null) {
                $this->command->warn("District not found: {$districtOrStateName}");

                continue;
            }

            foreach ($subdistricts as $subdistrictName) {
                Subdistrict::query()->updateOrCreate([
                    'district_id' => $district->id,
                    'name' => $subdistrictName,
                ], [
                    'country_id' => $malaysia->id,
                    'state_id' => $district->state_id,
                    'country_code' => 'MY',
                ]);
            }
        }
    }

    /**
     * Get subdistricts data organized by district.
     *
     * @return array<string, array<string>>
     */
    private function getSubdistrictsData(): array
    {
        return [
            'Johor Bahru' => ['Bandar Johor Bahru', 'Bandar Tiram', 'Divisyen Bandaraya', 'Gelang Patah', 'Iskandar Puteri', 'Jelutong', 'Johor Bahru', 'Masai', 'Pasir Gudang', 'Plentong', 'Pulai', 'Sungai Tiram', 'Tanjung Kupang', 'Tebrau', 'Ulu Choh', 'Ulu Tiram'],
            'Batu Pahat' => ['Ayer Hitam', 'Bagan', 'Bandar Penggaram', 'Batu Pahat', 'Chaah Bahru', 'Kampung Bahru', 'Linau', 'Lubok', 'Minyak Beku', 'Parit Raja', 'Parit Sulong', 'Peserai', 'Rengit', 'Semerah', 'Senggarang', 'Seri Gading', 'Seri Medan', 'Simpang Kanan', 'Simpang Kiri', 'Sri Gading', 'Sri Medan', 'Sungai Kluang', 'Sungai Punggor', 'Tanjung Sembrong', 'Yong Peng'],
            'Kluang' => ['Bandar Kluang', 'Chaah', 'Kahang', 'Kluang', 'Layang-Layang', 'Machap', 'Niyor', 'Paloh', 'Rengam', 'Renggam', 'Simpang Rengam', 'Ulu Benut'],
            'Kulai' => ['Bandar Kulai', 'Bandar Tenggara', 'Bukit Batu', 'Gugusan Taib Andak', 'Kulai', 'Sedenak', 'Senai'],
            'Muar' => ['Ayer Hitam', 'Bandar Maharani', 'Bukit Gambir', 'Bukit Kepong', 'Bukit Pasir', 'Jalan Bakri', 'Jorak', 'Lenga', 'Muar', 'Pagoh', 'Panchor', 'Parit Bakar', 'Parit Jawa', 'Sri Menanti', 'Sungai Balang', 'Sungai Mati', 'Sungai Raya', 'Sungai Terap'],
            'Kota Tinggi' => ['Bandar Kota Tinggi', 'Bandar Penawar', 'Johor Lama', 'Kambau', 'Kota Tinggi', 'Pantai Timur', 'Pengerang', 'Sedili Besar', 'Sedili Kechil', 'Tanjung Surat', 'Ulu Sungai Johor', 'Ulu Sungei Sedili Besar'],
            'Segamat' => ['Bandar Segamat', 'Batu Anam', 'Bekok', 'Buloh Kasap', 'Chaah', 'Gemas', 'Gemereh', 'Jabi', 'Jementah', 'Labis', 'Pogoh', 'Segamat', 'Sermin', 'Sungai Segamat'],
            'Pontian' => ['Air Masin', 'Api-Api', 'Ayer Baloi', 'Bandar Pontian', 'Benut', 'Jeram Batu', 'Kukup', 'Pekan Nenas', 'Pengkalan Raja', 'Pontian', 'Rimba Terjun', 'Serkat', 'Sungai Karang', 'Sungei Pinggan'],
            'Tangkak' => ['Bandar Tangkak', 'Bukit Serampang', 'Gerisek', 'Grisek', 'Kesang', 'Kundang', 'Serom', 'Tangkak'],
            'Mersing' => ['Ayer Tawar 2', 'Bandar Mersing', 'Endau', 'Jemaluang', 'Lenggor', 'Mersing', 'Padang Endau', 'Penyabong', 'Pulau Aur', 'Pulau Babi', 'Pulau Pemanggil', 'Pulau Satu', 'Pulau Sibu', 'Pulau Tinggi', 'Sembrong', 'Tenggaroh', 'Tenglu', 'Triang'],
            'Kuala Muda' => ['Bandar Sungai Petani', 'Bedong', 'Bujang', 'Bukit Meriam', 'Gurun', 'Haji Kudong', 'Kota', 'Kota Kuala Muda', 'Kuala', 'Merbok', 'Pekula', 'Pinang Tunggal', 'Rantau Panjang', 'Semeling', 'Sidam Kiri', 'Simpor', 'Sungai Pasir', 'Sungai Petani'],
            'Kota Setar' => ['Alor Malai', 'Alor Setar', 'Anak Bukit', 'Bandar Alor Setar', 'Derga', 'Gunong', 'Kangkong', 'Kepala Batas', 'Kodiang', 'Kota Sarang Semut', 'Kota Setar', 'Kuala Kedah', 'Kubang Rotan', 'Langgar', 'Lengkuas', 'Lepai', 'Limbong', 'Padang Hang', 'Padang Lalang', 'Pengkalan Kundor', 'Sala Kechik', 'Simpang Empat', 'Sungai Baharu', 'Tajar', 'Tebengau', 'Telaga Mas', 'Titi Gajah'],
            'Kulim' => ['Bagan Sena', 'Bandar Kulim', 'Junjong', 'Karangan', 'Kulim', 'Lunas', 'Mahang', 'Naga Lilit', 'Padang China', 'Padang Meha', 'Padang Serai', 'Sedim', 'Sidam Kanan', 'Sungai Seluang', 'Sungai Ular', 'Terap'],
            'Kubang Pasu' => ['Ah', 'Ayer Hitam', 'Bandar Darulaman', 'Bandar Jitra', 'Binjal', 'Bukit Kayu Hitam', 'Bukit Tinggi', 'Changloon', 'Gelong', 'Husba', 'Jeram', 'Jerlun', 'Jitra', 'Kepelu', 'Kubang Pasu', 'Malau', 'Naga', 'Padang Perahu', 'Pelubang', 'Pering', 'Putat', 'Sanglang', 'Sungai Laka', 'Temin', 'Tunjang', 'Universiti Utara Malaysia', 'Wang Tepus'],
            'Baling' => ['Bakai', 'Baling', 'Bandar Baling', 'Bongor', 'Kuala Ketil', 'Kuala Pegang', 'Kupang', 'Pulai', 'Siong', 'Tawar', 'Teloi Kanan'],
            'Pendang' => ['Ayer Puteh', 'Bandar Pendang', 'Bukit Paya', 'Guar Kepayang', 'Padang Kerbau', 'Padang Peliang', 'Padang Pusing', 'Pendang', 'Rambai', 'Tobiar'],
            'Langkawi' => ['Ayer Hangat', 'Bohor', 'Kedawang', 'Kuah', 'Langkawi', 'Padang Mat Sirat', 'Ulu Melaka'],
            'Yan' => ['Dulang', 'Guar Chempedak', 'Sala Besar', 'Singkir', 'Sungai Daun', 'Yan', 'Yan Kechil'],
            'Sik' => ['Jeniang', 'Jeneri', 'Pekan Sik', 'Sik', 'Sok'],
            'Padang Terap' => ['Batang Tunggang Kanan', 'Batang Tunggang Kiri', 'Belimbing Kanan', 'Belimbing Kiri', 'Kuala Nerang', 'Kurong Hitam', 'Padang Temak', 'Padang Terap Kanan', 'Padang Terap Kiri', 'Pedu', 'Tekai', 'Tolak'],
            'Pokok Sena' => ['Bukit Lada', 'Derang', 'Gajah Mati', 'Jabi', 'Lesong', 'Pekan Pokok Sena', 'Pokok Sena', 'Tualang'],
            'Bandar Baharu' => ['Bagan Samak', 'Bandar Baharu', 'Kuala Selama', 'Permatang Pasir', 'Relau', 'Serdang', 'Sungai Batu', 'Sungai Kechil'],
            'Kota Bharu' => ['Badang', 'Banggu', 'Bandar Baru Tunjong', 'Bandar Kota Bharu', 'Baung', 'Bayang', 'Beta Hilir', 'Beta Hulu', 'Beting', 'Bunut Payong', 'Demit', 'Guntong', 'Kadok', 'Kem Desa Pahlawan', 'Kemumin', 'Ketereh', 'Kijang', 'Kota', 'Kota Bharu', 'Kubang Kerian', 'Langgar', 'Lundang', 'Mentuan', 'Mulong', 'Panji', 'Pendek', 'Pengkalan Chepa', 'Peringat', 'Perol', 'Pulau Kundor', 'Salor', 'Sering', 'Tiong', 'Wakaf Stan'],
            'Pasir Mas' => ['Alor Buloh', 'Alor Pasir', 'Apam', 'Bakong', 'Bandar Pasir Mas', 'Bechah Menerong', 'Bechah Palas', 'Bechah Semak', 'Bukit Tuku', 'Chetok', 'Gelam', 'Gua', 'Gual Nering', 'Gual Periok', 'Jabo', 'Jejawi', 'Kala', 'Kangkong', 'Kasa', 'Kedondong', 'Kenak', 'Kerasak', 'Kiat', 'Kuala Lemal', 'Kubang Batang', 'Kubang Bemban', 'Kubang Gatal', 'Kubang Gendang', 'Kubang Ketam', 'Kubang Sepat', 'Kubang Terap', 'Lalang', 'Lubok Anching', 'Lubok Gong', 'Lubok Kawah', 'Lubok Setol', 'Lubok Tapah', 'Meranti', 'Padang Embon', 'Paloh', 'Pasir Mas', 'Rantau Panjang', 'Sakar', 'Tasik Berangan', 'Teliar', 'Tendong', 'Tok Sangkot', 'Tok Uban'],
            'Tumpat' => ['Bandar Tumpat', 'Bechah Resak', 'Bunohan', 'Bunut Sarang Burong', 'Chenderong Batu', 'Cherang Melintang', 'Geting', 'Jal', 'Kampong Laut', 'Kelaboran', 'Ketil', 'Kok Keli', 'Kutang', 'Mak Neralang', 'Morak', 'Palekbang', 'Pasir Pekan', 'Periok', 'Pulau Besar', 'Selehong Selatan', 'Selehong Utara', 'Simpangan', 'Sungai Pinang', 'Tabar', 'Talak', 'Telok Renjuna', 'Tujoh', 'Tumpat', 'Wakaf Bharu'],
            'Bachok' => ['Alor Bakat', 'Bachok', 'Bandar Bachok', 'Bator', 'Chap', 'Cherang Hangus', 'Cherang Ruku', 'Gunong', 'Kemasin', 'Kuau', 'Kubang Telaga', 'Lubok Tembesu', 'Mak Lipah', 'Melawi', 'Melor', 'Nipah', 'Pak Pura', 'Pauh Sembilan', 'Perupok', 'Repek', 'Rusa', 'Senak', 'Serdang', 'Takang', 'Tanjong', 'Telok Mesira', 'Telong', 'Tepus'],
            'Tanah Merah' => ['Bandar Tanah Merah', 'Batang Merbau', 'Bendang Nyior', 'Bukit Durian', 'Jedok', 'Kuala Paku', 'Lawang', 'Maka', 'Nibong', 'Pasir Ganda', 'Rambai', 'Sokor', 'Tanah Merah', 'Tebing Tinggi', 'Ulu Kusial'],
            'Pasir Puteh' => ['Bandar Pasir Puteh', 'Banggol Setol', 'Batu Sebutir', 'Berangan', 'Bukit Abal Barat', 'Bukit Abal Timor', 'Bukit Merbau', 'Bukit Tanah', 'Changgai', 'Cherang Ruku', 'Gong Chengal', 'Gong Datok Barat', 'Gong Datok Timor', 'Gong Garu', 'Gong Kulim', 'Gong Nangka', 'Jeram', 'Jerus', 'Kampong Wakaf', 'Kandis', 'Kolam Tembesu', 'Merbol', 'Merkang', 'Padang Pak Amat', 'Pasir Puteh', 'Permatang Sungkai', 'Seligi', 'Selising', 'Semerak', 'Tasik', 'Telipok'],
            'Kuala Krai' => ['Bandar Kuala Krai', 'Batu Mengkebang', 'Dabong', 'Enggong', 'Gajah', 'Kandek', 'Kenor', 'Kuala Geris', 'Kuala Krai', 'Kuala Nal', 'Kuala Pahi', 'Kuala Pergau', 'Kuala Stong', 'Mambong', 'Manek Urai', 'Manjor', 'Olak Jeram', 'Telekong'],
            'Machang' => ['Bagan', 'Bakar', 'Bandar Machang', 'Dewan', 'Gading Galoh', 'Jakar', 'Joh', 'Kelaweh', 'Kerawang', 'Kerilla', 'Kuala Kerak', 'Labok', 'Limau Hantu', 'Machang', 'Padang Kemunchut', 'Pek', 'Pemanok', 'Pulai Chondong', 'Raja', 'Temangan', 'Tengah', 'Tok Bok', 'Ulu Sat'],
            'Gua Musang' => ['Bandar Gua Musang', 'Batu Papan', 'Chiku', 'Dabong', 'Galas', 'Gua Musang', 'Ketil', 'Kuala Sungai', 'Limau Kasturi', 'Pulai', 'Relai', 'Renok', 'Ulu Nenggiri'],
            'Jeli' => ['Ayer Lanas', 'Bandar Jeli', 'Belimbing', 'Bunga Tanjong', 'Jeli', 'Jeli Tepi Sungai', 'Kalai', 'Kuala Balah', 'Lubok Bongor'],
            'Lojing' => ['Betis', 'Blau', 'Hau', 'Hendrop', 'Kuala Betis', 'Tuel'],
            'Melaka Tengah' => ['Ayer Keroh', 'Alai', 'Ayer Molek', 'Bachang', 'Balai Panjang', 'Bandaraya Melaka', 'Batu Berendam', 'Bertam', 'Bukit Baru', 'Bukit Katil', 'Bukit Lintang', 'Bukit Piatu', 'Bukit Rambai', 'Cheng', 'Duyong', 'Kandang', 'Klebang Besar', 'Klebang Kechil', 'Krubong', 'Melaka', 'Padang Temu', 'Paya Rumput', 'Pernu', 'Pringgit', 'Semabok', 'Sungai Udang', 'Sungei Udang', 'Tangga Batu', 'Tanjong Kling', 'Tanjong Minyak', 'Telok Mas', 'Ujong Pasir'],
            'Alor Gajah' => ['Alor Gajah', 'Asahan', 'Ayer Pa\'abas', 'Bandar Alor Gajah', 'Belimbing', 'Beringin', 'Brisu', 'Durian Tunggal', 'Gadek', 'Kelemak', 'Kemuning', 'Kuala Linggi', 'Kuala Sungai Baru', 'Kuala Sungei Baru', 'Lendu', 'Machap', 'Malaka Pindah', 'Masjid Tanah', 'Melekek', 'Padang Sebang', 'Parit Melana', 'Pegoh', 'Pulau Sebang', 'Ramuan China Besar', 'Ramuan China Kechil', 'Rembia', 'Sungei Baru Ilir', 'Sungei Baru Tengah', 'Sungei Baru Ulu', 'Sungei Buloh', 'Sungei Petai', 'Sungei Siput', 'Taboh Naning', 'Tanjong Rimau', 'Tebong'],
            'Jasin' => ['Ayer Keroh', 'Ayer Panas', 'Asahan', 'Bandar Jasin', 'Batang Malaka', 'Bemban', 'Bukit Senggeh', 'Chabau', 'Chin Chin', 'Chohong', 'Jasin', 'Jus', 'Kesang', 'Merlimau', 'Nyalas', 'Rim', 'Sebatu', 'Selandar', 'Sempang', 'Semujok', 'Serkam', 'Sungei Rambai', 'Tedong', 'Umbai'],
            'Seremban' => ['Ampangan', 'Bandar Enstek', 'Bandar Seremban', 'Labu', 'Lenggeng', 'Mantin', 'Nilai', 'Pantai', 'Rantau', 'Rasah', 'Senawang', 'Seremban', 'Seremban 2', 'Setul'],
            'Jempol' => ['Bahau', 'Bandar Seri Jempol', 'Batu Kikir', 'Jelai', 'Kuala Jempol', 'Pusat Bandar Palong', 'Rompin', 'Serting Hilir', 'Serting Ulu'],
            'Port Dickson' => ['Jimah', 'Linggi', 'Lukut', 'Pasir Panjang', 'Port Dickson', 'Si Rusa'],
            'Tampin' => ['Air Kuning', 'Gemas', 'Gemencheh', 'Keru', 'Repah', 'Tampin', 'Tampin Tengah', 'Tebong'],
            'Kuala Pilah' => ['Ampang Tinggi', 'Johol', 'Juasseh', 'Kepis', 'Kuala Pilah', 'Langkap', 'Parit Tinggi', 'Pilah', 'Sri Menanti', 'Terachi', 'Ulu Jempol', 'Ulu Muar'],
            'Rembau' => ['Batu Hampar', 'Bongek', 'Chembong', 'Chengkau', 'Gadong', 'Kota', 'Kundor', 'Legong Ilir', 'Legong Ulu', 'Miku', 'Nerasau', 'Pedas', 'Pilin', 'Rembau', 'Selemak', 'Semerbok', 'Spri', 'Tanjong Kling', 'Titian Bintangor'],
            'Jelebu' => ['Glami Lemi', 'Kenaboi', 'Kuala Klawang', 'Peradong', 'Pertang', 'Simpang Durian', 'Simpang Pertang', 'Tanjong Ipoh', 'Triang Ilir', 'Ulu Klawang', 'Ulu Triang'],
            'Kuantan' => ['Balok', 'Bandar Kuantan', 'Beserah', 'Bukit Goh', 'Bukit Kuin', 'Gambang', 'Gebeng', 'Hulu Kuantan', 'Hulu Lepar', 'Kuala Kuantan', 'Kuantan', 'Penor', 'Sungai Karang', 'Sungai Lembing'],
            'Temerloh' => ['Bangau', 'Jenderak', 'Kerdau', 'Lanchang', 'Lebak', 'Lipat Kajang', 'Mentakab', 'Perak', 'Sanggang', 'Semantan', 'Songsang', 'Temerloh'],
            'Bentong' => ['Bentong', 'Genting Highlands', 'Karak', 'Pelangai', 'Sabai'],
            'Maran' => ['Bandar Tun Abdul Razak', 'Bukit Segumpal', 'Chenor', 'Kertau', 'Luit', 'Lurah Bilut', 'Maran'],
            'Rompin' => ['Endau', 'Keratong', 'Kuala Rompin', 'Muadzam Shah', 'Pontian', 'Rompin', 'Tioman'],
            'Pekan' => ['Bebar', 'Chini', 'Ganchong', 'Kuala Pahang', 'Langgar', 'Lepar', 'Pahang Tua', 'Pekan', 'Penyor', 'Pulau Manis', 'Pulau Rusa', 'Temai'],
            'Bera' => ['Bandar Bera', 'Bera', 'Kemayan', 'Triang'],
            'Raub' => ['Batu Talam', 'Bukit Fraser', 'Dong', 'Gali', 'Hulu Dong', 'Raub', 'Sega', 'Semantan Hulu', 'Teras'],
            'Jerantut' => ['Bandar Pusat Jengka', 'Burau', 'Damak', 'Hulu Cheka', 'Hulu Tembeling', 'Jerantut', 'Kelola', 'Kuala Krau', 'Kuala Tembeling', 'Pedah', 'Pulau Tawar', 'Tebing Tinggi', 'Teh', 'Tembeling'],
            'Lipis' => ['Batu Yon', 'Benta', 'Budu', 'Cheka', 'Dong', 'Gua', 'Hulu Jelai', 'Kechau', 'Kuala Lipis', 'Padang Tengku', 'Penjom', 'Sega', 'Sungai Koyan', 'Tanjung Besar', 'Telang'],
            'Cameron Highlands' => ['Brinchang', 'Hulu Telom', 'Ringlet', 'Tanah Rata'],
            'Kinta' => ['Batu Gajah', 'Belanja', 'Chemor', 'Hulu Kinta', 'Ipoh', 'Kampung Kepayang', 'Kampar', 'Lahat', 'Pusing', 'Sungai Raia', 'Sungai Terap', 'Tambun', 'Tanjong Rambutan', 'Tanjong Tualang', 'Teja', 'Ulu Kinta'],
            'Larut, Matang dan Selama' => ['Asam Kumbang', 'Batu Kurau', 'Bukit Gantang', 'Jebong', 'Kamunting', 'Kuala Sepetang', 'Matang', 'Pengkalan Aor', 'Selama', 'Simpang', 'Sungai Limau', 'Sungai Tinggi', 'Taiping', 'Terong', 'Trong', 'Tupai', 'Ulu Ijok', 'Ulu Selama'],
            'Manjung' => ['Ayer Tawar', 'Beruas', 'Bruas', 'Changkat Keruing', 'Lekir', 'Lumut', 'Pangkor', 'Pantai Remis', 'Pengkalan Baharu', 'Seri Manjung', 'Sitiawan', 'TLDM Lumut'],
            'Hilir Perak' => ['Changkat Jong', 'Chikus', 'Durian Sebatang', 'Labu Kubong', 'Langkap', 'Sungai Durian', 'Sungai Manik', 'Sungai Sumun', 'Teluk Intan'],
            'Kerian' => ['Bagan Serai', 'Bagan Tiang', 'Batu Kurau', 'Beriah', 'Gunong Semanggol', 'Kuala Kurau', 'Parit Buntar', 'Selinsing', 'Simpang Ampat Semanggol', 'Tanjong Piandang'],
            'Batang Padang' => ['Batang Padang', 'Behrang Stesen', 'Bidor', 'Changkat Jering', 'Chenderiang', 'Slim', 'Slim River', 'Sungkai', 'Tapah', 'Tapah Road', 'Temoh', 'Trolak'],
            'Kuala Kangsar' => ['Chegar Galah', 'Enggor', 'Jeram', 'Kampung Buaya', 'Kota Lama Kanan', 'Kota Lama Kiri', 'Kuala Kangsar', 'Lubok Merbau', 'Manong', 'Padang Rengas', 'Pulau Kamiri', 'Saiong', 'Sauk', 'Senggang', 'Sungai Siput'],
            'Perak Tengah' => ['Bandar', 'Bandar Seri Iskandar', 'Belanja', 'Bota', 'Jaya Baharu', 'Kampong Gajah', 'Kota Setia', 'Lambor Kanan', 'Lambor Kiri', 'Layang-Layang', 'Parit', 'Pasir Panjang Ulu', 'Pasir Salak', 'Pulau Tiga', 'Seri Iskandar'],
            'Hulu Perak' => ['Belukar Semang', 'Belum', 'Durian Pipit', 'Gerik', 'Intan', 'Kenering', 'Kerunai', 'Lenggong', 'Pengkalan Hulu'],
            'Kampar' => ['Gopeng', 'Kampar', 'Malim Nawar', 'Teja', 'Tronoh'],
            'Muallim' => ['Hulu Bernam Timor', 'Slim', 'Slim River', 'Tanjong Malim', 'Ulu Bernam', 'Ulu Bernam Barat'],
            'Bagan Datuk' => ['Bagan Datoh', 'Bagan Datuk', 'Hutan Melintang', 'Rantau Panjang', 'Rungkup', 'Selekoh', 'Teluk Bharu'],

            // Perlis
            'Perlis' => ['Abi', 'Arau', 'Beseri', 'Chuping', 'Jejawi', 'Kaki Bukit', 'Kangar', 'Kayang', 'Kechor', 'Kuala Perlis', 'Kurong Anai', 'Kurong Batang', 'Ngulang', 'Oran', 'Padang Besar', 'Padang Pauh', 'Padang Siding', 'Paya', 'Sanglang', 'Sena', 'Seriab', 'Simpang Ampat', 'Sungai Adam', 'Titi Tinggi', 'Utan Aji', 'Wang Bintong'],

            // Penang
            'Timur Laut' => ['Air Itam', 'Bandar George Town', 'Batu Ferringhi', 'Gelugor', 'Jelutong', 'Mukim 13', 'Mukim 14', 'Mukim 15', 'Mukim 16', 'Mukim 17', 'Mukim 18', 'Penang Hill', 'Pulau Pinang', 'Tanjong Bungah', 'USM Pulau Pinang'],
            'Seberang Perai Tengah' => ['Bandar Bukit Mertajam', 'Bukit Mertajam', 'Mukim 1', 'Mukim 10', 'Mukim 11', 'Mukim 12', 'Mukim 13', 'Mukim 14', 'Mukim 15', 'Mukim 16', 'Mukim 17', 'Mukim 18', 'Mukim 19', 'Mukim 2', 'Mukim 20', 'Mukim 21', 'Mukim 3', 'Mukim 4', 'Mukim 5', 'Mukim 6', 'Mukim 7', 'Mukim 8', 'Mukim 9', 'Perai', 'Permatang Pauh', 'Seberang Jaya'],
            'Seberang Perai Utara' => ['Bandar Butterworth', 'Butterworth', 'Kepala Batas', 'Kubang Semang', 'Mukim 1', 'Mukim 10', 'Mukim 11', 'Mukim 12', 'Mukim 13', 'Mukim 14', 'Mukim 15', 'Mukim 16', 'Mukim 2', 'Mukim 3', 'Mukim 4', 'Mukim 5', 'Mukim 6', 'Mukim 7', 'Mukim 8', 'Mukim 9', 'Penaga', 'Tasek Gelugor'],
            'Barat Daya' => ['Balik Pulau', 'Batu Maung', 'Bayan Lepas', 'Mukim 1', 'Mukim 10', 'Mukim 11', 'Mukim 12', 'Mukim 2', 'Mukim 3', 'Mukim 4', 'Mukim 5', 'Mukim 6', 'Mukim 7', 'Mukim 8', 'Mukim 9', 'Mukim A', 'Mukim B', 'Mukim C', 'Mukim D', 'Mukim E', 'Mukim F', 'Mukim G', 'Mukim H', 'Mukim I', 'Mukim J', 'Teluk Kumbar'],
            'Seberang Perai Selatan' => ['Mukim 1', 'Mukim 10', 'Mukim 11', 'Mukim 12', 'Mukim 13', 'Mukim 14', 'Mukim 15', 'Mukim 16', 'Mukim 2', 'Mukim 3', 'Mukim 4', 'Mukim 5', 'Mukim 6', 'Mukim 7', 'Mukim 8', 'Mukim 9', 'Nibong Tebal', 'Simpang Ampat', 'Sungai Jawi'],

            // Sabah
            'Kota Kinabalu' => ['Bandaraya Kota Kinabalu', 'Inanam', 'Kota Kinabalu', 'Likas', 'Luyang', 'Menggatal', 'Sembulan', 'Tanjung Aru', 'Telipok'],
            'Tawau' => ['Apas', 'Balung', 'Bandar Tawau', 'Merotai', 'Sri Tanjung', 'Tawau'],
            'Sandakan' => ['Bandar Sandakan', 'Elopura', 'Gum Gum', 'Libaran', 'Sandakan', 'Sekong', 'Sungai Manila', 'Tanjong Papat'],
            'Lahad Datu' => ['Bandar Lahad Datu', 'Cenderawasih', 'Lahad Datu', 'Segama', 'Silabukan', 'Tungku'],
            'Keningau' => ['Apin-Apin', 'Bandar Keningau', 'Bingkor', 'Dalit', 'Keningau', 'Liawan', 'Nabawan', 'Sook', 'Tambunan'],
            'Kinabatangan' => ['Bukit Garam', 'Kota Kinabatangan', 'Lamag', 'Paris', 'Pekan Kinabatangan', 'Sukau', 'Tomanggong'],
            'Semporna' => ['Bubul', 'Bum-Bum Island', 'Pegagau', 'Pekan Semporna', 'Semporna'],
            'Papar' => ['Benoni', 'Bongawan', 'Kawang', 'Kimanis', 'Kinarut', 'Mandahan', 'Papar', 'Pekan Papar', 'Pengalat'],
            'Penampang' => ['Beverly', 'Kepayan', 'Pekan Donggongon', 'Penampang'],
            'Beluran' => ['Beluran', 'Jambongan', 'Klagan', 'Kolapis', 'Lingkabau', 'Nangoh', 'Paitan', 'Pamol', 'Pekan Beluran', 'Sapi'],
            'Tuaran' => ['Kiulu', 'Pekan Tuaran', 'Sulaman', 'Tamparuli', 'Tenghilan', 'Tuaran'],
            'Ranau' => ['Karanaan', 'Kundasang', 'Lohan/Bongkud', 'Malinsau', 'Pekan Ranau', 'Perancangan', 'Ranau', 'Tambiau', 'Timbua'],
            'Kota Belud' => ['Kadamaian', 'Kota Belud', 'Kuala Abai', 'Pekan Kota Belud', 'Rosok', 'Siasai', 'Taun Gusi', 'Tempasuk', 'Usukan'],
            'Kudat' => ['Banggi', 'Kudat', 'Matunggong', 'Pekan Kudat', 'Pitas', 'Tandek'],
            'Kota Marudu' => ['Kota Marudu', 'Mangaris', 'Pekan Kota Marudu', 'Tandek'],
            'Beaufort' => ['Bandar Beaufort', 'Beaufort', 'Gadong', 'Klias', 'Limbawang', 'Lumadan', 'Membakut', 'Weston'],
            'Kunak' => ['Kunak', 'Madai', 'Pangi', 'Pekan Kunak'],
            'Tenom' => ['Kemabong', 'Melalap', 'Pekan Tenom', 'Tenom', 'Tomani'],
            'Putatan' => ['Pekan Putatan', 'Petagas', 'Putatan'],
            'Pitas' => ['Kalumpang', 'Pekan Pitas', 'Telaga'],
            'Tambunan' => ['Kirokot', 'Pekan Tambunan', 'Sunsuron', 'Tambunan'],
            'Tongod' => ['Entilibon', 'Kuamut', 'Pekan Tongod'],
            'Sipitang' => ['Long Pasia', 'Lumadan', 'Mengalong', 'Mesapol', 'Pekan Sipitang', 'Sindumin', 'Sipitang'],
            'Nabawan' => ['Nabawan', 'Pagalungan', 'Pekan Nabawan', 'Pensiangan', 'Sepulut'],
            'Kuala Penyu' => ['Bundu', 'Kerukan', 'Kuala Penyu', 'Mantabawan', 'Menumbok', 'Pekan Kuala Penyu', 'Sitompok'],
            'Telupid' => ['Pekan Telupid', 'Telupid', 'Tongod'],
            'Kalabakan' => ['Kalabakan', 'Luasong', 'Wallace Bay'],

            // Sarawak
            'Kuching' => ['Batu Kawa', 'Kuching', 'Matang', 'Padawan', 'Santubong', 'Semariang'],
            'Miri' => ['Bakam', 'Bekenu', 'Lambir', 'Lutong', 'Miri', 'Niah', 'Pusat Mel Miri'],
            'Sibu' => ['Kemuyang', 'Pasai Siong', 'Pulau Babi', 'Sibu', 'Sibu Jaya', 'Sungai Merah'],
            'Bintulu' => ['Bintulu', 'Jepak', 'Kemena', 'Kidurong', 'Sebauh', 'Tatau'],
            'Serian' => ['Balai Ringin', 'Serian', 'Tapah', 'Tebakang'],
            'Kota Samarahan' => ['Kota Samarahan', 'Moyan', 'Muara Tuang', 'Tambirat'],
            'Sri Aman' => ['Batu Lintang', 'Lingga', 'Sri Aman', 'Undop'],
            'Marudi' => ['Baram', 'Long Lama', 'Long Teru', 'Marudi', 'Mulu', 'Poyut/Nibong'],
            'Betong' => ['Betong', 'Debak', 'Lidong', 'Maludam', 'Padeh', 'Spaoh', 'Triso'],
            'Sarikei' => ['Jakar', 'Repok', 'Sarikei'],
            'Kapit' => ['Kapit', 'Nanga Medamit', 'Nanga Merit', 'Pelagus'],
            'Bau' => ['Bau', 'Buso', 'Krokong', 'Musi', 'Pangkalan Tebang', 'Siniawan', 'Tondong'],
            'Limbang' => ['Batu Danau', 'Kubong', 'Limbang'],
            'Saratok' => ['Budu', 'Kabong', 'Roban', 'Saratok', 'Sebelak'],
            'Mukah' => ['Balingian', 'Kuala Balingian', 'Mukah'],
            'Simunjan' => ['Rangawan', 'Simunjan', 'Terasi'],
            'Lawas' => ['Ba\'kelalan', 'Lawas', 'Long Semado', 'Merapok', 'Sundar', 'Trusan'],
            'Belaga' => ['Belaga', 'Long Murum', 'Sungai Asap'],
            'Lundu' => ['Biawak', 'Lundu', 'Sematan'],
            'Asajaya' => ['Asajaya', 'Sadong Jaya', 'Semera'],
            'Daro' => ['Belawai', 'Daro', 'Semah', 'Serdeng'],
            'Meradong' => ['Bintangor', 'Nyelong', 'Tulai'],
            'Kanowit' => ['Kanowit', 'Machan', 'Majau', 'Nanga Dap', 'Nanga Tada'],
            'Lubok Antu' => ['Engkilili', 'Lemanak', 'Lubok Antu', 'Skrang'],
            'Selangau' => ['Arip', 'Balingian', 'Selangau', 'Tamin'],
            'Song' => ['Katibas', 'Nanga Engkuah', 'Song'],
            'Dalat' => ['Batang Igan', 'Dalat', 'Oya'],
            'Matu' => ['Igan', 'Jemoreng', 'Matu'],
            'Julau' => ['Entabai', 'Julau', 'Meluan', 'Nanga Entabai'],
            'Pakan' => ['Pakan', 'Wuak'],
            'Tanjung Manis' => ['Paloh', 'Tanjung Manis'],
            'Bukit Mabong' => [],
            'Telang Usan' => ['Lio Matu', 'Long Akah', 'Long Bedian', 'Long Lama', 'Long San'],
            'Tebedu' => ['Amo', 'Tebedu'],
            'Subis' => ['Batu Niah', 'Bekenu', 'Sepupok', 'Suai'],
            'Beluru' => ['Beluru', 'Lapok', 'Long Jegan'],
            'Kabong' => ['Kabong', 'Nyabor'],
            'Gedong' => ['Gedong'],
            'Siburan' => ['Siburan'],
            'Pantu' => ['Pantu'],
            'Lingga' => ['Lingga'],
            'Sebuyau' => ['Sebuyau'],

            // Selangor
            'Petaling' => ['Bukit Raja', 'Damansara', 'Denai Alam', 'Petaling', 'Petaling Jaya', 'Puchong', 'Saujana', 'Shah Alam', 'Subang Jaya', 'Sungai Buloh', 'USJ / UEP Subang Jaya'],
            'Hulu Langat' => ['Ampang', 'Bandar Baru Bangi', 'Beranang', 'Cheras', 'Hulu Langat', 'Hulu Semenyih', 'Kajang', 'Semenyih'],
            'Klang' => ['Jenjarum', 'Jenjarum Barat', 'Jenjarum Utama', 'Johan Setia', 'Kapar', 'Klang', 'Meru', 'Port Klang', 'Setia Alam', 'Teluk Panglima Garang'],
            'Gombak' => ['Batu', 'Batu Caves', 'Gombak', 'Hulu Klang', 'Kuang', 'Kundang', 'Rawang', 'Selayang', 'Setapak', 'Taman Melawati', 'Ulu Kelang'],
            'Kuala Langat' => ['Bandar', 'Banting', 'Batu', 'Jugra', 'Kelanang', 'Morib', 'Tanjong Duabelas', 'Tanjung Sepat', 'Telok Panglima Garang'],
            'Sepang' => ['Batu Arang', 'Cyberjaya', 'Dengkil', 'Labu', 'Salak Tinggi', 'Sepang', 'Sungai Pelek'],
            'Kuala Selangor' => ['Api-Api', 'Bestari Jaya', 'Ijok', 'Jeram', 'Kuala Selangor', 'Pasangan', 'Paya Jaras', 'Tanjong Karang', 'Ujong Permatang', 'Ulu Tinggi'],
            'Hulu Selangor' => ['Ampang Pechah', 'Batang Kali', 'Buloh Telor', 'Bukit Rotan', 'Hulu Bernam', 'Hulu Selangor', 'Hulu Yam', 'Kalumpang', 'Kerling', 'Kuala Kalumpang', 'Kuala Kubu Bharu', 'Peretak', 'Rasa', 'Serendah', 'Sungai Gumut', 'Sungai Tinggi'],
            'Sabak Bernam' => ['Bagan Nakhoda Omar', 'Panchang Bedena', 'Pasir Panjang', 'Sabak', 'Sabak Bernam', 'Sekinchan', 'Sungai Besar', 'Sungai Panjang'],

            // Terengganu
            'Kuala Terengganu' => ['Atas Tol', 'Batu Buruk', 'Belara', 'Bukit Besar', 'Bukit Payong', 'Cabang Tiga', 'Cenering', 'Gelugur Kedai', 'Gelugur Raja', 'Kepung', 'Kuala Ibai', 'Kuala Terengganu', 'Kubang Parit', 'Losong', 'Manir', 'Paluh', 'Pengadang Buluh', 'Pulau-Pulau', 'Rengas', 'Serada', 'Tok Jamal'],
            'Kemaman' => ['Ayer Puteh', 'Bandi', 'Banggul', 'Binjai', 'Ceneh', 'Chukai', 'Hulu Chukai', 'Hulu Jabur', 'Kemasek', 'Kerteh', 'Ketengah Jaya', 'Kijal', 'Pasir Semut', 'Tebak', 'Teluk Kalung'],
            'Dungun' => ['Al Muktatfi Billah Shah', 'Besul', 'Bukit Besi', 'Dungun', 'Hulu Paka', 'Jengai', 'Jerangau', 'Kuala Abang', 'Kuala Dungun', 'Kuala Paka', 'Kumpal', 'Paka', 'Pasir Raja', 'Rasau', 'Sura'],
            'Besut' => ['Bukit Kenak', 'Bukit Peteri', 'Hulu Besut', 'Jabi', 'Jerteh', 'Kampung Raja', 'Keluang', 'Kerandang', 'Kuala Besut', 'Kubang Bemban', 'Lubuk Kawah', 'Pasir Akar', 'Pelagat', 'Pangkalan Nangka', 'Pulau Perhentian', 'Tembila', 'Tenang'],
            'Marang' => ['Alur Limbat', 'Bukit Payung', 'Jerung', 'Marang', 'Merchang', 'Pulau Kerengga', 'Rusila'],
            'Hulu Terengganu' => ['Ajil', 'Hulu Berang', 'Hulu Telemung', 'Hulu Terengganu', 'Jenagur', 'Kuala Berang', 'Kuala Telemung', 'Penghulu Diman', 'Sungai Tong', 'Tanggul', 'Tersat'],
            'Setiu' => ['Bandar Permaisuri', 'Caluk', 'Chalok', 'Guntung', 'Hulu Nerus', 'Hulu Setiu', 'Merang', 'Pantai', 'Penarik', 'Permaisuri'],
            'Kuala Nerus' => ['Batu Rakit', 'Kuala Nerus', 'Pulau Redang'],

            // Wilayah Persekutuan
            'Kuala Lumpur' => ['Bandar Tun Razak', 'Batu', 'Bukit Bintang', 'Cheras', 'Kepong', 'Lembah Pantai', 'Segambut', 'Seputeh', 'Setiawangsa', 'Titiwangsa', 'Wangsa Maju'],
            'Putrajaya' => ['Precinct 1', 'Precinct 10', 'Precinct 11', 'Precinct 12', 'Precinct 13', 'Precinct 14', 'Precinct 15', 'Precinct 16', 'Precinct 17', 'Precinct 18', 'Precinct 19', 'Precinct 2', 'Precinct 20', 'Precinct 3', 'Precinct 4', 'Precinct 5', 'Precinct 6', 'Precinct 7', 'Precinct 8', 'Precinct 9'],
            'Labuan' => ['Bandar Victoria', 'Batu Arang', 'Batu Manikar', 'Bebuloh', 'Belukut', 'Bukit Kalam', 'Bukit Kuda', 'Durian Tunjung', 'Ganggarak / Merinding', 'Gersik / Saguking / Jawa / Parit', 'Kilan / Kilan Pulau Akar', 'Lajau', 'Layang-Layangan', 'Lubok Temiang', 'Nagalang / Kerupang', 'Pantai', 'Patau-Patau 1', 'Patau-Patau 2', 'Pohon Batu', 'Rancha-Rancha', 'Sungai Bangat', 'Sungai Bedaun / Sungai Sembilang', 'Sungai Buton', 'Sungai Keling', 'Sungai Labu', 'Sungai Lada', 'Sungai Miri / Pagar', 'Tanjung Aru'],
        ];
    }
}
