<?php

namespace Database\Factories;

use AIArmada\Events\Database\Factories\EventFactory as PackageEventFactory;
use AIArmada\Events\Enums\RegistrationMode as PackageRegistrationMode;
use AIArmada\Events\Models\EventLink;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EventFactory extends PackageEventFactory
{
    protected $model = Event::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
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
        $livestreamUrl = fake()->optional()->url();
        $recordingUrl = fake()->optional()->url();

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
            'timing_mode' => TimingMode::Absolute->value,
            'prayer_reference' => null,
            'prayer_offset' => null,
            'prayer_display_text' => null,
            'event_type' => [fake()->randomElement(EventType::cases())],
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
            'views_count' => fake()->numberBetween(0, 2000),
            'saves_count' => fake()->numberBetween(0, 500),
            'registrations_count' => fake()->numberBetween(0, 200),
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
        return $this->afterMaking(function (Event $event): void {
            if (in_array((string) $event->status, Event::PUBLIC_STATUSES, true) && $event->published_at === null) {
                $event->published_at = $event->starts_at?->copy()->subDay() ?? now();
            }
        })->afterCreating(function (Event $event) {
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
                    'opens_at' => $event->starts_at->copy()->subDays(7),
                    'closes_at' => $event->starts_at->copy()->subDays(1),
                ]);
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

        return $this->state(fn (array $attributes) => [
            'timing_mode' => TimingMode::PrayerRelative->value,
            'prayer_reference' => $prayer->value,
            'prayer_offset' => $offset->value,
            'prayer_display_text' => $offset->displayText($prayer),
        ]);
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
