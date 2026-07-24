<?php

namespace Database\Seeders;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Events\Enums\ScheduleKind;
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
use App\Models\Person;
use App\Models\Series;
use App\Models\Venue;
use App\Services\EventKeyPersonSyncService;
use Database\Seeders\Concerns\SeedsPackageAddresses;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nnjeim\World\Models\Language;

class EventSeeder extends Seeder
{
    use SeedsPackageAddresses;

    /**
     * @var array<string, string>
     */

    /**
     * @var array<string, string>
     */
    private array $scheduleSpeakerIds = [];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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
            $speakerIds = Person::query()->pluck('id')->toArray();
            $venueIds = Venue::query()->pluck('id')->toArray();

            if ($institutions->isEmpty()) {
                return;
            }

            $count = 0;
            $limit = 850; // We already have ~50 from seedIlmu360Schedule

            foreach ($institutions as $institution) {
                if ($count >= $limit) {
                    break;
                }

                $randomSeriesId = empty($seriesIds) ? null : $seriesIds[array_rand($seriesIds)];
                $randomVenueId = empty($venueIds) ? null : $venueIds[array_rand($venueIds)];

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

                        continue;
                    }

                    $useVenueLocation = $randomVenueId !== null && random_int(0, 1) === 1;

                    if ($useVenueLocation) {
                        $event->update([
                            'institution_id' => null,
                            'default_venue_id' => $randomVenueId,
                        ]);
                    } else {
                        $event->update([
                            'institution_id' => $institution->id,
                            'default_venue_id' => null,
                        ]);
                    }

                    $this->seedKeyPeopleForEvent($event, $speakerIds);
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
                $speakerKeyPeople = [];

                foreach ($events as $event) {
                    // Randomly select 1-3 speakers
                    if (! empty($speakerIds)) {
                        $numSpeakers = min(random_int(1, 3), count($speakerIds));
                        $selectedSpeakers = (array) array_rand(array_flip($speakerIds), $numSpeakers);
                        foreach (array_values($selectedSpeakers) as $index => $speakerId) {
                            $speakerKeyPeople[] = [
                                'id' => (string) Str::uuid(),
                                'event_id' => $event->id,
                                'involveable_type' => 'person',
                                'involveable_id' => $speakerId,
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

                // Bulk insert speaker key people
                if ($speakerKeyPeople !== []) {
                    DB::table('event_involvements')->insert($speakerKeyPeople);
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
                'description' => 'Jadual kuliah Januari 2026.',
                'status' => 'verified',
            ]);
        }

        OwnerContext::withOwner($institution, function () use ($institution): void {
            $institution->contactMethods()->firstOrCreate(
                ['type' => ContactMethodType::Email->value],
                ['value' => 'mtajbj@gmail.com', 'purpose' => ContactPurpose::General->value]
            );

            $institution->contactMethods()->firstOrCreate(
                ['type' => ContactMethodType::Phone->value],
                ['value' => '03-78313641', 'purpose' => ContactPurpose::General->value]
            );
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
            ], $state, $district, $subdistrict));
        }

        $venue = Venue::query()
            ->whereIn('slug', ['dewan-solat-utama-mtaj', 'dewan-solat-utama'])
            ->orWhere('name', 'Dewan Solat Utama')
            ->first();

        if (! $venue instanceof Venue) {
            $venue = Venue::query()->create([
                'name' => 'Dewan Solat Utama',
                'slug' => 'dewan-solat-utama-mtaj',
                'venue_type' => 'dewan',
                'status' => 'verified',
                'visibility' => 'public',
            ]);
        }

        if (! $venue->primaryAddress()) {
            $institutionAddress = $institution->primaryAddress();

            $this->seedPrimaryPackageAddress($venue, [
                'line1' => $institutionAddress?->line1,
                'city' => $institutionAddress?->city,
                'country_id' => $institutionAddress->country_id ?? $malaysia?->id,
                'state_id' => $institutionAddress?->state_id,
                'city_id' => $institutionAddress?->city_id,
                'admin_area_1_id' => $institutionAddress?->admin_area_1_id,
                'admin_area_2_id' => $institutionAddress?->admin_area_2_id,
                'admin_area_3_id' => null,
                'admin_area_4_id' => null,
                'latitude' => $institutionAddress?->latitude,
                'longitude' => $institutionAddress?->longitude,
            ]);
        }

        $schedule = [
            ['date' => '2026-01-05', 'slot' => 'Dhuha', 'time' => '10:30', 'speaker' => 'Ust Mukhlisur Riyadus', 'topic' => 'Adab Iman'],
            ['date' => '2026-01-05', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Mohd Aris Johari', 'topic' => 'Tafsir Juz Amma (Surah Jasim)'],
            ['date' => '2026-01-12', 'slot' => 'Dhuha', 'time' => '10:00', 'speaker' => 'Ust Muhd Zulkifli', 'topic' => 'Kitab Idaman Penuntut Ilmu'],
            ['date' => '2026-01-12', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Mohd Faiz al-Izzani', 'topic' => 'Tafsir Juz Amma'],
            ['date' => '2026-01-19', 'slot' => 'Dhuha', 'time' => '10:00', 'speaker' => 'Ust Mohd Nazri Abdul Razak', 'topic' => 'Berusrah Bersama'],
            ['date' => '2026-01-19', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Anuar Harun', 'topic' => 'Tafsir Juz Amma'],
            ['date' => '2026-01-26', 'slot' => 'Dhuha', 'time' => '10:00', 'speaker' => 'Ust Mohd Nazri Abdul Razak', 'topic' => 'Berusrah Bersama'],
            ['date' => '2026-01-26', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Fawwaz Mohd Nur', 'topic' => 'Tafsir Juz Amma'],

            ['date' => '2026-01-06', 'slot' => 'Quran Time', 'time' => '08:45', 'speaker' => 'Ust Adi Hamman Mahwi', 'topic' => 'Quran Time'],
            ['date' => '2026-01-06', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Dr Adnin Ramly'],
            ['date' => '2026-01-13', 'slot' => 'Dhuha', 'time' => '10:30', 'speaker' => 'Ust Abdul Khair Zaki'],
            ['date' => '2026-01-13', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Abu Hazim'],
            ['date' => '2026-01-20', 'slot' => 'Quran Time', 'time' => '08:45', 'speaker' => 'Ust Adi Hamman Mahwi', 'topic' => 'Quran Time'],
            ['date' => '2026-01-20', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Ebit Lew'],
            ['date' => '2026-01-27', 'slot' => 'Talaqqi al-Quran', 'time' => '10:30', 'speaker' => 'Ust Izani Zulkifli', 'topic' => 'Talaqqi al-Quran'],
            ['date' => '2026-01-27', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Dr Zulkifli Mohamad al-Bakri'],

            ['date' => '2026-01-07', 'slot' => 'Dhuha', 'time' => '10:00', 'speaker' => 'Ust Muhd Zulkifli', 'topic' => 'Kitab Idaman Penuntut Ilmu'],
            ['date' => '2026-01-07', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Dato Dr Danial Zainal Abidin'],
            ['date' => '2026-01-14', 'slot' => 'Dhuha', 'time' => '10:00', 'speaker' => 'Ust Muhamad Azmi', 'topic' => 'Kitab Nuru al-Iqna Masail Taharah'],
            ['date' => '2026-01-14', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Syed Mohd Shahabuddin'],
            ['date' => '2026-01-21', 'slot' => 'Dhuha', 'time' => '10:00', 'speaker' => 'Ust Muhamad Azmi'],
            ['date' => '2026-01-21', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Hj Zakaria Othman'],
            ['date' => '2026-01-28', 'slot' => 'Dhuha', 'time' => '10:00', 'speaker' => 'Ust Muhd Azmi'],
            ['date' => '2026-01-28', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Jamil Hashim'],

            ['date' => '2026-01-01', 'slot' => 'Maghrib', 'time' => '20:00', 'topic' => 'Bacaan Yasin & Tazkirah'],
            ['date' => '2026-01-08', 'slot' => 'Dhuha', 'time' => '10:30', 'speaker' => 'Ust Muhd Izudin Salem', 'topic' => 'Hadis Riyadus Solihin'],
            ['date' => '2026-01-08', 'slot' => 'Maghrib', 'time' => '20:00', 'topic' => 'Bacaan Yasin & Tazkirah'],
            ['date' => '2026-01-15', 'slot' => 'Dhuha', 'time' => '10:30', 'speaker' => 'Puan Farhana Abdul Ghani', 'topic' => 'Hikam ke-140'],
            ['date' => '2026-01-15', 'slot' => 'Maghrib', 'time' => '20:00', 'topic' => 'Bacaan Yasin & Tazkirah'],
            ['date' => '2026-01-22', 'slot' => 'Dhuha', 'time' => '10:30', 'speaker' => 'Dr Azman Shah Alias'],
            ['date' => '2026-01-22', 'slot' => 'Maghrib', 'time' => '20:00', 'topic' => 'Bacaan Yasin & Tazkirah'],
            ['date' => '2026-01-29', 'slot' => 'Dhuha', 'time' => '10:30', 'speaker' => 'Ust Anas Mohd'],
            ['date' => '2026-01-29', 'slot' => 'Maghrib', 'time' => '20:00', 'topic' => 'Bacaan Yasin & Tazkirah'],

            ['date' => '2026-01-02', 'slot' => 'Dhuha', 'time' => '10:00', 'note' => 'Ditangguhkan', 'topic' => 'Kuliah Dhuha'],
            ['date' => '2026-01-02', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Akid Shafie'],
            ['date' => '2026-01-09', 'slot' => 'Dhuha', 'time' => '10:00', 'speaker' => 'Ust Ahmad Fawwaz Zaidon'],
            ['date' => '2026-01-09', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Adnin Ramly'],
            ['date' => '2026-01-16', 'slot' => 'Dhuha', 'time' => '10:00', 'speaker' => 'Dato Dr Najmuddin'],
            ['date' => '2026-01-16', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Ahmad Saffwan'],
            ['date' => '2026-01-23', 'slot' => 'Dhuha', 'time' => '10:00', 'speaker' => 'Dato Dr Mohd Radzi'],
            ['date' => '2026-01-23', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Dato Dr Ahmad Zaki'],
            ['date' => '2026-01-30', 'slot' => 'Dhuha', 'time' => '10:00', 'speaker' => 'Dr Mizan Mohamed'],
            ['date' => '2026-01-30', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Jamil Hashim'],

            ['date' => '2026-01-03', 'slot' => 'Subuh', 'time' => '05:45', 'speaker' => 'Ust Roslan Mohamed'],
            ['date' => '2026-01-03', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Fahmi Ideris'],
            ['date' => '2026-01-10', 'slot' => 'Subuh', 'time' => '05:45', 'speaker' => 'Ust Radzi Shahari'],
            ['date' => '2026-01-10', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Ahmad Anwar'],
            ['date' => '2026-01-17', 'slot' => 'Subuh', 'time' => '05:45', 'speaker' => 'Ust Ahmad Rosli'],
            ['date' => '2026-01-17', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Mohd Sufyan'],
            ['date' => '2026-01-24', 'slot' => 'Subuh', 'time' => '05:45', 'speaker' => 'Ust Mohd Shah Rizal'],
            ['date' => '2026-01-24', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Dr Fathullah Kani'],
            ['date' => '2026-01-31', 'slot' => 'Maghrib', 'time' => '20:00', 'note' => 'Dibatalkan', 'topic' => 'Kuliah Maghrib'],

            ['date' => '2026-01-04', 'slot' => 'Subuh', 'time' => '05:45', 'speaker' => 'Mufti Wilayah Persekutuan'],
            ['date' => '2026-01-04', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Imam Muda Hassan'],
            ['date' => '2026-01-11', 'slot' => 'Subuh', 'time' => '05:45', 'speaker' => 'Dato Prof Dr Basri Ibrahim'],
            ['date' => '2026-01-11', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Ahmad Anwar'],
            ['date' => '2026-01-18', 'slot' => 'Subuh', 'time' => '05:45', 'speaker' => 'Dato Seri Zulkifli al-Bakri'],
            ['date' => '2026-01-18', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Ibrahim Zamzibar'],
            ['date' => '2026-01-25', 'slot' => 'Subuh', 'time' => '05:45', 'speaker' => 'Dato Dr Danial Zainal Abidin'],
            ['date' => '2026-01-25', 'slot' => 'Maghrib', 'time' => '20:00', 'speaker' => 'Ust Azhar Idrus'],
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
            if (isset($entry['speaker'])) {
                $descriptionParts[] = 'Bersama '.$entry['speaker'];
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

            $eventAttributes = [
                'institution_id' => null,
                'default_venue_id' => $venue->id,
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

            $existingScheduleEvent = $this->resolveExistingScheduleEvent($eventAttributes, $entry['speaker'] ?? null);
            $speaker = $this->resolveScheduleSpeaker($entry['speaker'] ?? null, $existingScheduleEvent);

            $eventAttributes['slug'] = app(GenerateEventSlugAction::class)->handle(
                $title,
                $entry['date'],
                'Asia/Kuala_Lumpur',
                $existingScheduleEvent?->getKey() !== null ? (string) $existingScheduleEvent->getKey() : null,
                $speaker instanceof Person && is_string($speaker->slug) && $speaker->slug !== ''
                    ? [$speaker->slug]
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

            OwnerContext::withOwner(null, fn () => $event->setPrimaryOrganizer($speaker ?? $institution));

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
                $speaker instanceof Person ? [$speaker->id] : [],
            );

            $this->ensureScheduleEventHasTags($event, $title, $topic);

        }
    }

    private function resolveScheduleSpeaker(?string $speakerName, ?Event $existingScheduleEvent = null): ?Person
    {
        if ($existingScheduleEvent instanceof Event) {
            if (! is_string($speakerName) || $speakerName === '') {
                return null;
            }

            $organizerInvolveable = $existingScheduleEvent->primaryOrganizerInvolvement?->involveable;
            $organizerPerson = $organizerInvolveable instanceof Person ? $organizerInvolveable : null;

            if ($organizerPerson instanceof Person && $organizerPerson->name === $speakerName) {
                app(GeneratePersonSlugAction::class)->syncSpeakerSlug(
                    $organizerPerson->loadMissing('addresses.country'),
                );
                app(GenerateEventSlugAction::class)->syncEventSlugsForSpeakerId((string) $organizerPerson->getKey());
                $this->scheduleSpeakerIds[$speakerName] = (string) $organizerPerson->getKey();

                $organizerPerson->refresh();

                return $organizerPerson;
            }

            $existingPerson = $existingScheduleEvent->persons()->first();

            if (
                $existingPerson instanceof Person
                && $existingPerson->name === $speakerName
            ) {
                app(GeneratePersonSlugAction::class)->syncSpeakerSlug(
                    $existingPerson->loadMissing('addresses.country'),
                );
                app(GenerateEventSlugAction::class)->syncEventSlugsForSpeakerId((string) $existingPerson->getKey());
                $this->scheduleSpeakerIds[$speakerName] = (string) $existingPerson->getKey();

                $existingPerson->refresh();

                return $existingPerson;
            }
        }

        if (! is_string($speakerName) || $speakerName === '') {
            return null;
        }

        if (isset($this->scheduleSpeakerIds[$speakerName])) {
            $cachedPerson = Person::query()->find($this->scheduleSpeakerIds[$speakerName]);

            if ($cachedPerson instanceof Person) {
                return $cachedPerson;
            }

            unset($this->scheduleSpeakerIds[$speakerName]);
        }

        $createdPerson = Person::query()->create([
            'name' => $speakerName,
            'slug' => app(GeneratePersonSlugAction::class)->handle($speakerName),
            'status' => 'verified',
        ]);

        $this->scheduleSpeakerIds[$speakerName] = (string) $createdPerson->getKey();

        return $createdPerson;
    }

    /**
     * @param  array<string, mixed>  $eventAttributes
     */
    private function resolveExistingScheduleEvent(array $eventAttributes, ?string $speakerName): ?Event
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

        if (is_string($speakerName) && $speakerName !== '') {
            $matchedEvent = $matchingEvents->first(fn (Event $event): bool => $event->keyPeople
                ->where('role_code', EventKeyPersonRole::Speaker->value)
                ->contains(function (mixed $keyPerson) use ($speakerName): bool {
                    $person = $keyPerson->person;

                    return (is_string($keyPerson->display_name) && $keyPerson->display_name === $speakerName)
                        || ($person instanceof Person && $person->name === $speakerName);
                }));

            if ($matchedEvent instanceof Event) {
                return $matchedEvent;
            }

            $organizerMatchedEvent = $matchingEvents->first(function (Event $event) use ($speakerName): bool {
                $organizerPerson = $event->primaryOrganizerInvolvement?->involveable;

                return $organizerPerson instanceof Person && $organizerPerson->name === $speakerName;
            });

            if ($organizerMatchedEvent instanceof Event) {
                return $organizerMatchedEvent;
            }
        }

        if (! is_string($speakerName) || $speakerName === '') {
            $noSpeakerEvent = $matchingEvents->first(fn (Event $event): bool => $event->keyPeople
                ->where('role_code', EventKeyPersonRole::Speaker->value)
                ->isEmpty());

            if ($noSpeakerEvent instanceof Event) {
                return $noSpeakerEvent;
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

        $domainSlug = 'syariah';
        $disciplineSlug = 'hadith_studies';
        $sourceSlug = 'hadith';
        $issueSlug = null;

        if (
            str_contains($haystack, 'tafsir') ||
            str_contains($haystack, 'quran') ||
            str_contains($haystack, 'qur\'an') ||
            str_contains($haystack, 'tadabbur')
        ) {
            $domainSlug = 'aqidah';
            $disciplineSlug = str_contains($haystack, 'tadabbur') ? 'tadabbur' : 'tafsir';
            $sourceSlug = 'quran';
        } elseif (
            str_contains($haystack, 'adab') ||
            str_contains($haystack, 'akhlak') ||
            str_contains($haystack, 'tazkiyah') ||
            str_contains($haystack, 'hikam')
        ) {
            $domainSlug = 'akhlak';
            $disciplineSlug = str_contains($haystack, 'tazkiyah') ? 'tazkiyah' : 'adab_akhlaq';
            $sourceSlug = 'turath';
        } elseif (str_contains($haystack, 'sirah')) {
            $domainSlug = 'aqidah';
            $disciplineSlug = 'sirah';
            $sourceSlug = 'hadith';
            $issueSlug = 'kepimpinan';
        } elseif (
            str_contains($haystack, 'fiqh') ||
            str_contains($haystack, 'solat') ||
            str_contains($haystack, 'zakat') ||
            str_contains($haystack, 'puasa')
        ) {
            $domainSlug = 'syariah';
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
                    } elseif ($hasVenueLocation && $hasSpace) {
                        // Space is only valid for institution-based locations.
                        $spaceId = null;
                    }

                    if ($event->primaryOrganizerInvolvement === null) {
                        $firstSpeaker = $event->persons->first();

                        $organizer = match (true) {
                            $firstSpeaker !== null => $firstSpeaker,
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
                        'domain_tags' => ['syariah'],
                        'discipline_tags' => ['hadith_studies'],
                        'source_tags' => ['hadith'],
                    ]);
                }
            });
    }

    /**
     * @param  list<string>  $speakerIds
     */
    private function seedKeyPeopleForEvent(Event $event, array $speakerIds): void
    {
        $categoryCatalog = app(EventCategoryCatalog::class);
        $categoryTerms = collect($categoryCatalog->terms($categoryCatalog->descendantIds($event->event_category_ids)));
        $speakerRoleRequired = app(EventCategoryPolicyResolver::class)->requiresSpeaker($event->event_category_ids);
        $selectedSpeakerIds = [];

        if ($speakerRoleRequired && $speakerIds !== []) {
            shuffle($speakerIds);
            $selectedSpeakerIds = array_slice($speakerIds, 0, random_int(1, min(2, count($speakerIds))));
        }

        $otherKeyPeople = [];

        if ($categoryTerms->contains('code', 'forum')) {
            $moderatorSpeakerId = $selectedSpeakerIds[0] ?? ($speakerIds[0] ?? null);

            if (is_string($moderatorSpeakerId)) {
                $otherKeyPeople[] = [
                    'role_code' => EventKeyPersonRole::Moderator->value,
                    'involveable_type' => 'speaker',
                    'involveable_id' => $moderatorSpeakerId,
                    'display_name' => null,
                    'visibility' => 'public',
                ];
            }
        }

        if ($categoryTerms->whereIn('code', ['tahlil', 'solat_hajat', 'qiamullail'])->isNotEmpty()) {
            $imamSpeakerId = $speakerIds[0] ?? null;

            $otherKeyPeople[] = [
                'role_code' => EventKeyPersonRole::Imam->value,
                'involveable_type' => is_string($imamSpeakerId) ? 'person' : null,
                'involveable_id' => is_string($imamSpeakerId) ? $imamSpeakerId : null,
                'display_name' => is_string($imamSpeakerId) ? null : fake()->name(),
                'visibility' => 'public',
            ];
        }

        if ($categoryTerms->contains('code', 'khutbah_jumaat')) {
            $khatibSpeakerId = $speakerIds[0] ?? null;
            $imamSpeakerId = $speakerIds[1] ?? $khatibSpeakerId;

            $otherKeyPeople[] = [
                'role_code' => EventKeyPersonRole::Khatib->value,
                'involveable_type' => is_string($khatibSpeakerId) ? 'person' : null,
                'involveable_id' => is_string($khatibSpeakerId) ? $khatibSpeakerId : null,
                'display_name' => is_string($khatibSpeakerId) ? null : fake()->name(),
                'visibility' => 'public',
            ];
            $otherKeyPeople[] = [
                'role_code' => EventKeyPersonRole::Imam->value,
                'involveable_type' => is_string($imamSpeakerId) ? 'person' : null,
                'involveable_id' => is_string($imamSpeakerId) ? $imamSpeakerId : null,
                'display_name' => is_string($imamSpeakerId) ? null : fake()->name(),
                'visibility' => 'public',
            ];
            $otherKeyPeople[] = [
                'role_code' => EventKeyPersonRole::Bilal->value,
                'involveable_type' => null,
                'involveable_id' => null,
                'display_name' => fake()->name(),
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

        app(EventKeyPersonSyncService::class)->sync($event, $selectedSpeakerIds, $otherKeyPeople);
    }
}
