<?php

use App\Enums\EventAgeGroup;
use App\Livewire\Pages\Events\Index;
use App\Models\Event;
use App\Models\Tag;
use App\Services\EventSearchService;
use App\Services\PrayerTimeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('hydrates the events index state cache into the current safe payload format', function () {
    config()->set('cache.default', 'database');
    app('cache')->setDefaultDriver('database');
    Cache::flush();

    DB::table('states')->insert([
        'id' => 999,
        'country_id' => 132,
        'name' => 'Selangor',
        'country_code' => 'MY',
    ]);

    Livewire::test(Index::class)
        ->assertSee('Cari Majlis Ilmu');

    expect(Cache::get('states_all_v1'))
        ->toBeArray()
        ->and(Cache::get('states_all_v1'))
        ->toHaveCount(1);
});

it('hydrates the submit event safe option caches into the current payload format', function () {
    config()->set('cache.default', 'database');
    app('cache')->setDefaultDriver('database');
    Cache::flush();
    app()->setLocale('ms');

    Tag::factory()->domain()->create([
        'status' => 'verified',
    ]);

    Livewire::test('pages.submit-event.create')
        ->set('data.age_group', [EventAgeGroup::Children->value])
        ->assertSet('data.age_group', [EventAgeGroup::Children->value]);

    expect(Cache::get('submit_languages_safe_v1'))->toBeArray()
        ->and(Cache::get('submit_tags_domain_ms_safe_v1'))->toBeArray();
});

it('rehydrates the current default events search cache safely from the database cache store', function () {
    config()->set('cache.default', 'database');
    app('cache')->setDefaultDriver('database');
    Cache::flush();
    config()->set('scout.driver', 'database');

    $firstEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);
    $secondEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(2),
    ]);

    $service = app(EventSearchService::class);

    Cache::put(
        'default_events_search_v2',
        [
            'ids' => [(string) $firstEvent->id, (string) $secondEvent->id],
            'total' => 2,
        ],
        now()->addMinute(),
    );

    $paginator = $service->search(null, [], 12, 'time');

    expect($paginator->total())->toBe(2)
        ->and(collect($paginator->items())->map(fn (Event $event): string => (string) $event->id)->all())
        ->toBe([(string) $firstEvent->id, (string) $secondEvent->id])
        ->and(Cache::get('default_events_search_v2'))->toMatchArray([
            'ids' => [(string) $firstEvent->id, (string) $secondEvent->id],
            'total' => 2,
        ]);
});

it('rehydrates cached prayer times safely from the database cache store', function () {
    config()->set('cache.default', 'database');
    app('cache')->setDefaultDriver('database');
    Cache::flush();

    $date = Carbon::parse('2026-07-15');
    $timezone = 'America/New_York';
    $service = new PrayerTimeService;

    Http::fake([
        'api.aladhan.com/*' => Http::response([
            'data' => [
                'timings' => [
                    'Fajr' => '05:55',
                    'Dhuhr' => '13:15',
                    'Asr' => '16:40',
                    'Maghrib' => '19:20',
                    'Isha' => '20:35',
                ],
            ],
        ]),
    ]);

    $first = $service->getPrayerTimes($date, 40.7128, -74.0060, $timezone);

    expect($first)->not->toBeNull()
        ->and($first['Maghrib'])->toBeInstanceOf(Carbon::class)
        ->and($first['Maghrib']->format('H:i'))->toBe('19:20')
        ->and($first['Maghrib']->timezoneName)->toBe($timezone);

    Http::fake([
        'api.aladhan.com/*' => Http::response(null, 500),
    ]);

    $second = $service->getPrayerTimes($date, 40.7128, -74.0060, $timezone);

    expect($second)->not->toBeNull()
        ->and($second['Maghrib'])->toBeInstanceOf(Carbon::class)
        ->and($second['Maghrib']->format('H:i'))->toBe('19:20')
        ->and($second['Maghrib']->timezoneName)->toBe($timezone);
});
