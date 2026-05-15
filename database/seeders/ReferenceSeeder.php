<?php

namespace Database\Seeders;

use App\Enums\ReferenceType;
use App\Enums\SocialMediaPlatform;
use App\Models\Event;
use App\Models\Reference;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReferenceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $referenceIdsByKey = $this->seedReferenceCatalog();
            $this->attachReferencesToApprovedEvents($referenceIdsByKey);
        });
    }

    /**
     * Seed references using the same fields accepted by submit-event quick create.
     *
     * @return array<string, string>
     */
    private function seedReferenceCatalog(): array
    {
        $references = [
            [
                'key' => 'riyadhus-solihin',
                'title' => 'Riyadhus Solihin',
                'author' => 'Imam al-Nawawi',
                'type' => ReferenceType::Book->value,
                'publication_year' => '1251',
                'publisher' => 'Dar al-Minhaj',
                'description' => 'Himpunan hadis adab dan targhib yang sering digunakan dalam kuliah umum.',
                'is_canonical' => true,
                'status' => 'verified',
                'is_active' => true,
                'reference_url' => 'https://sunnah.com/riyadussalihin',
            ],
            [
                'key' => 'al-arbain-an-nawawi',
                'title' => "Al-Arba'in al-Nawawiyyah",
                'author' => 'Imam al-Nawawi',
                'type' => ReferenceType::Book->value,
                'publication_year' => '1257',
                'publisher' => 'Dar Ibn Kathir',
                'description' => '40 hadis asas Islam, akidah, ibadah, dan akhlak.',
                'is_canonical' => true,
                'status' => 'verified',
                'is_active' => true,
                'reference_url' => 'https://sunnah.com/nawawi40',
            ],
            [
                'key' => 'tafsir-ibn-kathir',
                'title' => 'Tafsir Ibn Kathir',
                'author' => 'Imam Ibn Kathir',
                'type' => ReferenceType::Book->value,
                'publication_year' => '1370',
                'publisher' => 'Dar Tayyibah',
                'description' => 'Rujukan tafsir bil-ma\'thur untuk pengajian al-Quran.',
                'is_canonical' => true,
                'status' => 'verified',
                'is_active' => true,
                'reference_url' => 'https://quran.com/tafsirs/en-tafsir-ibn-kathir',
            ],
            [
                'key' => 'tafsir-al-saadi',
                'title' => "Tafsir al-Sa'di",
                'author' => 'Abd al-Rahman al-Sa\'di',
                'type' => ReferenceType::Book->value,
                'publication_year' => '2003',
                'publisher' => 'Muassasah al-Risalah',
                'description' => 'Tafsir ringkas kontemporari yang mudah difahami.',
                'is_canonical' => false,
                'status' => 'verified',
                'is_active' => true,
                'reference_url' => 'https://quran.com/tafsirs/en-tafsir-assadi',
            ],
            [
                'key' => 'bulugh-al-maram',
                'title' => 'Bulugh al-Maram',
                'author' => 'Ibn Hajar al-Asqalani',
                'type' => ReferenceType::Book->value,
                'publication_year' => '1442',
                'publisher' => 'Dar al-Salam',
                'description' => 'Kompilasi hadis hukum untuk fiqh ibadah dan muamalat.',
                'is_canonical' => true,
                'status' => 'verified',
                'is_active' => true,
                'reference_url' => 'https://sunnah.com/bulugh',
            ],
            [
                'key' => 'fiqh-al-manhaji',
                'title' => 'Fiqh al-Manhaji',
                'author' => 'Dr. Mustafa al-Khin et al.',
                'type' => ReferenceType::Book->value,
                'publication_year' => '2018',
                'publisher' => 'Pustaka Salam',
                'description' => 'Rujukan fiqh berstruktur untuk kelas asas dan menengah.',
                'is_canonical' => false,
                'status' => 'verified',
                'is_active' => true,
                'reference_url' => 'https://example.com/fiqh-al-manhaji',
            ],
            [
                'key' => 'sirah-ibn-hisham',
                'title' => 'Sirah Ibn Hisham',
                'author' => 'Ibn Hisham',
                'type' => ReferenceType::Book->value,
                'publication_year' => '1398',
                'publisher' => 'Dar al-Jil',
                'description' => 'Rujukan utama sejarah kehidupan Rasulullah SAW.',
                'is_canonical' => true,
                'status' => 'verified',
                'is_active' => true,
                'reference_url' => 'https://archive.org/details/ibn-hisham-sirah',
            ],
            [
                'key' => 'ar-raheeq-al-makhtum',
                'title' => 'Ar-Raheeq Al-Makhtum',
                'author' => 'Safi-ur-Rahman al-Mubarakpuri',
                'type' => ReferenceType::Book->value,
                'publication_year' => '2002',
                'publisher' => 'Darussalam',
                'description' => 'Sirah kontemporari yang lazim digunakan untuk kuliah umum.',
                'is_canonical' => false,
                'status' => 'verified',
                'is_active' => true,
                'reference_url' => 'https://example.com/ar-raheeq-al-makhtum',
            ],
            [
                'key' => 'hikam-ibn-ataillah',
                'title' => 'Al-Hikam Ibn Ataillah',
                'author' => 'Ibn Ataillah al-Sakandari',
                'type' => ReferenceType::Book->value,
                'publication_year' => '1300',
                'publisher' => 'Dar al-Kutub al-Ilmiyyah',
                'description' => 'Teks tazkiyah dan akhlak yang sering disyarahkan.',
                'is_canonical' => true,
                'status' => 'verified',
                'is_active' => true,
                'reference_url' => 'https://example.com/al-hikam',
            ],
            [
                'key' => 'bidayatul-hidayah',
                'title' => 'Bidayatul Hidayah',
                'author' => 'Imam al-Ghazali',
                'type' => ReferenceType::Book->value,
                'publication_year' => '1200',
                'publisher' => 'Dar al-Minhaj',
                'description' => 'Panduan adab harian dan penyucian jiwa.',
                'is_canonical' => true,
                'status' => 'verified',
                'is_active' => true,
                'reference_url' => 'https://example.com/bidayatul-hidayah',
            ],
            [
                'key' => 'adab-menuntut-ilmu-article',
                'title' => 'Adab Menuntut Ilmu Menurut Ulama',
                'author' => 'Majlis Ilmu Editorial',
                'type' => ReferenceType::Article->value,
                'publication_year' => '2025',
                'publisher' => 'Majlis Ilmu',
                'description' => 'Artikel rujukan ringkas untuk modul pengenalan pelajar baharu.',
                'is_canonical' => false,
                'status' => 'pending',
                'is_active' => true,
                'reference_url' => 'https://example.com/adab-menuntut-ilmu',
            ],
            [
                'key' => 'kuliah-maghrib-video',
                'title' => 'Kuliah Maghrib: Tadabbur Surah Al-Kahfi',
                'author' => 'Ustaz Jemputan',
                'type' => ReferenceType::Video->value,
                'publication_year' => '2024',
                'publisher' => 'Majlis Ilmu TV',
                'description' => 'Rakaman kuliah contoh untuk rujukan penyediaan kandungan.',
                'is_canonical' => false,
                'status' => 'pending',
                'is_active' => true,
                'reference_url' => 'https://example.com/kuliah-maghrib-video',
            ],
            [
                'key' => 'modul-remaja-masjid',
                'title' => 'Modul Remaja Masjid Kontemporari',
                'author' => 'Panel Tarbiah Komuniti',
                'type' => ReferenceType::Other->value,
                'publication_year' => '2026',
                'publisher' => 'Komuniti Setempat',
                'description' => 'Modul komuniti tempatan untuk sesi mentoring remaja.',
                'is_canonical' => false,
                'status' => 'pending',
                'is_active' => true,
                'reference_url' => 'https://example.com/modul-remaja-masjid',
            ],
        ];

        $referenceIdsByKey = [];

        foreach ($references as $referenceData) {
            $reference = Reference::query()->firstOrNew([
                'title' => $referenceData['title'],
                'author' => $referenceData['author'],
            ]);

            $reference->fill([
                'type' => $referenceData['type'],
                'publication_year' => $referenceData['publication_year'],
                'publisher' => $referenceData['publisher'],
                'description' => $referenceData['description'],
                'is_canonical' => $referenceData['is_canonical'],
                'status' => $referenceData['status'],
                'is_active' => $referenceData['is_active'],
            ]);
            $reference->save();

            $reference->socialMedia()->updateOrCreate(
                ['platform' => SocialMediaPlatform::Website->value],
                [
                    'url' => $referenceData['reference_url'],
                    'username' => null,
                ],
            );

            $referenceIdsByKey[$referenceData['key']] = (string) $reference->getKey();
        }

        return $referenceIdsByKey;
    }

    /**
     * Mirror submit-event behavior by attaching references through event_reference.
     *
     * @param  array<string, string>  $referenceIdsByKey
     */
    private function attachReferencesToApprovedEvents(array $referenceIdsByKey): void
    {
        if ($referenceIdsByKey === []) {
            return;
        }

        $events = Event::query()
            ->where('status', 'approved')
            ->latest('starts_at')
            ->limit(180)
            ->get(['id', 'title']);

        foreach ($events as $event) {
            $referenceKeys = $this->resolveReferenceKeysForTitle((string) $event->title);
            $syncPayload = [];
            $order = 1;

            foreach ($referenceKeys as $referenceKey) {
                $referenceId = $referenceIdsByKey[$referenceKey] ?? null;

                if (! is_string($referenceId)) {
                    continue;
                }

                $syncPayload[$referenceId] = ['order_column' => $order];
                $order++;
            }

            if ($syncPayload === []) {
                continue;
            }

            $event->references()->sync($syncPayload, false);
        }
    }

    /**
     * @return list<string>
     */
    private function resolveReferenceKeysForTitle(string $title): array
    {
        $normalizedTitle = strtolower($title);

        if (
            str_contains($normalizedTitle, 'tafsir') ||
            str_contains($normalizedTitle, 'quran') ||
            str_contains($normalizedTitle, 'qur\'an') ||
            str_contains($normalizedTitle, 'tadabbur')
        ) {
            return ['tafsir-ibn-kathir', 'tafsir-al-saadi'];
        }

        if (
            str_contains($normalizedTitle, 'hadis') ||
            str_contains($normalizedTitle, 'hadith') ||
            str_contains($normalizedTitle, 'arbain') ||
            str_contains($normalizedTitle, 'riyad')
        ) {
            return ['riyadhus-solihin', 'al-arbain-an-nawawi'];
        }

        if (
            str_contains($normalizedTitle, 'fiqh') ||
            str_contains($normalizedTitle, 'ibadah') ||
            str_contains($normalizedTitle, 'solat') ||
            str_contains($normalizedTitle, 'zakat') ||
            str_contains($normalizedTitle, 'puasa')
        ) {
            return ['bulugh-al-maram', 'fiqh-al-manhaji'];
        }

        if (str_contains($normalizedTitle, 'sirah')) {
            return ['sirah-ibn-hisham', 'ar-raheeq-al-makhtum'];
        }

        if (
            str_contains($normalizedTitle, 'akhlak') ||
            str_contains($normalizedTitle, 'tasawuf') ||
            str_contains($normalizedTitle, 'tazkiyah')
        ) {
            return ['hikam-ibn-ataillah', 'bidayatul-hidayah'];
        }

        return ['riyadhus-solihin'];
    }
}
