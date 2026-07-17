<?php

namespace Database\Seeders;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Actions\Events\SyncEventClassificationsAction;
use App\Contracts\EventCategoryCatalog;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventVisibility;
use App\Enums\ScheduleKind;
use App\Enums\ScheduleState;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Services\EventKeyPersonSyncService;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AdvancedEventSeeder extends Seeder
{
    public function run(): void
    {
        $institution = Institution::query()
            ->where('status', 'verified')
            ->inRandomOrder()
            ->first();

        $speakerIds = Speaker::query()
            ->where('status', 'verified')
            ->inRandomOrder()
            ->limit(6)
            ->pluck('id')
            ->all();

        // Suppress moderation side-effects during seeding
        Event::unsetEventDispatcher();

        try {
            $this->seedWeeklySeries($institution, $speakerIds);
            $this->seedRamadanProgram($institution, $speakerIds);
            $this->seedWeekendIntensive($institution, $speakerIds);
            $this->seedMultiDayStandalone($institution, $speakerIds);
            $this->seedStandaloneSpecialLecture($institution, $speakerIds);
        } finally {
            Event::setEventDispatcher(app('events'));
        }

        $this->command->info('  [AdvancedEventSeeder] Seeded 5 event examples with package occurrences and sessions.');
    }

    /**
     * @param  list<string>  $speakerIds
     */
    private function seedWeeklySeries(?Institution $institution, array $speakerIds): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $firstFriday = now($tz)->next(Carbon::FRIDAY)->setTime(20, 30);

        $parent = $this->makeBaseEvent(
            title: 'Kelas / Daurah: Al-Arba\'in An-Nawawiyyah',
            description: 'Program payung untuk siri pengajian mingguan. Setiap pertemuan direkodkan sebagai sesi dalam occurrence program.',
            scheduleKind: ScheduleKind::CustomChain,
            institution: $institution,
            speakerIds: $speakerIds,
            tz: $tz,
            startsAt: $firstFriday->copy()->utc(),
            endsAt: $firstFriday->copy()->addWeeks(4)->utc(),
        );

        $this->createSession($parent, 'Minggu 1: Pengenalan Hadis', $firstFriday->copy(), $firstFriday->copy()->addHours(2));
        $this->createSession($parent, 'Minggu 2: Hadis Niat', $firstFriday->copy()->addWeek(), $firstFriday->copy()->addWeek()->addHours(2));
        $this->createSession($parent, 'Minggu 3: Hadis Ihsan', $firstFriday->copy()->addWeeks(2), $firstFriday->copy()->addWeeks(2)->addHours(2));
    }

    /**
     * @param  list<string>  $speakerIds
     */
    private function seedRamadanProgram(?Institution $institution, array $speakerIds): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $nightOne = now($tz)->addDays(10)->setTime(21, 15);

        $parent = $this->makeBaseEvent(
            title: 'Program Ramadan: Tadabbur & Qiyam',
            description: 'Program payung Ramadan yang menghimpunkan kuliah malam, tadabbur hujung minggu, dan program khas.',
            scheduleKind: ScheduleKind::CustomChain,
            institution: $institution,
            speakerIds: $speakerIds,
            tz: $tz,
            startsAt: $nightOne->copy()->utc(),
            endsAt: $nightOne->copy()->addDays(14)->utc(),
        );

        $this->createSession($parent, 'Malam 1: Tadabbur Selepas Tarawih', $nightOne->copy(), $nightOne->copy()->addHours(1)->addMinutes(30));
        $this->createSession($parent, 'Malam 2: Qiyam & Muhasabah', $nightOne->copy()->addDays(3), $nightOne->copy()->addDays(3)->addHours(1)->addMinutes(15));
        $this->createSession($parent, 'Hujung Minggu: Tadabbur Keluarga', $nightOne->copy()->addDays(6)->setTime(10, 0), $nightOne->copy()->addDays(6)->setTime(12, 0));
    }

    /**
     * @param  list<string>  $speakerIds
     */
    private function seedWeekendIntensive(?Institution $institution, array $speakerIds): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $friday = now($tz)->next(Carbon::FRIDAY)->addWeeks(3)->setTime(20, 30);

        $parent = $this->makeBaseEvent(
            title: 'Weekend Intensive: Bulughul Maram',
            description: 'Program intensif hujung minggu dengan sesi utama dalam satu occurrence supaya jadual awam kekal jelas.',
            scheduleKind: ScheduleKind::MultiDay,
            institution: $institution,
            speakerIds: $speakerIds,
            tz: $tz,
            startsAt: $friday->copy()->utc(),
            endsAt: $friday->copy()->addDays(2)->utc(),
        );

        $this->createSession($parent, 'Sesi 1: Pengantar Kitab', $friday->copy(), $friday->copy()->addHours(2));
        $this->createSession($parent, 'Sesi 2: Fiqh Taharah', $friday->copy()->addDay()->setTime(9, 0), $friday->copy()->addDay()->setTime(12, 0));
        $this->createSession($parent, 'Sesi 3: Fiqh Solat', $friday->copy()->addDay()->setTime(14, 0), $friday->copy()->addDay()->setTime(17, 0));
        $this->createSession($parent, 'Penutup & Soal Jawab', $friday->copy()->addDays(2)->setTime(9, 30), $friday->copy()->addDays(2)->setTime(11, 30));
    }

    /**
     * @param  list<string>  $speakerIds
     */
    private function seedMultiDayStandalone(?Institution $institution, array $speakerIds): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $friday = now($tz)->next(Carbon::FRIDAY)->addWeek();

        $this->makeBaseEvent(
            title: 'Daurah Ilmiah: Bulughul Maram — 3 Hari',
            description: 'Daurah intensif tiga hari yang berlangsung sebagai satu event eksplisit merentasi beberapa hari.',
            scheduleKind: ScheduleKind::MultiDay,
            institution: $institution,
            speakerIds: $speakerIds,
            tz: $tz,
            startsAt: $friday->copy()->setTime(9, 0)->utc(),
            endsAt: $friday->copy()->addDays(2)->setTime(16, 0)->utc(),
        );
    }

    /**
     * @param  list<string>  $speakerIds
     */
    private function seedStandaloneSpecialLecture(?Institution $institution, array $speakerIds): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $night = now($tz)->next(Carbon::SUNDAY)->addDays(10)->setTime(20, 45);

        $this->makeBaseEvent(
            title: 'Kuliah Khas: Adab Menuntut Ilmu',
            description: 'Kuliah khas satu malam yang kekal sebagai event eksplisit tanpa lapisan jadual tambahan.',
            scheduleKind: ScheduleKind::Single,
            institution: $institution,
            speakerIds: $speakerIds,
            tz: $tz,
            startsAt: $night->copy()->utc(),
            endsAt: $night->copy()->addHours(2)->utc(),
        );
    }

    private function createSession(Event $event, string $title, CarbonInterface $startsAt, CarbonInterface $endsAt): EventSession
    {
        $occurrence = $event->primaryOccurrence;

        if ($occurrence === null) {
            throw new \RuntimeException('A seeded event must have a primary occurrence before sessions are added.');
        }

        return EventSession::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'event_occurrence_id' => $occurrence->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'description' => 'Session in the event occurrence.',
            'starts_at' => $startsAt->copy()->utc(),
            'ends_at' => $endsAt->copy()->utc(),
            'timezone' => $event->timezone ?: 'Asia/Kuala_Lumpur',
            'status' => 'published',
            'visibility' => EventVisibility::Public->value,
            'delivery_mode' => EventFormat::Physical->value,
            'sort_order' => (int) $occurrence->sessions()->max('sort_order') + 1,
        ]);
    }

    /**
     * @param  list<string>  $speakerIds
     */
    private function makeBaseEvent(
        string $title,
        string $description,
        ScheduleKind $scheduleKind,
        ?Institution $institution,
        array $speakerIds,
        string $tz,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
    ): Event {
        $startsAt ??= Carbon::now($tz)->utc();
        $endsAt ??= $startsAt->copy()->addHours(2);

        $event = Event::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => null,
            'submitter_id' => null,
            'institution_id' => $institution?->id,
            'default_venue_id' => null,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'description' => $description,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $tz,
            'delivery_mode' => EventFormat::Physical,
            'visibility' => EventVisibility::Public,
            'gender' => EventGenderRestriction::All,
            'age_group' => [EventAgeGroup::AllAges->value],
            'children_allowed' => true,
            'is_muslim_only' => false,
            'status' => 'approved',
            'published_at' => now()->subDay(),
            'schedule_kind' => $scheduleKind->value,
            'schedule_state' => ScheduleState::Active->value,
            'timing_mode' => TimingMode::Absolute->value,
            'prayer_reference' => null,
            'prayer_offset' => null,
            'prayer_display_text' => null,
        ]);

        if ($categoryId = array_key_first(app(EventCategoryCatalog::class)->options())) {
            app(SyncEventClassificationsAction::class)->handle($event, ['event_category_ids' => [$categoryId]]);
        }

        EventOccurrence::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'title' => $event->title,
            'slug' => $event->slug,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
            'timezone' => $event->timezone,
            'status' => 'published',
            'visibility' => EventVisibility::Public->value,
            'delivery_mode' => EventFormat::Physical->value,
            'published_at' => $event->published_at,
        ]);

        $event->unsetRelation('primaryOccurrence');

        OwnerContext::withOwner(null, function () use ($event, $institution, $speakerIds): void {
            if ($institution instanceof Institution) {
                $event->setPrimaryOrganizer($institution);
            }

            if ($speakerIds !== []) {
                $selected = array_slice($speakerIds, 0, random_int(1, min(3, count($speakerIds))));
                $otherKeyPeople = [];

                if (count($selected) > 1) {
                    $otherKeyPeople[] = [
                        'role' => EventKeyPersonRole::Moderator->value,
                        'speaker_id' => $selected[0],
                        'is_public' => true,
                    ];
                }

                app(EventKeyPersonSyncService::class)->sync($event, $selected, $otherKeyPeople);
            }
        });

        return $event;
    }
}
