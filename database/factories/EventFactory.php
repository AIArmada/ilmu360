<?php

namespace Database\Factories;

use AIArmada\Events\Database\Factories\EventFactory as PackageEventFactory;
use AIArmada\Events\Enums\RegistrationMode as PackageRegistrationMode;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventLink;
use App\Actions\Events\SyncEventClassificationsAction;
use App\Actions\Events\SyncEventScheduleAction;
use App\Contracts\EventCategoryCatalog;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventVisibility;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use WeakMap;

class EventFactory extends PackageEventFactory
{
    protected $model = Event::class;

    /** @var WeakMap<Event, array<string, mixed>>|null */
    private static ?WeakMap $scheduleStates = null;

    #[\Override]
    public function newModel(array $attributes = []): Model
    {
        $schedule = [];

        foreach (['starts_at', 'ends_at', 'timing_mode', 'prayer_reference', 'prayer_offset', 'prayer_display_text'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $schedule[$key] = $attributes[$key];
                unset($attributes[$key]);
            }
        }

        $event = parent::newModel($attributes);

        if ($event instanceof Event) {
            self::$scheduleStates ??= new WeakMap;
            self::$scheduleStates[$event] = $schedule;
        }

        return $event;
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function definition(): array
    {
        $eventTypes = [
            'Kuliah / Ceramah',
            'Tazkirah',
            'Kelas / Daurah',
            'Forum Perdana',
            'Seminar / Konvensyen',
            'Sesi Tadabbur',
            'Majlis Ilmu',
        ];
        $topics = [
            'Tafsir Al-Fatihah',
            'Tafsir Al-Kahfi',
            'Fiqh Solat',
            'Fiqh Puasa',
            'Fiqh Zakat',
            'Sirah Nabawiyyah',
            'Sirah Sahabah',
            'Adab Menuntut Ilmu',
            'Aqidah Ahlus Sunnah',
            'Hadis Arba\'in',
            'Riyadus Salihin',
            'Tazkiyah An-Nafs',
            'Muamalat Islam',
            'Keluarga Sakinah',
            'Remaja & Identiti',
            'Persiapan Ramadhan',
            'Isu Semasa Ummah',
            'Tadabbur Surah Yasin',
            'Tadabbur Surah Al-Mulk',
            'Tafsir Juz Amma',
        ];
        $books = [
            'Riyadus Salihin',
            'Bulughul Maram',
            'Al-Arba\'in An-Nawawiyyah',
            'Tafsir Ibnu Kathir',
            'Fiqh Manhaji',
            'Umdatul Ahkam',
        ];

        $eventTimezone = 'Asia/Kuala_Lumpur';
        $startsAtLocal = Carbon::instance(fake()->dateTimeBetween('now', '+2 months', $eventTimezone))
            ->setTimezone($eventTimezone);
        $endsAtLocal = $startsAtLocal->copy()->addHours(fake()->numberBetween(1, 3));
        $startsAt = $startsAtLocal->copy()->utc();
        $endsAt = $endsAtLocal->copy()->utc();
        $type = fake()->randomElement($eventTypes);
        $topic = fake()->randomElement($topics);
        $book = fake()->randomElement($books);
        $title = fake()->randomElement([
            $type.': '.$topic,
            $type.' - '.$topic,
            $topic.' ('.$type.')',
            'Kelas / Daurah: '.$book,
            'Halaqah '.$book,
            'Tadabbur: '.$topic,
            $type.' bersama Asatizah',
        ]);
        $status = fake()->randomElement(['approved', 'approved', 'pending', 'draft']);
        $publishedAt = $status === 'approved'
            ? $startsAtLocal->copy()->subDays(fake()->numberBetween(1, 14))->utc()
            : null;
        fake()->optional()->url();
        fake()->optional()->url();

        // Determine event format: 60% physical, 25% online, 15% hybrid
        $defaultEventFormat = fake()->randomElement([
            EventFormat::Physical,
            EventFormat::Physical,
            EventFormat::Physical,
            EventFormat::Physical,
            EventFormat::Physical,
            EventFormat::Physical,
            EventFormat::Online,
            EventFormat::Online,
            EventFormat::Online,
            EventFormat::Hybrid,
            EventFormat::Hybrid,
            EventFormat::Hybrid,
        ]);

        return [
            'institution_id' => function (array $attributes) {
                $eventFormat = $this->eventFormatFromAttributes($attributes);

                if ($eventFormat === EventFormat::Online) {
                    return null;
                }

                if (filled($attributes['default_venue_id'] ?? $attributes['venue_id'] ?? null)) {
                    return null;
                }

                return Institution::factory();
            },
            'default_venue_id' => null,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(7)),
            'description' => fake()->optional()->paragraphs(2, true),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $eventTimezone,

            'gender' => fake()->randomElement(EventGenderRestriction::cases()),
            'age_group' => [fake()->randomElement(EventAgeGroup::cases())],
            'children_allowed' => fake()->boolean(80), // 80% allow children
            'delivery_mode' => $defaultEventFormat instanceof EventFormat
                ? $defaultEventFormat->value
                : (string) $defaultEventFormat,
            'visibility' => fake()->randomElement([
                EventVisibility::Public,
                EventVisibility::Public,
                EventVisibility::Unlisted,
            ]),
            'status' => $status,
            'published_at' => $publishedAt,
            'is_muslim_only' => fake()->boolean(90), // 90% are muslim only
        ];
    }

    /**
     * Configure the model factory.
     */
    #[\Override]
    public function configure(): static
    {
        return $this->afterMaking(function ($event): void {
            if (! $event instanceof Event) {
                return;
            }

            $schedule = self::$scheduleStates instanceof \WeakMap && isset(self::$scheduleStates[$event])
                ? self::$scheduleStates[$event]
                : [];

            if (in_array((string) $event->status, Event::PUBLIC_STATUSES, true) && $event->published_at === null) {
                $startsAt = $schedule['starts_at'] ?? null;
                $event->published_at = $startsAt instanceof CarbonInterface
                    ? Carbon::instance($startsAt)->subDay()
                    : now();
            }
        })->afterCreating(function ($event): void {
            if (! $event instanceof Event) {
                return;
            }

            $schedule = self::$scheduleStates instanceof \WeakMap && isset(self::$scheduleStates[$event])
                ? self::$scheduleStates[$event]
                : [];
            $timingMode = $schedule['timing_mode'] ?? null;
            $timingMode = $timingMode instanceof TimingMode
                ? $timingMode
                : TimingMode::tryFrom((string) $timingMode);
            $prayerOffset = $schedule['prayer_offset'] ?? null;
            $prayerOffset = $prayerOffset instanceof PrayerOffset
                ? $prayerOffset->minutes()
                : PrayerOffset::tryFrom((string) $prayerOffset)?->minutes();
            $startsAt = ($schedule['starts_at'] ?? null) instanceof CarbonInterface
                ? $schedule['starts_at']
                : null;
            $endsAt = ($schedule['ends_at'] ?? null) instanceof CarbonInterface
                ? $schedule['ends_at']
                : null;

            app(SyncEventScheduleAction::class)->execute(
                event: $event,
                scheduleKind: ScheduleKind::Single,
                startsAt: $startsAt,
                endsAt: $endsAt,
                timezone: $event->timezone,
                timingMode: $timingMode,
                prayerReference: ($schedule['prayer_reference'] ?? null) instanceof PrayerReference
                    ? $schedule['prayer_reference']->value
                    : ($schedule['prayer_reference'] ?? null),
                prayerOffset: $prayerOffset,
                prayerDisplayText: $schedule['prayer_display_text'] ?? null,
            );

            $categoryIds = $event->event_category_ids;
            if ($categoryIds === []) {
                $categoryId = array_key_first(app(EventCategoryCatalog::class)->options());
                $categoryIds = $categoryId !== null ? [$categoryId] : [];
            }

            if ($categoryIds !== []) {
                app(SyncEventClassificationsAction::class)->handle($event, ['event_category_ids' => $categoryIds]);
            }

            // Create EventLink rows for streaming/recording URLs
            $this->ensureFactoryUrlLinks($event);

            // 30% of events have registration settings
            if (
                fake()->boolean(30)
                && ! $event->accessPolicy()->exists()
            ) {
                $event->forceFill([
                    'registration_mode' => PackageRegistrationMode::Required->value,
                ])->save();

                $event->accessPolicy()->create([
                    'registration_required' => true,
                    'capacity' => fake()->numberBetween(30, 300),
                    'walk_in_allowed' => false,
                    'opens_at' => $startsAt?->copy()->subDays(7),
                    'closes_at' => $startsAt?->copy()->subDays(1),
                ]);
            }

            if (self::$scheduleStates instanceof \WeakMap && isset(self::$scheduleStates[$event])) {
                unset(self::$scheduleStates[$event]);
            }
        });
    }

    /**
     * Indicate that the event is prayer-relative.
     */
    public function prayerRelative(
        ?PrayerReference $prayer = null,
        ?PrayerOffset $offset = null
    ): static {
        $prayer ??= fake()->randomElement(PrayerReference::cases());
        $offset ??= fake()->randomElement([
            PrayerOffset::Immediately,
            PrayerOffset::After15,
            PrayerOffset::After30,
        ]);

        return $this->afterCreating(function (Event $event) use ($prayer, $offset): void {
            app(SyncEventScheduleAction::class)->execute(
                event: $event,
                scheduleKind: ScheduleKind::Single,
                startsAt: $event->starts_at,
                endsAt: $event->ends_at,
                timezone: $event->timezone,
                timingMode: TimingMode::PrayerRelative,
                prayerReference: $prayer->value,
                prayerOffset: $offset->minutes(),
                prayerDisplayText: $offset->displayText($prayer),
            );
        });
    }

    /**
     * Indicate a Kuliah Maghrib event.
     */
    public function kuliahMaghrib(): static
    {
        return $this->prayerRelative(
            PrayerReference::Maghrib,
            PrayerOffset::Immediately
        )->state(fn (array $attributes) => [
            'title' => 'Kuliah Maghrib: '.fake()->randomElement([
                'Tafsir Al-Kahfi',
                'Sirah Nabawiyyah',
                'Fiqh Solat',
            ]),
        ]);
    }

    /**
     * Indicate a Kuliah Isya event.
     */
    public function kuliahIsya(): static
    {
        return $this->prayerRelative(
            PrayerReference::Isha,
            PrayerOffset::After15
        )->state(fn (array $attributes) => [
            'title' => 'Kuliah Isya: '.fake()->randomElement([
                'Hadis Arba\'in',
                'Riyadus Salihin',
                'Aqidah Ahlus Sunnah',
            ]),
        ]);
    }

    /**
     * Indicate a Tazkirah Subuh event.
     */
    public function tazkirahSubuh(): static
    {
        return $this->prayerRelative(
            PrayerReference::Fajr,
            PrayerOffset::Immediately
        )->state(fn (array $attributes) => [
            'title' => 'Tazkirah Subuh: '.fake()->randomElement([
                'Tazkiyah An-Nafs',
                'Adab Menuntut Ilmu',
                'Zikir Pagi',
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function eventFormatFromAttributes(array $attributes): EventFormat
    {
        $eventFormat = $attributes['delivery_mode'] ?? $attributes['event_format'] ?? null;

        if ($eventFormat instanceof EventFormat) {
            return $eventFormat;
        }

        if (is_string($eventFormat) && $eventFormat !== '') {
            return EventFormat::from($eventFormat);
        }

        return EventFormat::Physical;
    }

    private function ensureFactoryUrlLinks(Event $event): void
    {
        $eventFormat = $this->eventFormatFromAttributes($event->getAttributes());

        if ($eventFormat !== EventFormat::Physical) {
            EventLink::query()->create([
                'event_id' => (string) $event->getKey(),
                'link_type' => 'streaming',
                'url' => 'https://meet.google.com/'.Str::random(10),
                'visibility' => 'public',
            ]);
        }

        if (fake()->boolean(50) && $eventFormat !== EventFormat::Online) {
            EventLink::query()->create([
                'event_id' => (string) $event->getKey(),
                'link_type' => 'recording',
                'url' => 'https://www.youtube.com/watch?v='.Str::random(11),
                'visibility' => 'public',
            ]);
        }
    }
}
