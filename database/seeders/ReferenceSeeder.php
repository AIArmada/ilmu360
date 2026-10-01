<?php

namespace Database\Seeders;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventReference;
use AIArmada\Persons\Enums\Gender;
use AIArmada\References\Enums\ReferenceContributorRole;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Enums\ReferenceType;
use App\Enums\SpeakerStatus;
use App\Models\Event;
use App\Models\Person;
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
            OwnerContext::withOwner(null, function (): void {
                $referenceIdsByKey = $this->seedReferenceCatalog();
                $this->attachReferencesToApprovedEvents($referenceIdsByKey);
            });
        });
    }

    /**
     * Seed references using the columns owned by the aiarmada/references package.
     *
     * @return array<string, string>
     */
    private function seedReferenceCatalog(): array
    {
        $references = [
            [
                'key' => 'riyadhus-solihin',
                'title' => 'Riyadhus Solihin',
                'authors' => [['Imam al-Nawawi', Gender::Male]],
                'type' => ReferenceType::Book->value,
                'year' => 1251,
                'publisher' => 'Dar al-Minhaj',
                'description' => 'Himpunan hadis adab dan targhib yang sering digunakan dalam kuliah umum.',
                'status' => 'verified',
                'url' => 'https://sunnah.com/riyadussalihin',
                'language' => 'ar',
                'links' => [
                    ['platform' => 'youtube', 'url' => 'https://www.youtube.com/playlist?list=riyadhus-solihin'],
                    ['platform' => 'telegram', 'url' => 'https://t.me/riyadhus_solihin'],
                ],
            ],
            [
                'key' => 'al-arbain-an-nawawi',
                'title' => "Al-Arba'in al-Nawawiyyah",
                'authors' => [['Imam al-Nawawi', Gender::Male]],
                'type' => ReferenceType::Book->value,
                'year' => 1257,
                'publisher' => 'Dar Ibn Kathir',
                'description' => '40 hadis asas Islam, akidah, ibadah, dan akhlak.',
                'status' => 'verified',
                'url' => 'https://sunnah.com/nawawi40',
                'language' => 'ar',
            ],
            [
                'key' => 'tafsir-ibn-kathir',
                'title' => 'Tafsir Ibn Kathir',
                'authors' => [['Imam Ibn Kathir', Gender::Male]],
                'type' => ReferenceType::Book->value,
                'year' => 1370,
                'publisher' => 'Dar Tayyibah',
                'description' => 'Rujukan tafsir bil-ma\'thur untuk pengajian al-Quran.',
                'status' => 'verified',
                'url' => 'https://quran.com/tafsirs/en-tafsir-ibn-kathir',
                'language' => 'ar',
            ],
            [
                'key' => 'tafsir-al-saadi',
                'title' => "Tafsir al-Sa'di",
                'authors' => [["Abd al-Rahman al-Sa'di", Gender::Male]],
                'type' => ReferenceType::Book->value,
                'year' => 2003,
                'publisher' => 'Muassasah al-Risalah',
                'description' => 'Tafsir ringkas kontemporari yang mudah difahami.',
                'status' => 'verified',
                'url' => 'https://quran.com/tafsirs/en-tafsir-assadi',
                'language' => 'ar',
            ],
            [
                'key' => 'bulugh-al-maram',
                'title' => 'Bulugh al-Maram',
                'authors' => [['Ibn Hajar al-Asqalani', Gender::Male]],
                'type' => ReferenceType::Book->value,
                'year' => 1442,
                'publisher' => 'Dar al-Salam',
                'description' => 'Kompilasi hadis hukum untuk fiqh ibadah dan muamalat.',
                'status' => 'verified',
                'url' => 'https://sunnah.com/bulugh',
                'language' => 'ar',
                'links' => [
                    ['platform' => 'website', 'url' => 'https://archive.org/details/bulugh-al-maram'],
                    ['platform' => 'youtube', 'url' => 'https://www.youtube.com/playlist?list=bulugh-al-maram'],
                ],
            ],
            [
                'key' => 'fiqh-al-manhaji',
                'title' => 'Fiqh al-Manhaji',
                'authors' => [['Dr. Mustafa al-Khin', Gender::Male], ['Dr. Mustafa al-Bugha', Gender::Male]],
                'type' => ReferenceType::Book->value,
                'year' => 2018,
                'publisher' => 'Pustaka Salam',
                'description' => 'Rujukan fiqh berstruktur untuk kelas asas dan menengah.',
                'status' => 'verified',
                'url' => 'https://example.com/fiqh-al-manhaji',
                'language' => 'ms',
            ],
            [
                'key' => 'sirah-ibn-hisham',
                'title' => 'Sirah Ibn Hisham',
                'authors' => [['Ibn Hisham', Gender::Male]],
                'type' => ReferenceType::Book->value,
                'year' => 1398,
                'publisher' => 'Dar al-Jil',
                'description' => 'Rujukan utama sejarah kehidupan Rasulullah SAW.',
                'status' => 'verified',
                'url' => 'https://archive.org/details/ibn-hisham-sirah',
                'language' => 'ar',
            ],
            [
                'key' => 'ar-raheeq-al-makhtum',
                'title' => 'Ar-Raheeq Al-Makhtum',
                'authors' => [['Safi-ur-Rahman al-Mubarakpuri', Gender::Male]],
                'type' => ReferenceType::Book->value,
                'year' => 2002,
                'publisher' => 'Darussalam',
                'description' => 'Sirah kontemporari yang lazim digunakan untuk kuliah umum.',
                'status' => 'verified',
                'url' => 'https://example.com/ar-raheeq-al-makhtum',
                'language' => 'ms',
            ],
            [
                'key' => 'hikam-ibn-ataillah',
                'title' => 'Al-Hikam Ibn Ataillah',
                'authors' => [['Ibn Ataillah al-Sakandari', Gender::Male]],
                'type' => ReferenceType::Book->value,
                'year' => 1300,
                'publisher' => 'Dar al-Kutub al-Ilmiyyah',
                'description' => 'Teks tazkiyah dan akhlak yang sering disyarahkan.',
                'status' => 'verified',
                'url' => 'https://example.com/al-hikam',
                'language' => 'ar',
            ],
            [
                'key' => 'bidayatul-hidayah',
                'title' => 'Bidayatul Hidayah',
                'authors' => [['Imam al-Ghazali', Gender::Male]],
                'type' => ReferenceType::Book->value,
                'year' => 1200,
                'publisher' => 'Dar al-Minhaj',
                'description' => 'Panduan adab harian dan penyucian jiwa.',
                'status' => 'verified',
                'url' => 'https://example.com/bidayatul-hidayah',
                'language' => 'ar',
            ],
            [
                'key' => 'adab-menuntut-ilmu-article',
                'title' => 'Adab Menuntut Ilmu Menurut Ulama',
                'authors' => [],
                'type' => ReferenceType::Article->value,
                'year' => 2025,
                'publisher' => 'Majlis Ilmu',
                'description' => 'Artikel rujukan ringkas untuk modul pengenalan pelajar baharu.',
                'status' => 'pending',
                'url' => 'https://example.com/adab-menuntut-ilmu',
                'language' => 'ms',
            ],
            [
                'key' => 'kuliah-maghrib-video',
                'title' => 'Kuliah Maghrib: Tadabbur Surah Al-Kahfi',
                'authors' => [['Ustaz Jemputan', Gender::Male]],
                'type' => ReferenceType::Video->value,
                'year' => 2024,
                'publisher' => 'Majlis Ilmu TV',
                'description' => 'Rakaman kuliah contoh untuk rujukan penyediaan kandungan.',
                'status' => 'pending',
                'url' => 'https://example.com/kuliah-maghrib-video',
                'language' => 'ms',
                'links' => [
                    ['platform' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=kuliah-maghrib'],
                    ['platform' => 'instagram', 'url' => 'https://www.instagram.com/majlisilmutv'],
                ],
            ],
            [
                'key' => 'modul-remaja-masjid',
                'title' => 'Modul Remaja Masjid Kontemporari',
                'authors' => [],
                'type' => ReferenceType::Other->value,
                'year' => 2026,
                'publisher' => 'Komuniti Setempat',
                'description' => 'Modul komuniti tempatan untuk sesi mentoring remaja.',
                'status' => 'pending',
                'url' => 'https://example.com/modul-remaja-masjid',
                'language' => 'ms',
            ],
        ];

        $referenceIdsByKey = [];

        foreach ($references as $referenceData) {
            $reference = Reference::query()->firstOrNew([
                'title' => $referenceData['title'],
            ]);

            $reference->fill([
                'type' => $referenceData['type'],
                'year' => $referenceData['year'],
                'publisher' => $referenceData['publisher'],
                'description' => $referenceData['description'],
                'status' => $referenceData['status'],
                'published_at' => $reference->published_at ?? now(),
                'url' => $referenceData['url'],
                'language' => $referenceData['language'],
            ]);
            $reference->save();

            $this->syncAuthors($reference, $referenceData['authors']);
            $this->syncSocialProfiles($reference, $referenceData['links'] ?? []);

            $referenceIdsByKey[$referenceData['key']] = (string) $reference->getKey();
        }

        return $referenceIdsByKey;
    }

    /**
     * Link verified author persons (authorship only: never speaker activation,
     * event submission, or directory visibility).
     *
     * @param  array<int, array{0: string, 1: Gender}>  $authors
     */
    private function syncAuthors(Reference $reference, array $authors): void
    {
        $authorIds = [];

        foreach ($authors as [$name, $gender]) {
            $person = Person::query()->where('name', $name)->first();

            if (! $person instanceof Person) {
                $person = Person::query()->create([
                    'name' => $name,
                    'gender' => $gender->value,
                    'slug' => app(GeneratePersonSlugAction::class)->handle($name, ['name' => $name]),
                    'status' => 'verified',
                    'speaker_status' => SpeakerStatus::Inactive->value,
                    'allow_public_event_submission' => false,
                ]);
            }

            $authorIds[] = (string) $person->getKey();
        }

        $reference->syncContributors(ReferenceContributorRole::Author, (new Person)->getMorphClass(), $authorIds);
    }

    /**
     * Seed additional profile links so the multi-link UI can be verified.
     *
     * @param  array<int, array{platform: string, url: string}>  $links
     */
    private function syncSocialProfiles(Reference $reference, array $links): void
    {
        $reference->socialProfiles()->delete();

        foreach ($links as $link) {
            $reference->socialProfiles()->create([
                'platform' => $link['platform'],
                'url' => $link['url'],
                'handle' => null,
            ]);
        }
    }

    /**
     * Mirror submit-event behavior by attaching references via package EventReference rows.
     *
     * @param  array<string, string>  $referenceIdsByKey
     */
    private function attachReferencesToApprovedEvents(array $referenceIdsByKey): void
    {
        if ($referenceIdsByKey === []) {
            return;
        }

        $events = Event::query()
            ->whereIn('status', Event::PUBLIC_STATUSES)
            ->latest('created_at')
            ->limit(180)
            ->get(['id', 'title']);

        foreach ($events as $event) {
            $referenceKeys = $this->resolveReferenceKeysForTitle((string) $event->title);
            $order = 0;

            foreach ($referenceKeys as $referenceKey) {
                $referenceId = $referenceIdsByKey[$referenceKey] ?? null;

                if (! is_string($referenceId)) {
                    continue;
                }

                $reference = Reference::query()->find($referenceId);

                EventReference::query()->updateOrCreate(
                    [
                        'event_id' => (string) $event->getKey(),
                        'referenceable_type' => 'reference',
                        'referenceable_id' => $referenceId,
                    ],
                    [
                        'reference_type' => $reference?->typeValue() ?? 'book',
                        'title' => $reference?->title,
                        'url' => $reference?->url,
                        'visibility' => 'public',
                        'sort_order' => $order,
                    ],
                );

                $order++;
            }
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
