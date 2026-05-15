<?php

namespace Database\Seeders;

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventStructure;
use App\Enums\EventType;
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
            $this->seedWeeklySeriesParent($institution, $speakerIds);
            $this->seedRamadanProgramParent($institution, $speakerIds);
            $this->seedWeekendIntensiveParent($institution, $speakerIds);
            $this->seedMultiDayStandalone($institution, $speakerIds);
            $this->seedStandaloneSpecialLecture($institution, $speakerIds);
        } finally {
            Event::setEventDispatcher(app('events'));
        }

        $this->command->info('  [AdvancedEventSeeder] Seeded 5 explicit advanced event examples (3 parent programs, 2 standalone events).');
    }

    /**
     * @param  list<string>  $speakerIds
     */
    private function seedWeeklySeriesParent(?Institution $institution, array $speakerIds): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $firstFriday = now($tz)->next(Carbon::FRIDAY)->setTime(20, 30);

        $parent = $this->makeBaseEvent(
            title: 'Kelas / Daurah: Al-Arba\'in An-Nawawiyyah',
            description: 'Program payung untuk siri pengajian mingguan. Setiap pertemuan diterbitkan sebagai child event tersendiri.',
            scheduleKind: ScheduleKind::CustomChain,
            institution: $institution,
            speakerIds: $speakerIds,
            tz: $tz,
            eventStructure: EventStructure::ParentProgram,
            startsAt: $firstFriday->copy()->utc(),
            endsAt: $firstFriday->copy()->addWeeks(4)->utc(),
        );

        $this->createChildEvent($parent, 'Minggu 1: Pengenalan Hadis', $firstFriday->copy(), $firstFriday->copy()->addHours(2));
        $this->createChildEvent($parent, 'Minggu 2: Hadis Niat', $firstFriday->copy()->addWeek(), $firstFriday->copy()->addWeek()->addHours(2));
        $this->createChildEvent($parent, 'Minggu 3: Hadis Ihsan', $firstFriday->copy()->addWeeks(2), $firstFriday->copy()->addWeeks(2)->addHours(2));
    }

    /**
     * @param  list<string>  $speakerIds
     */
    private function seedRamadanProgramParent(?Institution $institution, array $speakerIds): void
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
            eventStructure: EventStructure::ParentProgram,
            startsAt: $nightOne->copy()->utc(),
            endsAt: $nightOne->copy()->addDays(14)->utc(),
        );

        $this->createChildEvent($parent, 'Malam 1: Tadabbur Selepas Tarawih', $nightOne->copy(), $nightOne->copy()->addHours(1)->addMinutes(30));
        $this->createChildEvent($parent, 'Malam 2: Qiyam & Muhasabah', $nightOne->copy()->addDays(3), $nightOne->copy()->addDays(3)->addHours(1)->addMinutes(15));
        $this->createChildEvent($parent, 'Hujung Minggu: Tadabbur Keluarga', $nightOne->copy()->addDays(6)->setTime(10, 0), $nightOne->copy()->addDays(6)->setTime(12, 0));
    }

    /**
     * @param  list<string>  $speakerIds
     */
    private function seedWeekendIntensiveParent(?Institution $institution, array $speakerIds): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $friday = now($tz)->next(Carbon::FRIDAY)->addWeeks(3)->setTime(20, 30);

        $parent = $this->makeBaseEvent(
            title: 'Weekend Intensive: Bulughul Maram',
            description: 'Program intensif hujung minggu. Setiap sesi utama dihantar sebagai child event supaya jadual awam kekal jelas.',
            scheduleKind: ScheduleKind::MultiDay,
            institution: $institution,
            speakerIds: $speakerIds,
            tz: $tz,
            eventStructure: EventStructure::ParentProgram,
            startsAt: $friday->copy()->utc(),
            endsAt: $friday->copy()->addDays(2)->utc(),
        );

        $this->createChildEvent($parent, 'Sesi 1: Pengantar Kitab', $friday->copy(), $friday->copy()->addHours(2));
        $this->createChildEvent($parent, 'Sesi 2: Fiqh Taharah', $friday->copy()->addDay()->setTime(9, 0), $friday->copy()->addDay()->setTime(12, 0));
        $this->createChildEvent($parent, 'Sesi 3: Fiqh Solat', $friday->copy()->addDay()->setTime(14, 0), $friday->copy()->addDay()->setTime(17, 0));
        $this->createChildEvent($parent, 'Penutup & Soal Jawab', $friday->copy()->addDays(2)->setTime(9, 30), $friday->copy()->addDays(2)->setTime(11, 30));
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

    private function createChildEvent(Event $parentEvent, string $title, CarbonInterface $startsAt, CarbonInterface $endsAt): Event
    {
        return $this->makeBaseEvent(
            title: $title,
            description: 'Child event attached to the parent program.',
            scheduleKind: ScheduleKind::Single,
            institution: $parentEvent->institution,
            speakerIds: $parentEvent->speakers()->pluck('speakers.id')->map(static fn (mixed $id): string => (string) $id)->all(),
            tz: $parentEvent->timezone ?: 'Asia/Kuala_Lumpur',
            eventStructure: EventStructure::ChildEvent,
            parentEvent: $parentEvent,
            startsAt: $startsAt->copy()->utc(),
            endsAt: $endsAt->copy()->utc(),
        );
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
        EventStructure $eventStructure = EventStructure::Standalone,
        ?Event $parentEvent = null,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
    ): Event {
        $startsAt ??= Carbon::now($tz)->utc();
        $endsAt ??= $startsAt->copy()->addHours(2);

        $event = Event::query()->create([
            'user_id' => null,
            'submitter_id' => null,
            'parent_event_id' => $parentEvent?->id,
            'event_structure' => $eventStructure->value,
            'institution_id' => $institution?->id,
            'venue_id' => null,
            'organizer_type' => $institution instanceof Institution ? Institution::class : null,
            'organizer_id' => $institution?->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'description' => $description,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $tz,
            'event_type' => [EventType::KuliahCeramah],
            'event_format' => EventFormat::Physical,
            'visibility' => EventVisibility::Public,
            'gender' => EventGenderRestriction::All,
            'age_group' => [EventAgeGroup::AllAges->value],
            'children_allowed' => true,
            'is_muslim_only' => false,
            'status' => 'approved',
            'published_at' => now()->subDay(),
            'schedule_kind' => $scheduleKind->value,
            'schedule_state' => ScheduleState::Active->value,
            'is_active' => true,
            'timing_mode' => TimingMode::Absolute->value,
            'prayer_reference' => null,
            'prayer_offset' => null,
            'prayer_display_text' => null,
        ]);

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

        return $event;
    }
}
