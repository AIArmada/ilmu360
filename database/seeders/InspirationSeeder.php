<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InspirationCategory;
use App\Models\Inspiration;
use Illuminate\Database\Seeder;

class InspirationSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // ── Quran Quotes ──
            [
                'category' => InspirationCategory::QuranQuote,
                'title' => 'Kesenangan Selepas Kesusahan',
                'content' => 'Maka sesungguhnya bersama kesulitan ada kemudahan. Sesungguhnya bersama kesulitan ada kemudahan.',
                'source' => 'Surah Al-Insyirah, 94:5-6',
            ],
            [
                'category' => InspirationCategory::QuranQuote,
                'title' => 'Allah Bersama Orang Sabar',
                'content' => 'Wahai orang-orang yang beriman! Mohonlah pertolongan (kepada Allah) dengan sabar dan solat. Sungguh, Allah beserta orang-orang yang sabar.',
                'source' => 'Surah Al-Baqarah, 2:153',
            ],
            [
                'category' => InspirationCategory::QuranQuote,
                'title' => 'Jangan Berputus Asa',
                'content' => 'Dan janganlah kamu berputus asa daripada rahmat Allah. Sesungguhnya tiada berputus asa daripada rahmat Allah melainkan kaum yang kafir.',
                'source' => 'Surah Yusuf, 12:87',
            ],
            [
                'category' => InspirationCategory::QuranQuote,
                'title' => 'Perancangan Allah Yang Terbaik',
                'content' => 'Boleh jadi kamu membenci sesuatu sedang ia baik bagi kamu, dan boleh jadi kamu menyukai sesuatu sedang ia buruk bagi kamu. Dan Allah mengetahui sedang kamu tidak mengetahui.',
                'source' => 'Surah Al-Baqarah, 2:216',
            ],

            // ── Hadith Quotes ──
            [
                'category' => InspirationCategory::HadithQuote,
                'title' => 'Senyuman Adalah Sedekah',
                'content' => 'Senyumanmu di hadapan saudaramu adalah sedekah.',
                'source' => 'HR Tirmidzi',
            ],
            [
                'category' => InspirationCategory::HadithQuote,
                'title' => 'Sebaik-baik Manusia',
                'content' => 'Sebaik-baik manusia adalah yang paling bermanfaat bagi manusia lain.',
                'source' => 'HR Ahmad & Tabrani',
            ],
            [
                'category' => InspirationCategory::HadithQuote,
                'title' => 'Menuntut Ilmu',
                'content' => 'Menuntut ilmu adalah kewajipan ke atas setiap Muslim.',
                'source' => 'HR Ibnu Majah',
            ],
            [
                'category' => InspirationCategory::HadithQuote,
                'title' => 'Kemuliaan Akhlak',
                'content' => 'Sesungguhnya aku diutus untuk menyempurnakan akhlak yang mulia.',
                'source' => 'HR Ahmad',
            ],

            // ── Motivational Quotes ──
            [
                'category' => InspirationCategory::MotivationalQuote,
                'title' => 'Kekuatan Doa',
                'content' => 'Doa adalah senjata orang mukmin, tiang agama, dan cahaya langit dan bumi.',
                'source' => 'Al-Hakim',
            ],
            [
                'category' => InspirationCategory::MotivationalQuote,
                'title' => 'Usaha dan Tawakkal',
                'content' => 'Berusahalah seolah-olah kamu akan hidup selamanya, dan beribadahlah seolah-olah kamu akan mati esok.',
                'source' => null,
            ],
            [
                'category' => InspirationCategory::MotivationalQuote,
                'title' => 'Kunci Kejayaan',
                'content' => 'Kejayaan bukan diukur dari apa yang kamu capai, tetapi dari berapa banyak halangan yang kamu hadapi dan keberanian kamu untuk terus berjuang.',
                'source' => null,
            ],

            // ── Did You Know ──
            [
                'category' => InspirationCategory::DidYouKnow,
                'title' => 'Al-Quran dan Sains',
                'content' => 'Al-Quran menyebut tentang perkembangan janin dalam rahim ibu dengan tepat 1,400 tahun sebelum sains moden mengesahkannya.',
                'source' => null,
            ],
            [
                'category' => InspirationCategory::DidYouKnow,
                'title' => 'Zam-Zam Tidak Pernah Kering',
                'content' => 'Air Zam-Zam telah mengalir selama lebih 4,000 tahun tanpa pernah kering, walaupun digunakan oleh jutaan jemaah setiap tahun.',
                'source' => null,
            ],
            [
                'category' => InspirationCategory::DidYouKnow,
                'title' => 'Masjid Pertama Di Dunia',
                'content' => 'Masjid Quba di Madinah adalah masjid pertama yang dibina dalam Islam. Ia dibina oleh Rasulullah SAW sendiri semasa hijrah dari Mekah ke Madinah.',
                'source' => null,
            ],
            [
                'category' => InspirationCategory::DidYouKnow,
                'title' => 'Bahasa Arab dan Al-Quran',
                'content' => 'Al-Quran mengandungi lebih daripada 77,000 perkataan, tetapi hanya menggunakan sekitar 1,800 akar kata Arab yang unik.',
                'source' => null,
            ],

            // ── Islamic FAQ ──
            [
                'category' => InspirationCategory::IslamicFaq,
                'title' => 'Apa itu Rukun Islam?',
                'content' => 'Rukun Islam ada 5: Syahadah (pengakuan), Solat (5 waktu), Zakat (sedekah wajib), Puasa (bulan Ramadan), dan Haji (ke Mekah bagi yang mampu).',
                'source' => null,
            ],
            [
                'category' => InspirationCategory::IslamicFaq,
                'title' => 'Mengapa Solat 5 Waktu?',
                'content' => 'Solat 5 waktu diwajibkan semasa peristiwa Israk Mikraj. Ia berfungsi sebagai hubungan langsung antara hamba dengan Allah dan menjadi tiang agama Islam.',
                'source' => null,
            ],
            [
                'category' => InspirationCategory::IslamicFaq,
                'title' => 'Apa Makna Bismillah?',
                'content' => '"Bismillahirrahmanirrahim" bermaksud "Dengan nama Allah, Yang Maha Pemurah, lagi Maha Penyayang." Ia diucapkan sebelum memulakan sebarang perkara baik.',
                'source' => null,
            ],

            // ── Islamic Comic (placeholders) ──
            [
                'category' => InspirationCategory::IslamicComic,
                'title' => 'Kisah Nabi Yusuf AS',
                'content' => 'Nabi Yusuf AS dibuang ke dalam perigi oleh saudara-saudaranya kerana iri hati, namun Allah menyelamatkannya dan menjadikannya pemerintah Mesir. Kesabaran dan tawakkal beliau menjadi contoh untuk kita semua.',
                'source' => 'Al-Quran, Surah Yusuf',
            ],
            [
                'category' => InspirationCategory::IslamicComic,
                'title' => 'Keajaiban Semut',
                'content' => 'Surah An-Naml menceritakan kisah Nabi Sulaiman AS yang mendengar perbualan seekor semut yang meminta rakan-rakannya masuk ke sarang supaya tidak terpijak. Ini menunjukkan betapa Allah menjaga setiap makhluk-Nya.',
                'source' => 'Surah An-Naml, 27:18',
            ],
        ];

        foreach ($items as $item) {
            Inspiration::query()->updateOrCreate(
                ['title' => $item['title'], 'category' => $item['category'], 'locale' => 'ms'],
                $item + ['is_active' => true, 'locale' => 'ms'],
            );
        }
    }
}
