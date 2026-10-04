<?php

use App\Data\EventDiscoveryCriteriaFactory;
use App\Enums\EventPrayerTime;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use App\Services\PostgresEventDiscovery;
use App\Services\PublicScheduleDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function tarawihFilterEvent(string $title, ?PrayerReference $reference, PrayerOffset $offset, string $label): Event
{
    // Pin the full schedule through the factory: unpinned schedule keys
    // fall back to the factory's random 70%-prayer-relative mix, which
    // would attach a second random expression and flake the filters.
    // SyncEventScheduleAction persists the expression from these keys.
    return Event::factory()->create([
        'institution_id' => Institution::factory(),
        'title' => $title,
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
        'delivery_mode' => 'physical',
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_reference' => $reference,
        'prayer_offset' => $offset,
        'prayer_display_text' => $label,
    ]);
}

function tarawihFilterSeed(): void
{
    // Current writer shapes: Tarawih is null-anchor + label, Isyak is
    // isha-anchored.
    tarawihFilterEvent('Tarawih Night', null, PrayerOffset::After60, 'Selepas Tarawih');
    tarawihFilterEvent('Isyak Night', PrayerReference::Isha, PrayerOffset::Immediately, 'Selepas Isyak');
}

function tarawihPostgresTitles(string $prayerTime): array
{
    $criteria = app(EventDiscoveryCriteriaFactory::class)
        ->fromSearch(null, ['prayer_time' => $prayerTime], 50, 'time');

    return collect(app(PostgresEventDiscovery::class)->search($criteria)->items())
        ->pluck('title')
        ->all();
}

function tarawihScheduleTitles(string $prayerTime): array
{
    return collect(app(PublicScheduleDiscoveryService::class)->search(null, ['prayer_time' => $prayerTime])->items())
        ->map(fn ($leaf): string => $leaf->event->title)
        ->unique()
        ->values()
        ->all();
}

it('excludes isyak events from the tarawih filter on both discovery paths', function () {
    tarawihFilterSeed();

    expect(tarawihPostgresTitles(EventPrayerTime::SelepasTarawih->value))
        ->toContain('Tarawih Night')
        ->not->toContain('Isyak Night');

    expect(tarawihScheduleTitles(EventPrayerTime::SelepasTarawih->value))
        ->toContain('Tarawih Night')
        ->not->toContain('Isyak Night');
});

it('excludes tarawih events from the isyak filter on both discovery paths', function () {
    tarawihFilterSeed();

    expect(tarawihPostgresTitles(EventPrayerTime::SelepasIsyak->value))
        ->toContain('Isyak Night')
        ->not->toContain('Tarawih Night');

    expect(tarawihScheduleTitles(EventPrayerTime::SelepasIsyak->value))
        ->toContain('Isyak Night')
        ->not->toContain('Tarawih Night');
});
