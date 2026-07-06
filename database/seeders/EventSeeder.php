<?php

namespace Database\Seeders;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Speakers\GenerateSpeakerSlugAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\ScheduleKind;
use App\Enums\ScheduleState;
use App\Enums\TagType;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Series;
use App\Models\Speaker;
use App\Models\Tag;
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
    private array $tagIdMap = [];

    /**
     * @var array<string, string>
     */
    private array $scheduleSpeakerIds = [];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $hadEvents = Event::query()->exists();

        $this->seedIlmu360Schedule();

        if (! $hadEvents) {
            $this->seedBulkEvents();
        }

        $this->backfillSeededEventRequiredFields();
    }

    private function seedBulkEvents(): void
    {

        DB::transaction(function (): void {
            $institutions = Institution::query()
                ->limit(90)
                ->get();
            $seriesIds = Series::query()->pluck('id')->toArray();
            $speakerIds = Speaker::query()->pluck('id')->toArray();
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
                    'venue_id' => null,
                    'space_id' => null,
                ]);

                // Ensure seeded events follow location invariant:
                // - online: no physical location
                // - non-online: institution XOR venue
                foreach ($events as $event) {
                    if ($event->event_format === EventFormat::Online) {
                        $event->update([
                            'institution_id' => null,
                            'venue_id' => null,
                            'space_id' => null,
                        ]);

                        continue;
                    }

                    $useVenueLocation = $randomVenueId !== null && random_int(0, 1) === 1;

                    if ($useVenueLocation) {
                        $event->update([
                            'institution_id' => null,
                            'venue_id' => $randomVenueId,
                            'space_id' => null,
                        ]);
                    } else {
                        $event->update([
                            'institution_id' => $institution->id,
                            'venue_id' => null,
                        ]);
                    }

                    $this->seedKeyPeopleForEvent($event, $speakerIds);
                }

                // If a series exists in the system, attach events via pivot table.
                if ($randomSeriesId) {
                    $order = 1;
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
                                'speaker_id' => $speakerId,
                                'role_code' => EventKeyPersonRole::Speaker->value,
                                'name' => null,
                                'sort_order' => $index + 1,
                                'is_public' => true,
                                'notes' => null,
                                'status' => 'active',
                                'visibility' => 'public',
                                'prominence' => 0,
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

        $institution->contacts()->firstOrCreate(
            ['type' => ContactMethodType::Email->value],
            ['value' => 'mtajbj@gmail.com', 'purpose' => ContactPurpose::General->value]
        );

        $institution->contacts()->firstOrCreate(
            ['type' => ContactMethodType::Phone->value],
            ['value' => '03-78313641', 'purpose' => ContactPurpose::General->value]
        );

        if (! $institution->addressModel) {
            $state = $this->malaysiaAreaByName('Selangor', 1);
            $district = $state instanceof AddressArea ? $this->malaysiaAreaByName('Petaling', 2, $state->id) : null;

            $this->seedPrimaryPackageAddress($institution, [
                'line1' => 'Bukit Jelutong',
                'city' => 'Shah Alam',
                'country_id' => $malaysia?->id,
                'admin_area_1_id' => $state?->id,
                'admin_area_2_id' => $district?->id,
                'latitude' => 3.0991666,
                'longitude' => 101.529892,
            ]);
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
                'is_active' => true,
            ]);
        }

        if (! $venue->addressModel) {
            $institutionAddress = $institution->addressModel;

            $this->seedPrimaryPackageAddress($venue, [
                'line1' => $institutionAddress?->line1,
                'city' => $institutionAddress?->city,
                'country_id' => $institutionAddress->country_id ?? $malaysia?->id,
                'admin_area_1_id' => $institutionAddress?->admin_area_1_id,
                'admin_area_2_id' => $institutionAddress?->admin_area_2_id,
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
                'venue_id' => $venue->id,
                'title' => $title,
                'description' => $descriptionParts !== [] ? implode(' | ', $descriptionParts) : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'schedule_kind' => ScheduleKind::CustomChain->value,
                'schedule_state' => ScheduleState::Active->value,
                'timezone' => 'Asia/Kuala_Lumpur',
                // 'language' has been removed; genre/audience are now event_type/age_group etc
                'event_type' => EventType::KuliahCeramah,
                'gender' => EventGenderRestriction::All,
                'age_group' => [EventAgeGroup::AllAges->value],
                'children_allowed' => true,
                'event_format' => EventFormat::Physical,
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
                $speaker instanceof Speaker && is_string($speaker->slug) && $speaker->slug !== ''
                    ? [$speaker->slug]
                    : [],
            );

            $event = $existingScheduleEvent;

            if ($event instanceof Event) {
                $event->fill($eventAttributes);
                $event->save();
            } else {
                $event = Event::query()->create($eventAttributes);
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
                $speaker instanceof Speaker ? [$speaker->id] : [],
            );

            $this->ensureScheduleEventHasTags($event, $title, $topic);

        }
    }

    private function resolveScheduleSpeaker(?string $speakerName, ?Event $existingScheduleEvent = null): ?Speaker
    {
        if ($existingScheduleEvent instanceof Event) {
            if (! is_string($speakerName) || $speakerName === '') {
                return null;
            }

            $organizerInvolveable = $existingScheduleEvent->primaryOrganizerInvolvement?->involveable;
            $organizerSpeaker = $organizerInvolveable instanceof Speaker ? $organizerInvolveable : null;

            if ($organizerSpeaker instanceof Speaker && $organizerSpeaker->name === $speakerName) {
                app(GenerateSpeakerSlugAction::class)->syncSpeakerSlug(
                    $organizerSpeaker->loadMissing('addresses.country'),
                );
                app(GenerateEventSlugAction::class)->syncEventSlugsForSpeakerId((string) $organizerSpeaker->getKey());
                $this->scheduleSpeakerIds[$speakerName] = (string) $organizerSpeaker->getKey();

                $organizerSpeaker->refresh();

                return $organizerSpeaker;
            }

            $existingSpeaker = $existingScheduleEvent->speakers()->first();

            if (
                $existingSpeaker instanceof Speaker
                && $existingSpeaker->name === $speakerName
            ) {
                app(GenerateSpeakerSlugAction::class)->syncSpeakerSlug(
                    $existingSpeaker->loadMissing('addresses.country'),
                );
                app(GenerateEventSlugAction::class)->syncEventSlugsForSpeakerId((string) $existingSpeaker->getKey());
                $this->scheduleSpeakerIds[$speakerName] = (string) $existingSpeaker->getKey();

                $existingSpeaker->refresh();

                return $existingSpeaker;
            }
        }

        if (! is_string($speakerName) || $speakerName === '') {
            return null;
        }

        if (isset($this->scheduleSpeakerIds[$speakerName])) {
            $cachedSpeaker = Speaker::query()->find($this->scheduleSpeakerIds[$speakerName]);

            if ($cachedSpeaker instanceof Speaker) {
                return $cachedSpeaker;
            }

            unset($this->scheduleSpeakerIds[$speakerName]);
        }

        $createdSpeaker = Speaker::query()->create([
            'name' => $speakerName,
            'slug' => app(GenerateSpeakerSlugAction::class)->handle($speakerName),
            'status' => 'verified',
            'is_active' => true,
        ]);

        $this->scheduleSpeakerIds[$speakerName] = (string) $createdSpeaker->getKey();

        return $createdSpeaker;
    }

    /**
     * @param  array<string, mixed>  $eventAttributes
     */
    private function resolveExistingScheduleEvent(array $eventAttributes, ?string $speakerName): ?Event
    {
        $matchingEvents = Event::query()
            ->with(['keyPeople.speaker'])
            ->where('title', $eventAttributes['title'])
            ->whereHas('occurrences', function ($query) use ($eventAttributes): void {
                $query->where('starts_at', $eventAttributes['starts_at']);
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Event $event): bool => $event->schedule_kind === $eventAttributes['schedule_kind']
                && $event->schedule_state === $eventAttributes['schedule_state']);

        if (is_string($speakerName) && $speakerName !== '') {
            $matchedEvent = $matchingEvents->first(fn (Event $event): bool => $event->keyPeople
                ->where('role', EventKeyPersonRole::Speaker->value)
                ->contains(function (mixed $keyPerson) use ($speakerName): bool {
                    $speaker = $keyPerson->speaker;

                    return (is_string($keyPerson->name) && $keyPerson->name === $speakerName)
                        || ($speaker instanceof Speaker && $speaker->name === $speakerName);
                }));

            if ($matchedEvent instanceof Event) {
                return $matchedEvent;
            }

            $organizerMatchedEvent = $matchingEvents->first(function (Event $event) use ($speakerName): bool {
                $organizerSpeaker = $event->primaryOrganizerInvolvement?->involveable;

                return $organizerSpeaker instanceof Speaker && $organizerSpeaker->name === $speakerName;
            });

            if ($organizerMatchedEvent instanceof Event) {
                return $organizerMatchedEvent;
            }
        }

        if (! is_string($speakerName) || $speakerName === '') {
            $noSpeakerEvent = $matchingEvents->first(fn (Event $event): bool => $event->keyPeople
                ->where('role', EventKeyPersonRole::Speaker->value)
                ->isEmpty());

            if ($noSpeakerEvent instanceof Event) {
                return $noSpeakerEvent;
            }
        }

        return $matchingEvents->count() === 1 ? $matchingEvents->first() : null;
    }

    private function ensureScheduleEventHasTags(Event $event, string $title, ?string $topic): void
    {
        $existingTypes = $event->tags()
            ->pluck('type')
            ->filter(fn (mixed $type): bool => is_string($type) && $type !== '')
            ->unique()
            ->values();

        $hasRequiredTypes = $existingTypes->contains(TagType::Domain->value)
            && $existingTypes->contains(TagType::Discipline->value);

        if ($hasRequiredTypes) {
            return;
        }

        $this->hydrateTagIdMap();

        $selectedTagIds = $this->resolveSeedTagIds($title, $topic);

        if ($selectedTagIds === []) {
            return;
        }

        $combinedTagIds = $event->tags()
            ->pluck('id')
            ->merge($selectedTagIds)
            ->unique()
            ->values()
            ->all();

        $tags = Tag::query()->whereIn('id', $combinedTagIds)->get();

        if ($tags->isEmpty()) {
            return;
        }

        $event->syncTags($tags);
    }

    private function hydrateTagIdMap(): void
    {
        if ($this->tagIdMap !== []) {
            return;
        }

        Tag::query()
            ->whereIn('status', ['verified', 'pending'])
            ->get(['id', 'type', 'slug'])
            ->each(function (Tag $tag): void {
                $slug = $tag->slug;
                $normalizedSlug = null;

                if (is_string($slug)) {
                    $decodedSlug = json_decode($slug, true);
                    if (is_array($decodedSlug) && $decodedSlug !== []) {
                        $normalizedSlug = $decodedSlug['en'] ?? $decodedSlug['ms'] ?? null;
                    } else {
                        $normalizedSlug = $slug;
                    }
                }

                if (is_array($slug) && $slug !== []) {
                    $normalizedSlug = $slug['en'] ?? $slug['ms'] ?? null;
                }

                if (! is_string($normalizedSlug) || $normalizedSlug === '') {
                    return;
                }

                $key = $tag->type.':'.$normalizedSlug;
                $this->tagIdMap[$key] = (string) $tag->id;
            });
    }

    /**
     * @return list<string>
     */
    private function resolveSeedTagIds(string $title, ?string $topic): array
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

        $resolved = [
            $this->tagIdFor(TagType::Domain, $domainSlug) ?? $this->tagIdFor(TagType::Domain, 'syariah'),
            $this->tagIdFor(TagType::Discipline, $disciplineSlug) ?? $this->tagIdFor(TagType::Discipline, 'hadith_studies'),
            $this->tagIdFor(TagType::Source, $sourceSlug) ?? $this->tagIdFor(TagType::Source, 'hadith'),
            $issueSlug ? $this->tagIdFor(TagType::Issue, $issueSlug) : null,
        ];

        /** @var Collection<int, string> $uniqueIds */
        $uniqueIds = collect($resolved)
            ->filter(fn (?string $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        return $uniqueIds->all();
    }

    private function tagIdFor(TagType $type, string $slug): ?string
    {
        return $this->tagIdMap[$type->value.':'.$slug] ?? null;
    }

    private function backfillSeededEventRequiredFields(): void
    {
        $defaultDomainTagId = Tag::query()
            ->where('type', TagType::Domain->value)
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('order_column')
            ->value('id');

        $defaultDisciplineTagId = Tag::query()
            ->where('type', TagType::Discipline->value)
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('order_column')
            ->value('id');

        $defaultSourceTagId = Tag::query()
            ->where('type', TagType::Source->value)
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('order_column')
            ->value('id');

        Event::query()
            ->with([
                'speakers:id',
                'tags:id,type',
                'primaryOrganizerInvolvement',
            ])
            ->chunk(200, function (Collection $events) use (
                $defaultDomainTagId,
                $defaultDisciplineTagId,
                $defaultSourceTagId
            ): void {
                foreach ($events as $event) {
                    $updates = [];

                    $ageGroup = $event->age_group;
                    $hasAgeGroup = $ageGroup instanceof Collection && $ageGroup->isNotEmpty();

                    if (! $hasAgeGroup) {
                        $updates['age_group'] = [EventAgeGroup::AllAges->value];
                    }

                    $hasInstitutionLocation = is_string($event->institution_id) && $event->institution_id !== '';
                    $hasVenueLocation = is_string($event->venue_id) && $event->venue_id !== '';
                    $hasSpace = is_string($event->space_id) && $event->space_id !== '';
                    $eventFormat = $event->event_format;
                    $isOnlineEvent = $eventFormat === EventFormat::Online
                        || (is_string($eventFormat) && $eventFormat === EventFormat::Online->value);

                    // Enforce location invariant for seeded rows:
                    // - online: no physical location fields at all.
                    // - non-online: location is institution XOR venue, never both.
                    if ($isOnlineEvent) {
                        if ($hasInstitutionLocation || $hasVenueLocation || $hasSpace) {
                            $updates['institution_id'] = null;
                            $updates['venue_id'] = null;
                            $updates['space_id'] = null;
                        }
                    } elseif ($hasInstitutionLocation && $hasVenueLocation) {
                        if ($hasSpace) {
                            // Space belongs to institution location.
                            $updates['venue_id'] = null;
                        } else {
                            // Default conflict resolution: keep venue location.
                            $updates['institution_id'] = null;
                        }
                    } elseif ($hasVenueLocation && $hasSpace) {
                        // Space is only valid for institution-based locations.
                        $updates['space_id'] = null;
                    }

                    if ($event->primaryOrganizerInvolvement === null) {
                        $firstSpeaker = $event->speakers->first();

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

                    $tagTypes = $event->tags
                        ->pluck('type')
                        ->filter(fn (mixed $type): bool => is_string($type) && $type !== '')
                        ->unique()
                        ->values();

                    $hasRequiredTagTypes = $tagTypes->contains(TagType::Domain->value)
                        && $tagTypes->contains(TagType::Discipline->value);

                    if ($hasRequiredTagTypes) {
                        continue;
                    }

                    $defaultIds = array_filter([
                        $defaultDomainTagId,
                        $defaultDisciplineTagId,
                        $defaultSourceTagId,
                    ]);

                    if ($defaultIds === []) {
                        continue;
                    }

                    /** @var list<string> $existingTagIds */
                    $existingTagIds = $event->tags->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

                    /** @var list<string> $finalTagIds */
                    $finalTagIds = array_values(array_unique(array_merge($existingTagIds, $defaultIds)));

                    $tags = Tag::query()->whereIn('id', $finalTagIds)->get();

                    if ($tags->isNotEmpty()) {
                        $event->syncTags($tags);
                    }
                }
            });
    }

    /**
     * @param  list<string>  $speakerIds
     */
    private function seedKeyPeopleForEvent(Event $event, array $speakerIds): void
    {
        $eventTypes = $event->event_type instanceof Collection
            ? $event->event_type->all()
            : (is_array($event->event_type) ? $event->event_type : []);

        $normalizedTypes = collect($eventTypes)
            ->map(fn (mixed $type): ?EventType => $type instanceof EventType ? $type : EventType::tryFrom($type))
            ->filter()
            ->values();

        $speakerRoleRequired = $normalizedTypes->contains(fn (EventType $type): bool => $type->requiresSpeakerByDefault());
        $selectedSpeakerIds = [];

        if ($speakerRoleRequired && $speakerIds !== []) {
            shuffle($speakerIds);
            $selectedSpeakerIds = array_slice($speakerIds, 0, random_int(1, min(2, count($speakerIds))));
        }

        $otherKeyPeople = [];

        if ($normalizedTypes->contains(EventType::Forum)) {
            $moderatorSpeakerId = $selectedSpeakerIds[0] ?? ($speakerIds[0] ?? null);

            if (is_string($moderatorSpeakerId)) {
                $otherKeyPeople[] = [
                    'role' => EventKeyPersonRole::Moderator->value,
                    'speaker_id' => $moderatorSpeakerId,
                    'is_public' => true,
                ];
            }
        }

        if ($normalizedTypes->contains(fn (EventType $type): bool => in_array($type, [EventType::Tahlil, EventType::SolatHajat, EventType::Qiamullail], true))) {
            $imamSpeakerId = $speakerIds[0] ?? null;

            $otherKeyPeople[] = [
                'role' => EventKeyPersonRole::Imam->value,
                'speaker_id' => is_string($imamSpeakerId) ? $imamSpeakerId : null,
                'name' => is_string($imamSpeakerId) ? null : fake()->name(),
                'is_public' => true,
            ];
        }

        if ($normalizedTypes->contains(EventType::KhutbahJumaat)) {
            $khatibSpeakerId = $speakerIds[0] ?? null;
            $imamSpeakerId = $speakerIds[1] ?? $khatibSpeakerId;

            $otherKeyPeople[] = [
                'role' => EventKeyPersonRole::Khatib->value,
                'speaker_id' => is_string($khatibSpeakerId) ? $khatibSpeakerId : null,
                'name' => is_string($khatibSpeakerId) ? null : fake()->name(),
                'is_public' => true,
            ];
            $otherKeyPeople[] = [
                'role' => EventKeyPersonRole::Imam->value,
                'speaker_id' => is_string($imamSpeakerId) ? $imamSpeakerId : null,
                'name' => is_string($imamSpeakerId) ? null : fake()->name(),
                'is_public' => true,
            ];
            $otherKeyPeople[] = [
                'role' => EventKeyPersonRole::Bilal->value,
                'name' => fake()->name(),
                'is_public' => true,
            ];
        }

        if ($normalizedTypes->contains(fn (EventType $type): bool => $type->isCommunity())) {
            $otherKeyPeople[] = [
                'role' => EventKeyPersonRole::PersonInCharge->value,
                'name' => fake()->name(),
                'is_public' => true,
            ];
        }

        app(EventKeyPersonSyncService::class)->sync($event, $selectedSpeakerIds, $otherKeyPeople);
    }
}
