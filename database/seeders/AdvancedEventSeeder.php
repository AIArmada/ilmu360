<?php

namespace Database\Seeders;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventSession;
use App\Actions\Events\SyncEventClassificationsAction;
use App\Actions\Events\SyncEventScheduleAction;
use App\Contracts\EventCategoryCatalog;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventVisibility;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
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

        $personIds = Person::query()
            ->where('status', 'verified')
            ->inRandomOrder()
            ->limit(6)
            ->pluck('id')
            ->all();

        $this->seedWeeklySeries($institution, $personIds);
        $this->seedRamadanProgram($institution, $personIds);
        $this->seedWeekendIntensive($institution, $personIds);
        $this->seedMultiDayStandalone($institution, $personIds);
        $this->seedStandaloneSpecialLecture($institution, $personIds);

        $this->command->info('  [AdvancedEventSeeder] Seeded 5 event examples with package occurrences and sessions.');
    }

    /**
     * @param  list<string>  $personIds
     */
    private function seedWeeklySeries(?Institution $institution, array $personIds): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $firstFriday = now($tz)->next(Carbon::FRIDAY)->setTime(20, 30);

        $parent = $this->makeBaseEvent(
            title: 'Kelas / Daurah: Al-Arba\'in An-Nawawiyyah',
            description: 'Program payung untuk siri pengajian mingguan. Setiap pertemuan direkodkan sebagai sesi dalam occurrence program.',
            scheduleKind: ScheduleKind::CustomChain,
            institution: $institution,
            personIds: $personIds,
            tz: $tz,
            startsAt: $firstFriday->copy()->utc(),
            endsAt: $firstFriday->copy()->addWeeks(4)->utc(),
        );

        $this->createSession($parent, 'Minggu 1: Pengenalan Hadis', $firstFriday->copy(), $firstFriday->copy()->addHours(2));
        $this->createSession($parent, 'Minggu 2: Hadis Niat', $firstFriday->copy()->addWeek(), $firstFriday->copy()->addWeek()->addHours(2));
        $this->createSession($parent, 'Minggu 3: Hadis Ihsan', $firstFriday->copy()->addWeeks(2), $firstFriday->copy()->addWeeks(2)->addHours(2));
    }

    /**
     * @param  list<string>  $personIds
     */
    private function seedRamadanProgram(?Institution $institution, array $personIds): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $nightOne = now($tz)->addDays(10)->setTime(21, 15);

        $parent = $this->makeBaseEvent(
            title: 'Program Ramadan: Tadabbur & Qiyam',
            description: 'Program payung Ramadan yang menghimpunkan kuliah malam, tadabbur hujung minggu, dan program khas.',
            scheduleKind: ScheduleKind::CustomChain,
            institution: $institution,
            personIds: $personIds,
            tz: $tz,
            startsAt: $nightOne->copy()->utc(),
            endsAt: $nightOne->copy()->addDays(14)->utc(),
        );

        $this->createSession($parent, 'Malam 1: Tadabbur Selepas Tarawih', $nightOne->copy(), $nightOne->copy()->addHours(1)->addMinutes(30));
        $this->createSession($parent, 'Malam 2: Qiyam & Muhasabah', $nightOne->copy()->addDays(3), $nightOne->copy()->addDays(3)->addHours(1)->addMinutes(15));
        $this->createSession($parent, 'Hujung Minggu: Tadabbur Keluarga', $nightOne->copy()->addDays(6)->setTime(10, 0), $nightOne->copy()->addDays(6)->setTime(12, 0));
    }

    /**
     * @param  list<string>  $personIds
     */
    private function seedWeekendIntensive(?Institution $institution, array $personIds): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $friday = now($tz)->next(Carbon::FRIDAY)->addWeeks(3)->setTime(20, 30);

        $parent = $this->makeBaseEvent(
            title: 'Weekend Intensive: Bulughul Maram',
            description: 'Program intensif hujung minggu dengan sesi utama dalam satu occurrence supaya jadual awam kekal jelas.',
            scheduleKind: ScheduleKind::MultiDay,
            institution: $institution,
            personIds: $personIds,
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
     * @param  list<string>  $personIds
     */
    private function seedMultiDayStandalone(?Institution $institution, array $personIds): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $friday = now($tz)->next(Carbon::FRIDAY)->addWeek();

        $this->makeBaseEvent(
            title: 'Daurah Ilmiah: Bulughul Maram — 3 Hari',
            description: 'Daurah intensif tiga hari yang berlangsung sebagai satu event eksplisit merentasi beberapa hari.',
            scheduleKind: ScheduleKind::MultiDay,
            institution: $institution,
            personIds: $personIds,
            tz: $tz,
            startsAt: $friday->copy()->setTime(9, 0)->utc(),
            endsAt: $friday->copy()->addDays(2)->setTime(16, 0)->utc(),
        );
    }

    /**
     * @param  list<string>  $personIds
     */
    private function seedStandaloneSpecialLecture(?Institution $institution, array $personIds): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $night = now($tz)->next(Carbon::SUNDAY)->addDays(10)->setTime(20, 45);

        $this->makeBaseEvent(
            title: 'Kuliah Khas: Adab Menuntut Ilmu',
            description: 'Kuliah khas satu malam yang kekal sebagai event eksplisit tanpa lapisan jadual tambahan.',
            scheduleKind: ScheduleKind::Single,
            institution: $institution,
            personIds: $personIds,
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
     * @param  list<string>  $personIds
     */
    private function makeBaseEvent(
        string $title,
        string $description,
        ScheduleKind $scheduleKind,
        ?Institution $institution,
        array $personIds,
        string $tz,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
    ): Event {
        $startsAt ??= Carbon::now($tz)->utc();
        $endsAt ??= $startsAt->copy()->addHours(2);

        $event = Event::query()->create([
            'id' => (string) Str::uuid(),
            'institution_id' => $institution?->id,
            'default_venue_id' => null,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'description' => $description,
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
        ]);

        if ($categoryId = array_key_first(app(EventCategoryCatalog::class)->options())) {
            app(SyncEventClassificationsAction::class)->handle($event, ['event_category_ids' => [$categoryId]]);
        }

        app(SyncEventScheduleAction::class)->execute(
            event: $event,
            scheduleKind: $scheduleKind,
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $tz,
            timingMode: TimingMode::Absolute,
        );

        $event->primaryOccurrence?->forceFill([
            'status' => 'published',
            'published_at' => $event->published_at,
        ])->save();

        $event->unsetRelation('primaryOccurrence');

        OwnerContext::withOwner(null, function () use ($event, $institution, $personIds): void {
            if ($institution instanceof Institution) {
                $event->setPrimaryOrganizer($institution);
            }

            if ($personIds !== []) {
                $selected = array_slice($personIds, 0, random_int(1, min(3, count($personIds))));
                $otherKeyPeople = [];

                if (count($selected) > 1) {
                    $otherKeyPeople[] = [
                        'role_code' => EventKeyPersonRole::Moderator->value,
                        'involveable_type' => 'person',
                        'involveable_id' => $selected[0],
                        'display_name' => null,
                        'visibility' => 'public',
                    ];
                }

                app(EventKeyPersonSyncService::class)->sync($event, $selected, $otherKeyPeople);
            }
        });

        return $event;
    }
}
