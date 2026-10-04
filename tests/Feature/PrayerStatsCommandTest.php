<?php

use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventTimeExpression;
use App\Actions\Events\SyncEventScheduleAction;
use App\Enums\TimingMode;
use App\Jobs\RefreshPrayerTimes;
use App\Models\Event;
use App\Services\Prayer\PrayerProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
});

it('reports provenance distribution, mappings, breakers, and queue depth', function () {
    $event = Event::factory()->create();

    app(SyncEventScheduleAction::class)->execute(
        event: $event,
        scheduleKind: ScheduleKind::Single,
        startsAt: Carbon::parse('2026-05-01 18:45:00', 'UTC'),
        endsAt: Carbon::parse('2026-05-01 20:00:00', 'UTC'),
        timezone: 'Asia/Kuala_Lumpur',
        timingMode: TimingMode::PrayerRelative,
        prayerReference: 'maghrib',
        prayerOffset: 5,
        prayerSource: 'jakim:v2/WLY01',
        prayerFetchedAt: '2026-05-01T00:00:00+00:00',
    );

    EventTimeExpression::factory()->create([
        'event_id' => Event::factory()->create()->getKey(),
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'metadata' => null,
    ]);

    app(PrayerProviderRegistry::class)->orderedFor('XX');

    $exit = Artisan::call('app:prayer:stats', ['--json' => true]);
    $stats = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($stats['sources']['jakim:v2/WLY01'])->toBe(1)
        ->and($stats['sources']['hardcoded:untracked'])->toBe(1)
        ->and($stats['missing_mappings']['XX'])->toBe(1)
        ->and($stats['breakers'])->toHaveKeys(['jakim', 'ummah', 'aladhan'])
        ->and($stats['breakers']['jakim'])->toBe(['failures' => 0, 'open' => false])
        ->and($stats['queue_depth'])->toBe(0);
});

it('fails the queue-depth alert past the threshold', function () {
    Log::spy();

    // Sync driver always reports depth 0, so any negative threshold trips.
    $exit = Artisan::call('app:prayer:stats', ['--alert-queue-depth' => '-1', '--json' => true]);

    expect($exit)->toBe(1);

    Log::shouldHaveReceived('error')->once();
});

it('aggregates non-default queue backlogs into the reported total and alert', function () {
    Queue::fake();
    Log::spy();

    RefreshPrayerTimes::dispatch('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-10'])->onQueue('media');
    RefreshPrayerTimes::dispatch('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-10'])->onQueue('media');
    RefreshPrayerTimes::dispatch('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-10']);

    $exit = Artisan::call('app:prayer:stats', ['--alert-queue-depth' => '2', '--json' => true]);
    $stats = json_decode(Artisan::output(), true);

    // The media backlog counts: the total is no longer the default queue
    // alone, and the alert trips on the aggregated depth.
    expect($stats['queue_depths']['media'])->toBe(2)
        ->and($stats['queue_depths']['default'])->toBe(1)
        ->and($stats['queue_depth'])->toBe(3)
        ->and($exit)->toBe(1);

    Log::shouldHaveReceived('error')->once();
});

it('reports only the preceding seven days of missing mappings', function () {
    app(PrayerProviderRegistry::class)->orderedFor('XX');

    // A surviving-but-stale counter from eight days ago must not leak in.
    $prefix = (string) config('prayer.cache.prefix', 'prayer:v3');
    $old = Carbon::now('UTC')->subDays(8)->format('Y-m-d');
    Cache::store(config('prayer.cache.store'))->put("{$prefix}:metric:missing-mapping:XX:{$old}", 5, 8 * 86400);

    $exit = Artisan::call('app:prayer:stats', ['--json' => true]);
    $stats = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($stats['missing_mappings']['XX'])->toBe(1);
});
