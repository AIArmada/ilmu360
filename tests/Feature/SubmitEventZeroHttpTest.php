<?php

use AIArmada\Events\Models\EventTimeExpression;
use App\Data\Prayer\PrayerTimesDTO;
use App\Enums\EventPrayerTime;
use App\Jobs\RefreshPrayerTimes;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Services\Prayer\HardcodedPrayerFallback;
use App\Services\Prayer\PrayerTimesCache;
use Carbon\CarbonImmutable;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
    Cache::flush();

    $this->seed(EventRoleSeeder::class);
});

it('submits with zero live HTTP on a cold cache and records hardcoded provenance', function () {
    Http::preventStrayRequests();

    setSubmitEventFormState(Livewire::test(Create::class), submitEventPrayerFormData(['title' => 'Zero HTTP Submit Event']))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    Http::assertNothingSent();

    $event = Event::query()->where('title', 'Zero HTTP Submit Event')->sole();
    $expression = EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression?->metadata['prayer']['source'])->toBe(HardcodedPrayerFallback::SOURCE)
        ->and($expression?->resolved_at)->not->toBeNull();
});

it('uses the cached provider clock when the flag is on', function () {
    Http::preventStrayRequests();
    config(['prayer.enabled' => true]);

    $form = submitEventPrayerFormData(['title' => 'Provider Clock Submit Event']);
    $eventDate = $form['event_date'];

    app(PrayerTimesCache::class)->putMonthly('WLY01', substr($eventDate, 0, 7), [
        $eventDate => prayerCacheDto($eventDate),
    ], 'MY');

    setSubmitEventFormState(Livewire::test(Create::class), $form)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    Http::assertNothingSent();

    $event = Event::query()->where('title', 'Provider Clock Submit Event')->sole();

    // Seeded DTO: maghrib 19:02 MYT + the SelepasMaghrib offset.
    $offset = EventPrayerTime::SelepasMaghrib->getDefaultOffset()?->minutes() ?? 0;
    $expected = CarbonImmutable::parse($eventDate.' 19:02:00', 'Asia/Kuala_Lumpur')
        ->addMinutes($offset)
        ->utc();

    expect($event->starts_at->toIso8601String())->toBe($expected->toIso8601String());

    $expression = EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression?->metadata['prayer']['source'])->toBe('jakim:v2/WLY01');
});

it('falls back to hardcoded on a cold cache when the flag is on and refreshes after response', function () {
    Http::preventStrayRequests();
    Bus::fake();
    config(['prayer.enabled' => true]);

    $form = submitEventPrayerFormData(['title' => 'Cold Cache Submit Event']);

    setSubmitEventFormState(Livewire::test(Create::class), $form)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    Http::assertNothingSent();
    Bus::assertDispatchedAfterResponse(RefreshPrayerTimes::class);

    $event = Event::query()->where('title', 'Cold Cache Submit Event')->sole();
    $expected = CarbonImmutable::parse($form['event_date'].' 20:00:00', 'Asia/Kuala_Lumpur')->utc();

    expect($event->starts_at->toIso8601String())->toBe($expected->toIso8601String());

    $expression = EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression?->metadata['prayer']['source'])->toBe(HardcodedPrayerFallback::SOURCE);
});

it('ignores seeded cache when the flag is off', function () {
    Http::preventStrayRequests();
    Bus::fake();
    config(['prayer.enabled' => false]);

    $form = submitEventPrayerFormData(['title' => 'Flag Off Submit Event']);
    $eventDate = $form['event_date'];

    app(PrayerTimesCache::class)->putMonthly('WLY01', substr($eventDate, 0, 7), [
        $eventDate => prayerCacheDto($eventDate),
    ], 'MY');

    setSubmitEventFormState(Livewire::test(Create::class), $form)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    Http::assertNothingSent();
    Bus::assertNotDispatched(RefreshPrayerTimes::class);

    $event = Event::query()->where('title', 'Flag Off Submit Event')->sole();
    $expected = CarbonImmutable::parse($form['event_date'].' 20:00:00', 'Asia/Kuala_Lumpur')->utc();

    expect($event->starts_at->toIso8601String())->toBe($expected->toIso8601String());
});

it('submits on hardcoded estimates when the cache store is down', function () {
    Http::preventStrayRequests();
    Bus::fake();
    config(['prayer.enabled' => true]);
    useFailingPrayerCacheStore();

    $form = submitEventPrayerFormData(['title' => 'Cache Down Submit Event']);

    setSubmitEventFormState(Livewire::test(Create::class), $form)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    Http::assertNothingSent();

    $event = Event::query()->where('title', 'Cache Down Submit Event')->sole();
    $expected = CarbonImmutable::parse($form['event_date'].' 20:00:00', 'Asia/Kuala_Lumpur')->utc();

    expect($event->starts_at->toIso8601String())->toBe($expected->toIso8601String());

    $expression = EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression?->metadata['prayer']['source'])->toBe(HardcodedPrayerFallback::SOURCE);

    config(['prayer.cache.store' => null]);
});

it('submits with a real sync queue and performs no inline HTTP work', function () {
    Http::preventStrayRequests();
    config(['prayer.enabled' => true, 'queue.default' => 'sync']);

    // Livewire's test kernel terminates each request, so deferred
    // after-response jobs execute for real on the sync driver. Every
    // provider-host request records its app frames so the test proves all
    // HTTP originates from deferred jobs (post-response) and none from
    // request-time resolution.
    $origins = [];
    $recorder = function (string $host) use (&$origins) {
        return function ($request) use (&$origins, $host) {
            $origins[] = [
                'host' => $host,
                'frames' => collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60))
                    ->map(fn ($frame) => ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''))
                    ->filter(fn ($caller) => str_starts_with($caller, 'App\\'))
                    ->values()
                    ->all(),
            ];

            // Providers are unreachable: jobs must degrade to negatives
            // without caching anything the submit could read.
            return Http::response(['message' => 'unavailable'], 500);
        };
    };

    Http::fake([
        'api.waktusolat.app/*' => $recorder('jakim'),
        'ummahapi.com/*' => $recorder('ummah'),
        'api.aladhan.com/*' => $recorder('aladhan'),
    ]);

    // No Bus fake: dispatches run for real, proving submit never blocks
    // on them even while deferred jobs execute post-response.
    $form = submitEventPrayerFormData(['title' => 'Sync Queue Submit Event']);

    setSubmitEventFormState(Livewire::test(Create::class), $form)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    // Deferred jobs ran (Livewire terminates test requests on sync)...
    expect($origins)->not->toBeEmpty();

    // ...and every provider request came from a deferred job, never from
    // request-time resolution.
    foreach ($origins as $origin) {
        $frames = implode("\n", $origin['frames']);

        expect($frames)->toContain('App\\Jobs\\')
            ->and($frames)->not->toContain('App\\Actions\\')
            ->and($frames)->not->toContain('App\\Livewire\\')
            ->and($frames)->not->toContain('App\\Http\\');
    }

    $event = Event::query()->where('title', 'Sync Queue Submit Event')->sole();
    $expected = CarbonImmutable::parse($form['event_date'].' 20:00:00', 'Asia/Kuala_Lumpur')->utc();

    expect($event->starts_at->toIso8601String())->toBe($expected->toIso8601String());
});

it('survives real sync dispatch with a failing prayer cache store', function () {
    Http::preventStrayRequests();
    config(['prayer.enabled' => true, 'queue.default' => 'sync']);

    // No Bus fake: deferred jobs execute for real at request termination
    // while every prayer cache operation — data, negatives, and lock
    // acquisition — throws. Prayer owns its store end to end, so the
    // default store stays healthy for unrelated app code.
    useFailingPrayerCacheStore();

    $form = submitEventPrayerFormData(['title' => 'Default Cache Down Submit Event']);

    setSubmitEventFormState(Livewire::test(Create::class), $form)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Default Cache Down Submit Event')->sole();
    $expected = CarbonImmutable::parse($form['event_date'].' 20:00:00', 'Asia/Kuala_Lumpur')->utc();

    expect($event->starts_at->toIso8601String())->toBe($expected->toIso8601String());

    $expression = EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression?->metadata['prayer']['source'])->toBe(HardcodedPrayerFallback::SOURCE);

    config(['prayer.cache.store' => null]);
});

it('accepts an end time between the provider start and the hardcoded estimate', function () {
    Http::preventStrayRequests();
    Bus::fake();
    config(['prayer.enabled' => true]);

    $form = submitEventPrayerFormData([
        'title' => 'End Between Clocks Submit Event',
        'end_time' => '19:30',
    ]);
    $eventDate = $form['event_date'];

    app(PrayerTimesCache::class)->putMonthly('WLY01', substr($eventDate, 0, 7), [
        $eventDate => prayerCacheDto($eventDate),
    ], 'MY');

    // Seeded maghrib 19:02 MYT + Immediately (+5) => 19:07 provider start,
    // hardcoded estimate 20:00. 19:30 must validate against 19:07, not 20:00.
    setSubmitEventFormState(Livewire::test(Create::class), $form)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'End Between Clocks Submit Event')->sole();
    $expected = CarbonImmutable::parse($eventDate.' 19:07:00', 'Asia/Kuala_Lumpur')->utc();

    expect($event->starts_at->toIso8601String())->toBe($expected->toIso8601String());
});

it('persists a prayer offset crossing midnight on the following local day', function () {
    Http::preventStrayRequests();
    Bus::fake();
    config(['prayer.enabled' => true]);

    $form = submitEventPrayerFormData([
        'title' => 'Midnight Rollover Submit Event',
        'prayer_time' => EventPrayerTime::SelepasIsyak->value,
    ]);
    $eventDate = $form['event_date'];

    // Isha 23:58 MYT + Immediately (+5) => 00:03 on the following day.
    $day = CarbonImmutable::parse($eventDate.' 00:00:00', 'UTC');
    $dto = new PrayerTimesDTO(
        timesUtc: [
            'fajr' => $day->subDay()->setTime(21, 50),
            'sunrise' => $day->subDay()->setTime(22, 57),
            'dhuhr' => $day->setTime(5, 2),
            'asr' => $day->setTime(8, 18),
            'maghrib' => $day->setTime(11, 2),
            'isha' => $day->setTime(15, 58),
        ],
        source: 'jakim:v2/WLY01',
        fetchedAt: CarbonImmutable::now('UTC'),
        timezoneUsed: 'Asia/Kuala_Lumpur',
        date: $eventDate,
        zoneOrCell: 'WLY01',
    );

    app(PrayerTimesCache::class)->putMonthly('WLY01', substr($eventDate, 0, 7), [
        $eventDate => $dto,
    ], 'MY');

    setSubmitEventFormState(Livewire::test(Create::class), $form)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Midnight Rollover Submit Event')->sole();
    $expected = CarbonImmutable::parse($eventDate.' 00:00:00', 'Asia/Kuala_Lumpur')
        ->addDay()
        ->setTime(0, 3)
        ->utc();

    expect($event->starts_at->toIso8601String())->toBe($expected->toIso8601String());
});
