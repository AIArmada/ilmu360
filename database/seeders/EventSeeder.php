<?php

namespace Database\Seeders;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventRole;
use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Events\SyncEventClassificationsAction;
use App\Actions\Events\SyncEventScheduleAction;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventTaxonomyCode;
use App\Enums\EventVisibility;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\Series;
use App\Models\Venue;
use App\Services\EventKeyPersonSyncService;
use Database\Seeders\Concerns\SeedsEventLocations;
use Database\Seeders\Concerns\SeedsPackageAddresses;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventSeeder extends Seeder
{
    use SeedsEventLocations;
    use SeedsPackageAddresses;

    /**
     * @var array<string, string>
     */

    /**
     * @var array<string, string>
     */
    private array $schedulePersonIds = [];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedEventSpaces();

        OwnerContext::withOwner(null, function (): void {
            $hadEvents = Event::query()->exists();

            $this->seedIlmu360Schedule();

            if (! $hadEvents) {
                $this->seedBulkEvents();
            }

            $this->backfillSeededEventRequiredFields();
        });
    }

    private function seedBulkEvents(): void
    {

        DB::transaction(function (): void {
            $institutions = Institution::query()
                ->limit(90)
                ->get();
            $seriesIds = Series::query()->pluck('id')->toArray();
            $personIds = Person::query()->pluck('id')->toArray();
            $venues = Venue::query()->get();
            $speakerRoleId = EventRole::query()->where('code', EventKeyPersonRole::Speaker->value)->value('id');

            if ($institutions->isEmpty()) {
                return;
            }

            $count = 0;
            $limit = 50; // We already have ~50 from seedIlmu360Schedule

            foreach ($institutions as $institution) {
                if ($count >= $limit) {
                    break;
                }

                $randomSeriesId = empty($seriesIds) ? null : $seriesIds[array_rand($seriesIds)];
                $randomVenue = $venues->isEmpty() ? null : $venues->random();

                // Create 10 events per institution
                // Start with no location, then assign exactly one location per event.
                $events = Event::factory()->count(10)->create([
                    'institution_id' => null,
                    'default_venue_id' => null,
                ]);

                // Ensure seeded events follow location invariant:
                // - online: no physical location
                // - non-online: institution XOR venue
                /** @var Event $event */
                foreach ($events as $event) {
                    if ($event->delivery_mode === EventFormat::Online->value || $event->delivery_mode === EventFormat::Online) {
                        $event->update([
                            'institution_id' => null,
                            'default_venue_id' => null,
                        ]);
                        $this->syncSeededEventLocation($event);

                        continue;
                    }

                    $useVenueLocation = $randomVenue instanceof Venue && random_int(0, 1) === 1;

                    if ($useVenueLocation) {
                        $event->update([
                            'institution_id' => null,
                            'default_venue_id' => $randomVenue->getKey(),
                        ]);
                        $this->syncSeededEventLocation($event, venue: $randomVenue);
                    } else {
                        $event->update([
                            'institution_id' => $institution->id,
                            'default_venue_id' => null,
                        ]);
                        $this->syncSeededEventLocation($event, institution: $institution);
                    }

                    $this->seedKeyPeopleForEvent($event, $personIds);
                }

                // If a series exists in the system, attach events via pivot table.
                if ($randomSeriesId) {
                    $order = 1;
                    /** @var Event $event */
                    foreach ($events as $event) {
                        DB::table(config('events.database.tables.event_series_items', 'event_series_items'))->insert([
                            'id' => (string) Str::uuid(),
                            'event_series_id' => $randomSeriesId,
                            'seriesable_type' => Event::class,
                            'seriesable_id' => $event->id,
                            'event_id' => $event->id,
                            'event_occurrence_id' => null,
                            'event_session_id' => null,
                            'title_override' => null,
                            'starts_at' => $event->starts_at,
                            'sort_order' => $order++,
                            'metadata' => json_encode(['source' => 'ilmu360_seed']),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                // Prepare bulk key-person data
                $personKeyPeople = [];

                foreach ($events as $event) {
                    // Randomly select 1-3 persons
                    if (! empty($personIds)) {
                        $numPersons = min(random_int(1, 3), count($personIds));
                        $selectedPersons = (array) array_rand(array_flip($personIds), $numPersons);
                        foreach (array_values($selectedPersons) as $index => $personId) {
                            $personKeyPeople[] = [
                                'id' => (string) Str::uuid(),
                                'event_id' => $event->id,
                                'involveable_type' => 'person',
                                'involveable_id' => $personId,
                                'event_role_id' => $speakerRoleId,
                                'role_code' => EventKeyPersonRole::Speaker->value,
                                'sort_order' => $index + 1,
                                'notes' => null,
                                'status' => 'active',
                                'visibility' => 'public',
                                'prominence' => '0',
                                'is_featured' => false,
                                'is_primary' => false,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }
                }

                // Bulk insert person key people
                if ($personKeyPeople !== []) {
                    DB::table('event_involvements')->insert($personKeyPeople);
                }

                $count += 10;
            }
        });
    }

    private function seedIlmu360Schedule(): void
    {
        $malaysia = $this->malaysiaCountry();

        $institution = Institution::query()
            ->where('name', 'Masjid Tengku Ampuan Jemaah Bukit Jelutong')
            ->first();

        if (! $institution instanceof Institution) {
            $institution = Institution::query()->create([
                'type' => 'masjid',
                'name' => 'Masjid Tengku Ampuan Jemaah Bukit Jelutong',
                'slug' => 'masjid-tengku-ampuan-jemaah-bukit-jelutong',
                'description' => '<p>Masjid Tengku Ampuan Jemaah Bukit Jelutong ialah pusat ibadah dan pembelajaran Islam untuk komuniti setempat.</p><p>Masjid ini menganjurkan solat berjemaah, kuliah mingguan, program al-Quran, serta aktiviti kekeluargaan dan kemasyarakatan.</p>',
                'status' => 'verified',
            ]);
        } elseif (blank($institution->description) || $institution->description === 'Jadual kuliah Januari 2026.') {
            $institution->update([
                'description' => '<p>Masjid Tengku Ampuan Jemaah Bukit Jelutong ialah pusat ibadah dan pembelajaran Islam untuk komuniti setempat.</p><p>Masjid ini menganjurkan solat berjemaah, kuliah mingguan, program al-Quran, serta aktiviti kekeluargaan dan kemasyarakatan.</p>',
            ]);
        }

        OwnerContext::withOwner($institution, function () use ($institution): void {
            $institution->contactMethods()->firstOrCreate(
                ['type' => ContactMethodType::Email->value],
                ['value' => 'mtajbj@gmail.com', 'purpose' => ContactPurpose::General->value, 'is_public' => true]
            );

            $institution->contactMethods()->firstOrCreate(
                ['type' => ContactMethodType::Phone->value],
                ['value' => '03-78313641', 'purpose' => ContactPurpose::General->value, 'is_public' => true]
            );

            $institution->contactMethods()->firstOrCreate(
                ['type' => ContactMethodType::Whatsapp->value],
                ['value' => '601278313641', 'purpose' => ContactPurpose::General->value, 'is_public' => true]
            );

            foreach ([
                [SocialPlatform::Facebook, 'masjid.tajbj', 'https://www.facebook.com/masjid.tajbj'],
                [SocialPlatform::Instagram, 'masjid.tajbj', 'https://www.instagram.com/masjid.tajbj'],
                [SocialPlatform::Youtube, 'MasjidTAJBJ', 'https://www.youtube.com/@MasjidTAJBJ'],
                [SocialPlatform::Telegram, 'masjid_tajbj', 'https://t.me/masjid_tajbj'],
            ] as [$platform, $handle, $url]) {
                $institution->socialProfiles()->firstOrCreate(
                    ['platform' => $platform->value],
                    ['handle' => $handle, 'url' => $url]
                );
            }
        });

        if (! $institution->primaryAddress()) {
            $state = $this->malaysiaPackageStateByName('Selangor');
            $areaState = $state instanceof State
                ? $this->malaysiaAreaStateForPackageState($state)
                : null;
            $district = $areaState instanceof AddressArea
                ? $this->malaysiaAreaByName('Petaling', 2, (string) $areaState->getKey())
                : null;
            $subdistrict = $district instanceof AddressArea
                ? $this->malaysiaAreaByName('Shah Alam', 3, (string) $district->getKey())
                : null;

            $this->seedPrimaryPackageAddress($institution, $this->packageAddressAttributes([
                'line1' => 'Bukit Jelutong',
                'city' => 'Shah Alam',
                'country_id' => $malaysia?->id,
                'latitude' => 3.0991666,
                'longitude' => 101.529892,
                'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=3.0991666%2C101.529892',
                'waze_url' => 'https://www.waze.com/ul?ll=3.0991666%2C101.529892&navigate=yes',
            ], $state, $district, $subdistrict));
        }

        $institution->primaryAddress()?->update([
            'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=3.0991666%2C101.529892',
            'waze_url' => 'https://www.waze.com/ul?ll=3.0991666%2C101.529892&navigate=yes',
        ]);

        $schedule = [
            ['date' => '2026-01-05', 'slot' => 'Dhuha', 'time' => '10:30', 'person' => 'Ust Mukhlisur Riyadus', 'topic' => 'Adab Iman'],
            ['date' => '2026-01-05', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Mohd Aris Johari', 'topic' => 'Tafsir Juz Amma (Surah Jasim)'],
            ['date' => '2026-01-12', 'slot' => 'Dhuha', 'time' => '10:00', 'person' => 'Ust Muhd Zulkifli', 'topic' => 'Kitab Idaman Penuntut Ilmu'],
            ['date' => '2026-01-12', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Mohd Faiz al-Izzani', 'topic' => 'Tafsir Juz Amma'],
            ['date' => '2026-01-19', 'slot' => 'Dhuha', 'time' => '10:00', 'person' => 'Ust Mohd Nazri Abdul Razak', 'topic' => 'Berusrah Bersama'],
            ['date' => '2026-01-19', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Anuar Harun', 'topic' => 'Tafsir Juz Amma'],
            ['date' => '2026-01-26', 'slot' => 'Dhuha', 'time' => '10:00', 'person' => 'Ust Mohd Nazri Abdul Razak', 'topic' => 'Berusrah Bersama'],
            ['date' => '2026-01-26', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Fawwaz Mohd Nur', 'topic' => 'Tafsir Juz Amma'],

            ['date' => '2026-01-06', 'slot' => 'Quran Time', 'time' => '08:45', 'person' => 'Ust Adi Hamman Mahwi', 'topic' => 'Quran Time'],
            ['date' => '2026-01-06', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Dr Adnin Ramly'],
            ['date' => '2026-01-13', 'slot' => 'Dhuha', 'time' => '10:30', 'person' => 'Ust Abdul Khair Zaki'],
            ['date' => '2026-01-13', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Abu Hazim'],
            ['date' => '2026-01-20', 'slot' => 'Quran Time', 'time' => '08:45', 'person' => 'Ust Adi Hamman Mahwi', 'topic' => 'Quran Time'],
            ['date' => '2026-01-20', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Ebit Lew'],
            ['date' => '2026-01-27', 'slot' => 'Talaqqi al-Quran', 'time' => '10:30', 'person' => 'Ust Izani Zulkifli', 'topic' => 'Talaqqi al-Quran'],
            ['date' => '2026-01-27', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Dr Zulkifli Mohamad al-Bakri'],

            ['date' => '2026-01-07', 'slot' => 'Dhuha', 'time' => '10:00', 'person' => 'Ust Muhd Zulkifli', 'topic' => 'Kitab Idaman Penuntut Ilmu'],
            ['date' => '2026-01-07', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Dato Dr Danial Zainal Abidin'],
            ['date' => '2026-01-14', 'slot' => 'Dhuha', 'time' => '10:00', 'person' => 'Ust Muhamad Azmi', 'topic' => 'Kitab Nuru al-Iqna Masail Taharah'],
            ['date' => '2026-01-14', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Syed Mohd Shahabuddin'],
            ['date' => '2026-01-21', 'slot' => 'Dhuha', 'time' => '10:00', 'person' => 'Ust Muhamad Azmi'],
            ['date' => '2026-01-21', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Hj Zakaria Othman'],
            ['date' => '2026-01-28', 'slot' => 'Dhuha', 'time' => '10:00', 'person' => 'Ust Muhd Azmi'],
            ['date' => '2026-01-28', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Jamil Hashim'],

            ['date' => '2026-01-01', 'slot' => 'Maghrib', 'time' => '20:00', 'topic' => 'Bacaan Yasin & Tazkirah'],
            ['date' => '2026-01-08', 'slot' => 'Dhuha', 'time' => '10:30', 'person' => 'Ust Muhd Izudin Salem', 'topic' => 'Hadis Riyadus Solihin'],
            ['date' => '2026-01-08', 'slot' => 'Maghrib', 'time' => '20:00', 'topic' => 'Bacaan Yasin & Tazkirah'],
            ['date' => '2026-01-15', 'slot' => 'Dhuha', 'time' => '10:30', 'person' => 'Puan Farhana Abdul Ghani', 'topic' => 'Hikam ke-140'],
            ['date' => '2026-01-15', 'slot' => 'Maghrib', 'time' => '20:00', 'topic' => 'Bacaan Yasin & Tazkirah'],
            ['date' => '2026-01-22', 'slot' => 'Dhuha', 'time' => '10:30', 'person' => 'Dr Azman Shah Alias'],
            ['date' => '2026-01-22', 'slot' => 'Maghrib', 'time' => '20:00', 'topic' => 'Bacaan Yasin & Tazkirah'],
            ['date' => '2026-01-29', 'slot' => 'Dhuha', 'time' => '10:30', 'person' => 'Ust Anas Mohd'],
            ['date' => '2026-01-29', 'slot' => 'Maghrib', 'time' => '20:00', 'topic' => 'Bacaan Yasin & Tazkirah'],

            ['date' => '2026-01-02', 'slot' => 'Dhuha', 'time' => '10:00', 'note' => 'Ditangguhkan', 'topic' => 'Kuliah Dhuha'],
            ['date' => '2026-01-02', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Akid Shafie'],
            ['date' => '2026-01-09', 'slot' => 'Dhuha', 'time' => '10:00', 'person' => 'Ust Ahmad Fawwaz Zaidon'],
            ['date' => '2026-01-09', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Adnin Ramly'],
            ['date' => '2026-01-16', 'slot' => 'Dhuha', 'time' => '10:00', 'person' => 'Dato Dr Najmuddin'],
            ['date' => '2026-01-16', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Ahmad Saffwan'],
            ['date' => '2026-01-23', 'slot' => 'Dhuha', 'time' => '10:00', 'person' => 'Dato Dr Mohd Radzi'],
            ['date' => '2026-01-23', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Dato Dr Ahmad Zaki'],
            ['date' => '2026-01-30', 'slot' => 'Dhuha', 'time' => '10:00', 'person' => 'Dr Mizan Mohamed'],
            ['date' => '2026-01-30', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Jamil Hashim'],

            ['date' => '2026-01-03', 'slot' => 'Subuh', 'time' => '05:45', 'person' => 'Ust Roslan Mohamed'],
            ['date' => '2026-01-03', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Fahmi Ideris'],
            ['date' => '2026-01-10', 'slot' => 'Subuh', 'time' => '05:45', 'person' => 'Ust Radzi Shahari'],
            ['date' => '2026-01-10', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Ahmad Anwar'],
            ['date' => '2026-01-17', 'slot' => 'Subuh', 'time' => '05:45', 'person' => 'Ust Ahmad Rosli'],
            ['date' => '2026-01-17', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Mohd Sufyan'],
            ['date' => '2026-01-24', 'slot' => 'Subuh', 'time' => '05:45', 'person' => 'Ust Mohd Shah Rizal'],
            ['date' => '2026-01-24', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Dr Fathullah Kani'],
            ['date' => '2026-01-31', 'slot' => 'Maghrib', 'time' => '20:00', 'note' => 'Dibatalkan', 'topic' => 'Kuliah Maghrib'],

            ['date' => '2026-01-04', 'slot' => 'Subuh', 'time' => '05:45', 'person' => 'Mufti Wilayah Persekutuan'],
            ['date' => '2026-01-04', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Imam Muda Hassan'],
            ['date' => '2026-01-11', 'slot' => 'Subuh', 'time' => '05:45', 'person' => 'Dato Prof Dr Basri Ibrahim'],
            ['date' => '2026-01-11', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Ahmad Anwar'],
            ['date' => '2026-01-18', 'slot' => 'Subuh', 'time' => '05:45', 'person' => 'Dato Seri Zulkifli al-Bakri'],
            ['date' => '2026-01-18', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Ibrahim Zamzibar'],
            ['date' => '2026-01-25', 'slot' => 'Subuh', 'time' => '05:45', 'person' => 'Dato Dr Danial Zainal Abidin'],
            ['date' => '2026-01-25', 'slot' => 'Maghrib', 'time' => '20:00', 'person' => 'Ust Azhar Idrus'],
        ];

        foreach ($schedule as $entry) {
            $startsAtLocal = Carbon::parse($entry['date'].' '.$entry['time'], 'Asia/Kuala_Lumpur');
            $endsAtLocal = $startsAtLocal->copy()->addMinutes(90);
            $startsAt = $startsAtLocal->copy()->utc();
            $endsAt = $endsAtLocal->copy()->utc();
            $topic = $entry['topic'] ?? null;
            $note = $entry['note'] ?? null;

            $title = $entry['slot'];
            if ($topic) {
                $title = $entry['slot'].': '.$topic;
            }
            if ($note) {
                $title .= ' - '.$note;
            }

            $descriptionParts = [];
            if ($topic) {
                $descriptionParts[] = $topic;
            }
            if (isset($entry['person'])) {
                $descriptionParts[] = 'Bersama '.$entry['person'];
            }
            if ($note) {
                $descriptionParts[] = $note;
            }

            // Determine timing mode
            $timingMode = TimingMode::Absolute->value;
            $prayerReference = null;
            $prayerOffset = null;
            $prayerDisplayText = null;

            $slotLower = strtolower($entry['slot']);
            if (str_contains($slotLower, 'maghrib')) {
                $timingMode = TimingMode::PrayerRelative->value;
                $prayerReference = PrayerReference::Maghrib->value;
                $prayerOffset = PrayerOffset::Immediately->value;
                $prayerDisplayText = 'Selepas Maghrib';
            } elseif (str_contains($slotLower, 'isyak') || str_contains($slotLower, 'isya')) {
                $timingMode = TimingMode::PrayerRelative->value;
                $prayerReference = PrayerReference::Isha->value;
                $prayerOffset = PrayerOffset::After15->value; // Usually Isyak lectures start a bit later
                $prayerDisplayText = '15 minit selepas Isyak';
            } elseif (str_contains($slotLower, 'subuh')) {
                $timingMode = TimingMode::PrayerRelative->value;
                $prayerReference = PrayerReference::Fajr->value;
                $prayerOffset = PrayerOffset::Immediately->value;
                $prayerDisplayText = 'Selepas Subuh';
            } elseif (str_contains($slotLower, 'zuhur') || str_contains($slotLower, 'zohor')) {
                $timingMode = TimingMode::PrayerRelative->value;
                $prayerReference = PrayerReference::Dhuhr->value;
                $prayerOffset = PrayerOffset::Immediately->value;
                $prayerDisplayText = 'Selepas Zohor';
            }

            if ($timingMode === TimingMode::PrayerRelative->value) {
                $endsAt = null;
            }

            $eventAttributes = [
                'institution_id' => $institution->id,
                'default_venue_id' => null,
                'title' => $title,
                'description' => $descriptionParts !== [] ? implode(' | ', $descriptionParts) : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'schedule_kind' => ScheduleKind::CustomChain->value,
                'timezone' => 'Asia/Kuala_Lumpur',
                // 'language' has been removed; genre/audience use event categories and age groups.
                'gender' => EventGenderRestriction::All,
                'age_group' => [EventAgeGroup::AllAges->value],
                'children_allowed' => true,
                'delivery_mode' => EventFormat::Physical,
                'visibility' => EventVisibility::Public,
                'is_muslim_only' => false,
                'status' => 'approved',
                'published_at' => $startsAtLocal->copy()->subDays(7)->utc(),
                'timing_mode' => $timingMode,
                'prayer_reference' => $prayerReference,
                'prayer_offset' => $prayerOffset,
                'prayer_display_text' => $prayerDisplayText,
            ];

            $existingScheduleEvent = $this->resolveExistingScheduleEvent($eventAttributes, $entry['person'] ?? null);
            $person = $this->resolveSchedulePerson($entry['person'] ?? null, $existingScheduleEvent);

            $eventAttributes['slug'] = app(GenerateEventSlugAction::class)->handle(
                $title,
                $entry['date'],
                'Asia/Kuala_Lumpur',
                $existingScheduleEvent?->getKey() !== null ? (string) $existingScheduleEvent->getKey() : null,
                $person instanceof Person && is_string($person->slug) && $person->slug !== ''
                    ? [$person->slug]
                    : [],
            );

            $event = $existingScheduleEvent;

            $persistedEventAttributes = $eventAttributes;
            foreach (['starts_at', 'ends_at', 'timing_mode', 'prayer_reference', 'prayer_offset', 'prayer_display_text'] as $scheduleKey) {
                unset($persistedEventAttributes[$scheduleKey]);
            }

            if ($event instanceof Event) {
                $event->fill($persistedEventAttributes);
                $event->save();
            } else {
                $event = Event::query()->create($persistedEventAttributes);
            }

            $this->syncSeededEventLocation($event, institution: $institution);

            app(SyncEventScheduleAction::class)->execute(
                event: $event,
                scheduleKind: ScheduleKind::tryFrom($eventAttributes['schedule_kind']) ?? ScheduleKind::Single,
                startsAt: $startsAt,
                endsAt: $endsAt,
                timezone: $event->timezone,
                timingMode: TimingMode::tryFrom((string) $eventAttributes['timing_mode']),
                prayerReference: $eventAttributes['prayer_reference'],
                prayerOffset: PrayerOffset::tryFrom((string) ($eventAttributes['prayer_offset'] ?? ''))?->minutes(),
                prayerDisplayText: $eventAttributes['prayer_display_text'],
            );

            $event->primaryOccurrence?->forceFill([
                'status' => 'published',
                'published_at' => $event->published_at,
            ])->save();

            $categoryId = array_key_first(app(EventCategoryCatalog::class)->options());
            if ($categoryId !== null) {
                app(SyncEventClassificationsAction::class)->handle($event, ['event_category_ids' => [$categoryId]]);
            }

            OwnerContext::withOwner(null, fn () => $event->setPrimaryOrganizer($person ?? $institution));

            // Attach default language (Malay) if exists
            if (class_exists(Language::class)) {
                $malay = Language::where('code', 'ms')->first();
                if ($malay) {
                    $event->languages()->firstOrCreate([
                        'language_code' => 'ms',
                        'usage_type' => 'presentation',
                    ]);
                }
            }

            app(EventKeyPersonSyncService::class)->sync(
                $event,
                $person instanceof Person ? [$person->id] : [],
            );

            $this->ensureScheduleEventHasTags($event, $title, $topic);

        }
    }

    private function resolveSchedulePerson(?string $personName, ?Event $existingScheduleEvent = null): ?Person
    {
        if ($existingScheduleEvent instanceof Event) {
            if (! is_string($personName) || $personName === '') {
                return null;
            }

            $organizerInvolveable = $existingScheduleEvent->primaryOrganizerInvolvement?->involveable;
            $organizerPerson = $organizerInvolveable instanceof Person ? $organizerInvolveable : null;

            if ($organizerPerson instanceof Person && $organizerPerson->name === $personName) {
                app(GeneratePersonSlugAction::class)->syncPersonSlug(
                    $organizerPerson->loadMissing('addresses.country'),
                );
                app(GenerateEventSlugAction::class)->syncEventSlugsForPersonId((string) $organizerPerson->getKey());
                $this->schedulePersonIds[$personName] = (string) $organizerPerson->getKey();

                $organizerPerson->refresh();

                return $organizerPerson;
            }

            $existingPerson = $existingScheduleEvent->persons()->first();

            if (
                $existingPerson instanceof Person
                && $existingPerson->name === $personName
            ) {
                app(GeneratePersonSlugAction::class)->syncPersonSlug(
                    $existingPerson->loadMissing('addresses.country'),
                );
                app(GenerateEventSlugAction::class)->syncEventSlugsForPersonId((string) $existingPerson->getKey());
                $this->schedulePersonIds[$personName] = (string) $existingPerson->getKey();

                $existingPerson->refresh();

                return $existingPerson;
            }
        }

        if (! is_string($personName) || $personName === '') {
            return null;
        }

        if (isset($this->schedulePersonIds[$personName])) {
            $cachedPerson = Person::query()->find($this->schedulePersonIds[$personName]);

            if ($cachedPerson instanceof Person) {
                return $cachedPerson;
            }

            unset($this->schedulePersonIds[$personName]);
        }

        $createdPerson = Person::query()->create([
            'name' => $personName,
            'middle_name' => null,
            'family_name' => null,
            'slug' => app(GeneratePersonSlugAction::class)->handle($personName),
            'status' => 'verified',
        ]);

        $this->schedulePersonIds[$personName] = (string) $createdPerson->getKey();

        return $createdPerson;
    }

    /**
     * @param  array<string, mixed>  $eventAttributes
     */
    private function resolveExistingScheduleEvent(array $eventAttributes, ?string $personName): ?Event
    {
        $matchingEvents = Event::query()
            ->with(['keyPeople.person'])
            ->where('title', $eventAttributes['title'])
            ->whereHas('occurrences', function ($query) use ($eventAttributes): void {
                $query->where('starts_at', $eventAttributes['starts_at']);
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Event $event): bool => $event->schedule_kind === $eventAttributes['schedule_kind']);

        if (is_string($personName) && $personName !== '') {
            $matchedEvent = $matchingEvents->first(fn (Event $event): bool => $event->keyPeople
                ->where('role_code', EventKeyPersonRole::Speaker->value)
                ->contains(function (mixed $keyPerson) use ($personName): bool {
                    $person = $keyPerson->person;

                    return (is_string($keyPerson->display_name) && $keyPerson->display_name === $personName)
                        || ($person instanceof Person && $person->name === $personName);
                }));

            if ($matchedEvent instanceof Event) {
                return $matchedEvent;
            }

            $organizerMatchedEvent = $matchingEvents->first(function (Event $event) use ($personName): bool {
                $organizerPerson = $event->primaryOrganizerInvolvement?->involveable;

                return $organizerPerson instanceof Person && $organizerPerson->name === $personName;
            });

            if ($organizerMatchedEvent instanceof Event) {
                return $organizerMatchedEvent;
            }
        }

        if (! is_string($personName) || $personName === '') {
            $noPersonEvent = $matchingEvents->first(fn (Event $event): bool => $event->keyPeople
                ->where('role_code', EventKeyPersonRole::Speaker->value)
                ->isEmpty());

            if ($noPersonEvent instanceof Event) {
                return $noPersonEvent;
            }
        }

        return $matchingEvents->count() === 1 ? $matchingEvents->first() : null;
    }

    private function ensureScheduleEventHasTags(Event $event, string $title, ?string $topic): void
    {
        $existingTaxonomyCodes = $event->classifications()
            ->pluck('taxonomy_code')
            ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
            ->unique()
            ->values();

        $hasRequiredTypes = $existingTaxonomyCodes->contains(EventTaxonomyCode::Domain->value)
            && $existingTaxonomyCodes->contains(EventTaxonomyCode::Discipline->value);

        if ($hasRequiredTypes) {
            return;
        }

        $payload = $this->resolveSeedTaxonomyPayload($title, $topic);

        if ($payload === []) {
            return;
        }

        app(SyncEventClassificationsAction::class)->handle($event, $payload);
    }

    /**
     * @return array{
     *     domain_tags?: list<string>,
     *     discipline_tags?: list<string>,
     *     source_tags?: list<string>,
     *     issue_tags?: list<string>
     * }
     */
    private function resolveSeedTaxonomyPayload(string $title, ?string $topic): array
    {
        $haystack = mb_strtolower(trim($title.' '.($topic ?? '')));

        $domainSlug = 'agama_kerohanian';
        $disciplineSlug = 'hadith_studies';
        $sourceSlug = 'hadith';
        $issueSlug = null;

        if (
            str_contains($haystack, 'tafsir') ||
            str_contains($haystack, 'quran') ||
            str_contains($haystack, 'qur\'an') ||
            str_contains($haystack, 'tadabbur')
        ) {
            $disciplineSlug = str_contains($haystack, 'tadabbur') ? 'tadabbur' : 'tafsir';
            $sourceSlug = 'quran';
        } elseif (
            str_contains($haystack, 'adab') ||
            str_contains($haystack, 'akhlak') ||
            str_contains($haystack, 'tazkiyah') ||
            str_contains($haystack, 'hikam')
        ) {
            $disciplineSlug = str_contains($haystack, 'tazkiyah') ? 'tazkiyah' : 'adab_akhlaq';
            $sourceSlug = 'turath';
        } elseif (str_contains($haystack, 'sirah')) {
            $disciplineSlug = 'sirah';
            $sourceSlug = 'hadith';
            $issueSlug = 'kepimpinan';
        } elseif (
            str_contains($haystack, 'fiqh') ||
            str_contains($haystack, 'solat') ||
            str_contains($haystack, 'zakat') ||
            str_contains($haystack, 'puasa')
        ) {
            $disciplineSlug = 'ibadah';
            $sourceSlug = 'hadith';
        }

        if (str_contains($haystack, 'keluarga') || str_contains($haystack, 'keibubapaan')) {
            $issueSlug = 'keluarga';
        }

        $payload = [
            'domain_tags' => [$domainSlug],
            'discipline_tags' => [$disciplineSlug],
            'source_tags' => [$sourceSlug],
        ];

        if ($issueSlug !== null) {
            $payload['issue_tags'] = [$issueSlug];
        }

        return $payload;
    }

    private function backfillSeededEventRequiredFields(): void
    {
        Event::query()
            ->with([
                'persons:id',
                'classifications',
                'primaryOrganizerInvolvement',
                'primaryLocation.venueSpace',
            ])
            ->chunk(200, function (Collection $events): void {
                foreach ($events as $event) {
                    $updates = [];

                    $ageGroup = $event->age_group;
                    $hasAgeGroup = $ageGroup instanceof Collection && $ageGroup->isNotEmpty();

                    if (! $hasAgeGroup) {
                        $updates['age_group'] = [EventAgeGroup::AllAges->value];
                    }

                    $hasInstitutionLocation = is_string($event->institution_id) && $event->institution_id !== '';
                    $hasVenueLocation = is_string($event->default_venue_id) && $event->default_venue_id !== '';
                    $hasSpace = is_string($event->primaryLocation?->venue_space_id) && $event->primaryLocation->venue_space_id !== '';
                    $spaceId = $hasSpace ? $event->primaryLocation->venue_space_id : null;
                    $eventFormat = $event->delivery_mode;
                    $isOnlineEvent = $eventFormat === EventFormat::Online
                        || (is_string($eventFormat) && $eventFormat === EventFormat::Online->value);

                    // Enforce location invariant for seeded rows:
                    // - online: no physical location fields at all.
                    // - non-online: location is institution XOR venue, never both.
                    if ($isOnlineEvent) {
                        if ($hasInstitutionLocation || $hasVenueLocation || $hasSpace) {
                            $updates['institution_id'] = null;
                            $updates['default_venue_id'] = null;
                            $spaceId = null;
                        }
                    } elseif ($hasInstitutionLocation && $hasVenueLocation) {
                        if ($hasSpace) {
                            // Space belongs to institution location.
                            $updates['default_venue_id'] = null;
                        } else {
                            // Default conflict resolution: keep venue location.
                            $updates['institution_id'] = null;
                        }
                    }

                    if ($event->primaryOrganizerInvolvement === null) {
                        $firstPerson = $event->persons->first();

                        $organizer = match (true) {
                            $firstPerson !== null => $firstPerson,
                            ! empty($event->institution_id) => Institution::query()->find($event->institution_id),
                            default => null,
                        };

                        if ($organizer !== null) {
                            OwnerContext::withOwner(null, fn () => $event->setPrimaryOrganizer($organizer));
                        }
                    }

                    if ($updates !== []) {
                        $event->fill($updates)->save();
                    }

                    if (! $isOnlineEvent && $spaceId === null) {
                        if (is_string($event->institution_id) && $event->institution_id !== '') {
                            $institution = Institution::query()->find($event->institution_id);
                            $spaceId = $institution instanceof Institution
                                ? (string) $this->seededInstitutionEventSpace($institution)->getKey()
                                : null;
                        } elseif (is_string($event->default_venue_id) && $event->default_venue_id !== '') {
                            $venue = Venue::query()->find($event->default_venue_id);
                            $spaceId = $venue instanceof Venue
                                ? (string) $this->seededVenueEventSpace($venue)->getKey()
                                : null;
                        }
                    }

                    $event->syncLocation($event->default_venue_id, $spaceId !== null ? [$spaceId] : []);

                    $taxonomyCodes = $event->classifications
                        ->pluck('taxonomy_code')
                        ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
                        ->unique()
                        ->values();

                    $hasRequiredTaxonomies = $taxonomyCodes->contains(EventTaxonomyCode::Domain->value)
                        && $taxonomyCodes->contains(EventTaxonomyCode::Discipline->value);

                    if ($hasRequiredTaxonomies) {
                        continue;
                    }

                    app(SyncEventClassificationsAction::class)->handle($event, [
                        'domain_tags' => ['agama_kerohanian'],
                        'discipline_tags' => ['hadith_studies'],
                        'source_tags' => ['hadith'],
                    ]);
                }
            });
    }

    /**
     * @param  list<string>  $personIds
     */
    private function seedKeyPeopleForEvent(Event $event, array $personIds): void
    {
        $categoryCatalog = app(EventCategoryCatalog::class);
        $categoryTerms = collect($categoryCatalog->terms($categoryCatalog->descendantIds($event->event_category_ids)));
        $personRoleRequired = app(EventCategoryPolicyResolver::class)->requiresSpeaker($event->event_category_ids);
        $selectedPersonIds = [];

        if ($personRoleRequired && $personIds !== []) {
            shuffle($personIds);
            $selectedPersonIds = array_slice($personIds, 0, random_int(1, min(2, count($personIds))));
        }

        $otherKeyPeople = [];

        if ($categoryTerms->contains('code', 'forum_diskusi')) {
            $moderatorPersonId = $selectedPersonIds[0] ?? ($personIds[0] ?? null);

            if (is_string($moderatorPersonId)) {
                $otherKeyPeople[] = [
                    'role_code' => EventKeyPersonRole::Moderator->value,
                    'involveable_type' => 'person',
                    'involveable_id' => $moderatorPersonId,
                    'display_name' => null,
                    'visibility' => 'public',
                ];
            }
        }

        if ($categoryTerms->contains('code', 'aktiviti_keagamaan')) {
            $imamPersonId = $personIds[0] ?? null;

            $otherKeyPeople[] = [
                'role_code' => EventKeyPersonRole::Imam->value,
                'involveable_type' => is_string($imamPersonId) ? 'person' : null,
                'involveable_id' => is_string($imamPersonId) ? $imamPersonId : null,
                'display_name' => is_string($imamPersonId) ? null : fake()->name(),
                'visibility' => 'public',
            ];
        }

        if (app(EventCategoryPolicyResolver::class)->requiresPhysicalDelivery($event->event_category_ids)) {
            $otherKeyPeople[] = [
                'role_code' => EventKeyPersonRole::PersonInCharge->value,
                'involveable_type' => null,
                'involveable_id' => null,
                'display_name' => fake()->name(),
                'visibility' => 'public',
            ];
        }

        app(EventKeyPersonSyncService::class)->sync($event, $selectedPersonIds, $otherKeyPeople);
    }
}
