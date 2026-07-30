<?php

namespace Database\Seeders;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\PersonNameType;
use AIArmada\Persons\Models\Title;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Enums\SpeakerStatus;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\Concerns\SeedsPackageAddresses;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonSeeder extends Seeder
{
    use SeedsPackageAddresses;

    public function run(): void
    {
        Person::unsetEventDispatcher();

        try {
            DB::transaction(function (): void {
                $this->seedPersons();
            });
        } finally {
            Person::setEventDispatcher(app('events'));
        }
    }

    private function seedPersons(): void
    {
        $malaysia = $this->malaysiaCountry();
        $realPersons = [
            [
                'name' => 'Azhar Idrus',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah bebas Malaysia yang dikenali melalui penyampaian santai, penggunaan loghat Terengganu dan sesi soal jawab agama yang mudah difahami masyarakat umum.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Azhar Idrus', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Ustaz Azhar Idrus'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Zamrul bin Idrus'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'UAI'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'Ustaz.Azhar.Idrus.Original'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'uaioriginal'],
                    ['platform' => SocialPlatform::Youtube, 'handle' => 'ustazazharidrusofficial'],
                ],
            ],
            [
                'name' => 'Mohd Asri Zainul Abidin',
                'gender' => 'male',
                'titles' => ['Dr.'],
                'bio' => 'Sarjana Islam, penulis dan pendakwah Malaysia yang dikenali sebagai Dr. MAZA serta berkhidmat sebagai Mufti Negeri Perlis.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Mohd Asri Zainul Abidin', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Dr. Mohd Asri Zainul Abidin'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Mohd Asri bin Zainul Abidin'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Dr. MAZA'],
                    ['name_type' => PersonNameType::Religious, 'full_name' => 'Abu Talhah al-Malizi'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'drmaza_official'],
                ],
            ],
            [
                'name' => 'Wadi Annuar',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah dan penceramah agama Malaysia yang aktif menyampaikan kuliah berkaitan akidah, akhir zaman, sirah dan pembinaan rohani.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Wadi Annuar', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Ustaz Wadi Annuar'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Mohamad Wadi Annuar bin Ayub'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'UWA'],
                ],
                'contacts' => [
                    ['type' => ContactMethodType::Phone, 'value' => '+60143131564'],
                ],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'ustazwadiannuarofficial'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'ustazwadiannuar'],
                    ['platform' => SocialPlatform::Youtube, 'handle' => 'UstazWadiAnnuarOfficial'],
                ],
            ],
            [
                'name' => 'Don Daniyal',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah, pengacara dan personaliti televisyen Malaysia yang dikenali melalui pendekatan dakwah mesra serta penerangan asas agama dan al-Quran.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Don Daniyal', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Ustaz Don Daniyal'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Don Daniyal bin Don Biyajid'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'UstazDonOfficialPage'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'ddaniyaldbiyajid'],
                ],
            ],
            [
                'name' => 'Ali Zaenal Abidin',
                'gender' => 'male',
                'titles' => ['Habib'],
                'bio' => 'Pendakwah dan ilmuwan Islam yang dikenali melalui kuliah sirah, akhlak, tasawuf serta pengajian tradisi keilmuan Islam.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ali Zaenal Abidin', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Habib Ali Zaenal Abidin Al-Hamid'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Ali Zaenal Abidin bin Abu Bakar Al-Hamid'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Habib Ali Zaenal Abidin'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'habibalizaenalabidinalhamid'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'habibalizaenalalhamid'],
                ],
            ],
            [
                'name' => 'Kazim Elias',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah bebas Malaysia yang terkenal melalui ceramah agama, motivasi dan kekeluargaan dengan gaya penyampaian bersahaja serta berjenaka.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Kazim Elias', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => "Dato' Ustaz Mohd Kazim Elias"],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Mohammad Kazim bin Elias'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ustaz Kazim Elias'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'ustazmohdkazim'],
                ],
            ],
            [
                'name' => 'Ebit Lew',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah Malaysia yang dikenali melalui program motivasi, kerja kebajikan dan kandungan dakwah berkaitan keluarga, kasih sayang dan perubahan diri.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ebit Lew', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Ustaz Ebit Lew'],
                    ['name_type' => PersonNameType::Religious, 'full_name' => 'Ebit Irawan Ibrahim Lew'],
                    ['name_type' => PersonNameType::Birth, 'full_name' => 'Lew Yun Pau'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'ebitlewofficialpage'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'ebitlew'],
                    ['platform' => SocialPlatform::Youtube, 'handle' => 'EbitLew'],
                ],
            ],
            [
                'name' => 'Rozaimi Ramle',
                'gender' => 'male',
                'titles' => ['Profesor', 'Dr.'],
                'bio' => 'Profesor dalam bidang hadis, ahli akademik, penulis dan pendakwah Malaysia yang turut mengetuai SemakHadis.com.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Rozaimi Ramle', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Prof. Dr. Muhamad Rozaimi Ramle'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Muhamad Rozaimi bin Ramle'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Dr. Rozaimi Ramle'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'DrRozaimiRamle'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'rozaimi_ramle'],
                ],
            ],
            [
                'name' => 'Auni Mohamed',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah bebas Malaysia yang aktif membincangkan akidah, sejarah, isu semasa umat dan kefahaman Islam melalui ceramah serta media digital.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Auni Mohamed', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => "Ustaz Au'ni Mohamad"],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ustaz Auni Mohamed'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'aunimohamed'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'ustaz_auni_mohamed'],
                ],
            ],
            [
                'name' => 'Fawwaz Mat Jan',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah dan ahli politik Malaysia yang berkhidmat sebagai Ahli Parlimen Permatang Pauh.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Fawwaz Mat Jan', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'YB Tuan Haji Muhammad Fawwaz bin Mohamad Jan'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Muhammad Fawwaz bin Mohamad Jan'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Fawwaz Md Jan'],
                ],
                'contacts' => [
                    ['type' => ContactMethodType::Email, 'value' => 'pkwrpermatangpauh@gmail.com'],
                    ['type' => ContactMethodType::Phone, 'value' => '+60105256044'],
                ],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'UstazMuhammadFawwaz'],
                ],
            ],
            [
                'name' => 'Jafri Abu Bakar',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah, penulis dan penceramah Malaysia yang dikenali melalui pengajian agama, motivasi serta pendekatan dakwah kepada masyarakat umum.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Jafri Abu Bakar', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Ustaz Jafri Abu Bakar Mahmoodi'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Jafri bin Abu Bakar @ Mahmud'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'jafriabubakarmahmoodi'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'jafrimahmoodi'],
                ],
            ],
            [
                'name' => 'Abdullah Khairi',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah dan penceramah agama Malaysia yang dikenali melalui ceramah berkaitan akhlak, keluarga, ibadah dan pembinaan peribadi Muslim.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Abdullah Khairi', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Ustaz Haji Abdullah Khairi'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ustaz Abdullah Khairi'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'abdullah.khairi.18'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'abdullahkhairi_official'],
                ],
            ],
            [
                'name' => 'Haslin Baharim (Bollywood)',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah bebas Malaysia yang dikenali dengan gelaran Ustaz Bollywood dan gaya ceramah yang menggabungkan pengajaran agama, motivasi serta humor.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Haslin Baharim (Bollywood)', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Ustaz Haslin Baharim'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ustaz Bollywood'],
                ],
                'contacts' => [],
                'socials' => [],
            ],
            [
                'name' => 'Syamsul Debat',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah, penceramah motivasi dan personaliti media Malaysia yang lebih dikenali sebagai Syamsul Debat.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Syamsul Debat', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Ustaz Syamsul Debat'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Syamsul Amri bin Ismail'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'SyamsulDebatOriginalOfficial'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'syamsuldebatofficial'],
                    ['platform' => SocialPlatform::Youtube, 'handle' => 'SyamsulDebatTV'],
                ],
            ],
            [
                'name' => 'Muhaya Mohamad',
                'gender' => 'female',
                'titles' => ['Profesor', 'Dr.'],
                'bio' => 'Pakar oftalmologi, profesor, penulis dan penceramah motivasi Malaysia yang dikenali melalui perkongsian berkaitan kesihatan, pemikiran dan pembangunan diri.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Muhaya Mohamad', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Datuk Prof. Dr. Muhaya Mohamad'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Muhaya binti Mohamad'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Prof Muhaya'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'ProfMuhaya'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'profmuhayaofficial'],
                    ['platform' => SocialPlatform::Youtube, 'handle' => 'PROFMUHAYATV'],
                ],
            ],
            [
                'name' => "Asma' Harun",
                'gender' => 'female',
                'titles' => [],
                'bio' => 'Pendakwah wanita, penulis dan penceramah motivasi Malaysia yang aktif berkongsi ilmu agama, kekeluargaan dan pembangunan diri melalui program serta media sosial.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => "Asma' Harun", 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => "Ustazah Asma' Harun"],
                    ['name_type' => PersonNameType::Legal, 'full_name' => "Asma' binti Harun"],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ustazah Asma Harun'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'ustazahasmaharun'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'ustazahasmaharun'],
                    ['platform' => SocialPlatform::Youtube, 'handle' => 'UstazahAsmaHarun'],
                ],
            ],
            [
                'name' => 'Norhafizah Musa',
                'gender' => 'female',
                'titles' => ['Dr.'],
                'bio' => 'Ahli akademik, pendakwah wanita dan penceramah Malaysia yang banyak membincangkan al-Quran, kerohanian, keluarga dan pembangunan wanita.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Norhafizah Musa', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Dr. Ustazah Norhafizah Musa'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Norhafizah binti Musa'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ustazah Norhafizah Musa'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'ustazahnorhafizahmusa'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'ustazahnorhafizahmusa'],
                ],
            ],
            [
                'name' => 'Zaharuddin Abdul Rahman',
                'gender' => 'male',
                'titles' => ['Dr.'],
                'bio' => 'Sarjana Syariah, penulis, ahli akademik dan pakar kewangan Islam Malaysia yang aktif menyampaikan pendidikan berkaitan muamalat dan kewangan patuh Syariah.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Zaharuddin Abdul Rahman', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Dr. Zaharuddin Abdul Rahman'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Zaharuddin bin Abdul Rahman'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Dr Zaharuddin'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'drzaharuddinrahman'],
                    ['platform' => SocialPlatform::Youtube, 'handle' => 'DrZaharuddinOfficialChannel'],
                ],
            ],
            [
                'name' => 'Hasrizal Abdul Jamil',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendidik, penulis dan pendakwah Malaysia yang dikenali melalui jenama Saifulislam.com serta penulisan berkaitan pendidikan, keluarga, pemikiran dan pengalaman hidup.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Hasrizal Abdul Jamil', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Ustaz Hasrizal Abdul Jamil'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Hasrizal bin Abdul Jamil'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Saiful Islam'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'hasrizalabduljamil'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'iamhasrizal'],
                    ['platform' => SocialPlatform::Youtube, 'handle' => 'iamhasrizal'],
                ],
            ],
            [
                'name' => 'Amin Idris',
                'gender' => 'male',
                'titles' => [],
                'bio' => 'Pengacara, penulis, perunding latihan dan penceramah motivasi Malaysia yang aktif dalam bidang komunikasi, kepimpinan dan pembangunan insan.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Amin Idris', 'is_primary' => true],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Bro Amin'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Abid Abdullah'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'aminidrispage'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'amin_idris'],
                    ['platform' => SocialPlatform::Youtube, 'handle' => 'THEAMINIDRISSHOW'],
                ],
            ],
            [
                'name' => 'Ahmad Dusuki Abdul Rani',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah, penulis dan penceramah agama Malaysia yang aktif menyampaikan kuliah berkaitan akidah, ibadah, akhirat dan pembinaan rohani.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ahmad Dusuki Abdul Rani', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Ustaz Ahmad Dusuki Abd Rani'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Ahmad Dusuki bin Abdul Rani'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ustaz Ahmad Dusuki #USTAD'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Youtube, 'handle' => 'UstazAhmadDusuki'],
                ],
            ],
            [
                'name' => 'Mohamad Elyas Ismail',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah, penceramah motivasi dan personaliti televisyen Malaysia yang aktif menyampaikan ilmu berkaitan akhlak, keluarga dan pembangunan diri.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Mohamad Elyas Ismail', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Ustaz Elyas Ismail'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Mohamad Elyas bin Ismail'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Elyas Ismail'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'ustazelyasismail'],
                ],
            ],
            [
                'name' => 'Fatimah Syarha Mohd Noordin',
                'gender' => 'female',
                'titles' => ['Dr.'],
                'bio' => 'Penulis, pendakwah dan perunding motivasi Malaysia yang banyak membincangkan pendidikan keluarga, wanita, keibubapaan dan pembinaan rumah tangga berteraskan Islam.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Fatimah Syarha Mohd Noordin', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Dr. Ustazah Fatimah Syarha Mohd Noordin'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Fatimah Syarha binti Mohd Noordin'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ustazah Fatimah Syarha'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'fatimah_syarha'],
                ],
            ],
            [
                'name' => 'Siti Nor Bahyah Mahamood',
                'gender' => 'female',
                'titles' => [],
                'bio' => 'Pendakwah wanita dan pakar motivasi keluarga Malaysia yang dikenali melalui ceramah serta program media berkaitan perkahwinan, kekeluargaan dan pembangunan wanita.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Siti Nor Bahyah Mahamood', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Datuk Ustazah Siti Nor Bahyah Mahamood'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Siti Nor Bahyah binti Mahamood'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ustazah Bahyah'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'ustazah_bahyah'],
                ],
            ],
            [
                'name' => 'Zulkifli Mohamad Al-Bakri',
                'gender' => 'male',
                'titles' => ['Dr.'],
                'bio' => 'Sarjana Islam, penulis dan mantan Mufti Wilayah Persekutuan yang menghasilkan ribuan artikel serta jawapan berkaitan fiqh, fatwa dan isu semasa.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Zulkifli Mohamad Al-Bakri', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Datuk Dr. Zulkifli Mohamad Al-Bakri'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Zulkifli bin Mohamad Al-Bakri'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Dr. Zulkifli Al-Bakri'],
                ],
                'contacts' => [
                    ['type' => ContactMethodType::Email, 'value' => 'pa.drzul.albakri@gmail.com'],
                    ['type' => ContactMethodType::Phone, 'value' => '+60135844287'],
                ],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'drzulkiflialbakri'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'drzulkifli.albakri'],
                    ['platform' => SocialPlatform::Youtube, 'handle' => 'DatukDrZulkifliMohamadalBakri'],
                ],
            ],
            [
                'name' => 'Ahmad Kamil Jamilin',
                'gender' => 'male',
                'titles' => ['Dr.'],
                'bio' => 'Ahli akademik, sarjana hadis, penulis dan pendakwah Malaysia yang dikenali melalui pengajian serta penerangan disiplin ilmu hadis.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ahmad Kamil Jamilin', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Dr. Kamilin Jamilin'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Ahmad Kamil bin Jamilin'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ustaz Kamilin Jamilin'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'dr_kamilinjamilin_official'],
                ],
            ],
            [
                'name' => 'Maszlee Malik',
                'gender' => 'male',
                'titles' => ['Dr.'],
                'bio' => 'Ahli akademik, penulis, penceramah dan mantan Menteri Pendidikan Malaysia yang banyak membincangkan pendidikan, dasar awam dan masyarakat.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Maszlee Malik', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Dr. Maszlee Malik'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Maszlee bin Malik'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'DrMaszleeMalik'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'maszlee'],
                ],
            ],
            [
                'name' => 'Idris Ahmad',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah, penulis dan ahli politik Malaysia yang aktif dalam pendidikan Islam, kerja kemasyarakatan dan khidmat awam.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Idris Ahmad', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'YB Datuk Haji Idris bin Haji Ahmad'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Idris bin Haji Ahmad'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Ustaz Idris Ahmad'],
                ],
                'contacts' => [
                    ['type' => ContactMethodType::Email, 'value' => 'idrissahmad@gmail.com'],
                    ['type' => ContactMethodType::Phone, 'value' => '+6057214586'],
                ],
                'socials' => [
                    ['platform' => SocialPlatform::Facebook, 'handle' => 'ustazidrisahmad'],
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'ustaz.idrisahmad'],
                ],
            ],
            [
                'name' => 'Isfadiah Mohd Dasuki',
                'gender' => 'female',
                'titles' => [],
                'bio' => 'Pendakwah wanita, perunding motivasi dan pengasas inisiatif pendidikan keluarga yang aktif membincangkan keibubapaan, wanita dan pembinaan keluarga.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Isfadiah Mohd Dasuki', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Ustazah Isfadiah Mohd Dasuki'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Isfadiah binti Mohd Dasuki'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Isfadiah Mohd. Dasuki'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'isfadiah'],
                ],
            ],
            [
                'name' => 'Muhammad Asyraf Mohd Ridzuan',
                'gender' => 'male',
                'titles' => ['Ustaz'],
                'bio' => 'Pendakwah, pengacara dan personaliti media Malaysia yang dikenali sebagai juara sulung program Imam Muda.',
                'names' => [
                    ['name_type' => PersonNameType::Display, 'full_name' => 'Muhammad Asyraf Mohd Ridzuan', 'is_primary' => true],
                    ['name_type' => PersonNameType::Professional, 'full_name' => 'Imam Muda Asyraf'],
                    ['name_type' => PersonNameType::Legal, 'full_name' => 'Muhammad Asyraf bin Mohd Ridzuan'],
                    ['name_type' => PersonNameType::Display, 'full_name' => 'IM Asyraf'],
                ],
                'contacts' => [],
                'socials' => [
                    ['platform' => SocialPlatform::Instagram, 'handle' => 'asyrafridzuanofficial'],
                ],
            ],
        ];

        $userIds = User::query()->pluck('id')->toArray();
        $memberAttachments = [];

        foreach ($realPersons as $personData) {
            OwnerContext::withOwner(null, function () use ($personData, $userIds, $malaysia, &$memberAttachments): void {
                $nameParts = array_values(array_filter(preg_split('/\s+/u', trim($personData['name'])) ?: []));
                $familyName = array_pop($nameParts) ?: null;
                $name = array_shift($nameParts) ?: trim($personData['name']);
                $middleName = $nameParts !== [] ? implode(' ', $nameParts) : null;
                $person = Person::firstOrCreate(
                    [
                        'name' => $name,
                        'middle_name' => $middleName,
                        'family_name' => $familyName,
                    ],
                    [
                        'slug' => app(GeneratePersonSlugAction::class)->handle($name, [
                            'middle_name' => $middleName,
                            'family_name' => $familyName,
                        ]),
                        'gender' => $personData['gender'],
                        'bio' => [
                            'type' => 'doc',
                            'content' => [[
                                'type' => 'paragraph',
                                'content' => [[
                                    'type' => 'text',
                                    'text' => $personData['bio'],
                                ]],
                            ]],
                        ],
                        'status' => 'verified',
                        'speaker_status' => SpeakerStatus::Active->value,
                    ]
                );

                if ($person->middle_name !== $middleName || $person->family_name !== $familyName) {
                    $person->forceFill([
                        'middle_name' => $middleName,
                        'family_name' => $familyName,
                    ])->saveQuietly();
                }

                if ($person->wasRecentlyCreated || $person->getAttribute('speaker_status') === null) {
                    $person->forceFill(['speaker_status' => SpeakerStatus::Active->value])->saveQuietly();
                }

                if ($person->wasRecentlyCreated) {
                    $state = null;
                    $district = null;
                    $subdistrict = null;

                    $this->seedPrimaryPackageAddress($person, $this->packageAddressAttributes([
                        'country_id' => $malaysia?->getKey(),
                    ], $state, $district, $subdistrict));
                }

                foreach ($personData['titles'] as $titleName) {
                    $title = Title::query()->where('name', $titleName)->firstOrFail();

                    $person->titleAssignments()->firstOrCreate(
                        ['title_id' => $title->id],
                        ['status' => AssignmentStatus::Active],
                    );
                }

                foreach ($personData['names'] as $personName) {
                    $person->names()->firstOrCreate(
                        [
                            'name_type' => $personName['name_type'],
                            'language_code' => 'ms',
                            'full_name' => $personName['full_name'],
                        ],
                        ['is_primary' => $personName['is_primary'] ?? false],
                    );
                }

                foreach ($personData['contacts'] as $contact) {
                    $person->contactMethods()->updateOrCreate(
                        ['type' => $contact['type']->value],
                        ['value' => $contact['value'], 'purpose' => ContactPurpose::General->value]
                    );
                }

                $socials = $personData['socials'];
                foreach ($socials as $social) {
                    $person->socialProfiles()->firstOrCreate(
                        ['platform' => $social['platform']->value],
                        ['handle' => $social['handle']],
                    );
                }

                if (! empty($userIds)) {
                    $memberAttachments[] = [
                        'person_id' => $person->id,
                        'user_id' => $userIds[array_rand($userIds)],
                    ];
                }
            });
        }

        $currentCount = Person::count();
        if ($currentCount < 30) {
            $persons = Person::factory()->count(30 - $currentCount)->create();

            foreach ($persons as $person) {
                $person->socialProfiles()->create([
                    'platform' => SocialPlatform::Facebook->value,
                    'handle' => Str::slug($person->name),
                ]);

                if (! empty($userIds)) {
                    $memberAttachments[] = [
                        'person_id' => $person->id,
                        'user_id' => $userIds[array_rand($userIds)],
                    ];
                }
            }
        }

        if ($memberAttachments !== []) {
            DB::table('person_members')->insertOrIgnore($memberAttachments);
        }
    }
}
