<?php

declare(strict_types=1);

use App\Livewire\Pages\Events\Index;
use App\Livewire\Pages\Institutions\Show;
use App\Models\Event;
use App\Models\Institution;
use App\Platform\Time\UserDateTimeFormatter;
use App\Shared\Events\Enums\EventFormat;
use App\Support\Location\VisitorCountryResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * D0 characterization: pin the time model used by discovery after the D1a
 * listing rulings.
 *
 * - Date-range filters (`starts_after` / `starts_before`) parse the submitted
 *   calendar date in the viewer timezone and convert it to UTC boundaries.
 * - Past/upcoming splits classify the absolute end instant: an event stays
 *   upcoming while `ends_at` (falling back to `starts_at`) has not passed,
 *   and becomes past once that instant has passed.
 */
beforeEach(function (): void {
    app(VisitorCountryResolver::class)->forget();
});

function d0TimezoneEvent(string $title, string $startsAtUtc, ?string $institutionId = null, ?string $endsAtUtc = null): Event
{
    $startsAt = Carbon::parse($startsAtUtc, 'UTC');

    return Event::factory()->create([
        'title' => $title,
        'institution_id' => $institutionId ?? Institution::factory(),
        'default_venue_id' => null,
        'delivery_mode' => EventFormat::Physical->value,
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => $startsAt,
        'ends_at' => $endsAtUtc !== null ? Carbon::parse($endsAtUtc, 'UTC') : $startsAt->copy()->addMinutes(30),
    ]);
}

it('converts viewer-local date filters to UTC boundaries around midnight', function (): void {
    $this->travelTo(Carbon::parse('2026-04-16 16:30:00', 'UTC'));

    $request = Request::create('/', 'GET', [], ['user_timezone' => 'Asia/Kuala_Lumpur']);

    // The viewer is already on 2026-04-17 (00:30 MYT) while the server clock
    // is still on 2026-04-16 (16:30 UTC).
    expect(now()->toDateString())->toBe('2026-04-16')
        ->and(UserDateTimeFormatter::userNow($request)->toDateString())->toBe('2026-04-17');

    // 2026-04-17 in Asia/Kuala_Lumpur starts at 2026-04-16 16:00 UTC.
    expect(UserDateTimeFormatter::parseUserDateToUtc('2026-04-17', request: $request)?->format('Y-m-d H:i'))
        ->toBe('2026-04-16 16:00');

    d0TimezoneEvent('D0 Tz Local Previous Day', '2026-04-16 15:30:00', endsAtUtc: '2026-04-16 15:45:00');
    d0TimezoneEvent('D0 Tz Local Selected Day', '2026-04-16 17:00:00');
    $component = Livewire::withCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->test(Index::class)
        ->set('time_scope', 'all')
        ->set('starts_after', '2026-04-17')
        ->set('starts_before', '2026-04-17');

    $titles = $component->instance()
        ->events
        ->getCollection()
        ->pluck('title')
        ->all();

    expect($titles)
        ->toContain('D0 Tz Local Selected Day')
        ->not->toContain('D0 Tz Local Previous Day');
});

it('splits past and upcoming by the event end instant after the viewer crosses midnight', function (): void {
    $this->travelTo(Carbon::parse('2026-04-16 16:30:00', 'UTC'));

    $institution = Institution::factory()->create(['status' => 'verified']);

    // Both starts are on 2026-04-17 in the viewer timezone while the server
    // clock is 2026-04-16 16:30 UTC. The ongoing event ends at 16:45 UTC and
    // stays upcoming; the earlier event ended at 16:10 UTC and is past.
    d0TimezoneEvent('D0 Tz Ongoing', '2026-04-16 16:15:00', $institution->getKey(), endsAtUtc: '2026-04-16 16:45:00');
    d0TimezoneEvent('D0 Tz Ended', '2026-04-16 16:05:00', $institution->getKey(), endsAtUtc: '2026-04-16 16:10:00');

    $component = Livewire::withCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->test(Show::class, ['institution' => $institution]);

    $upcoming = $component->instance()
        ->getUpcomingEventsProperty()
        ->map(static fn ($leaf): string => $leaf->event->title)
        ->all();
    $past = $component->instance()
        ->getPastEventsProperty()
        ->map(static fn ($leaf): string => $leaf->event->title)
        ->all();

    expect($upcoming)->toBe(['D0 Tz Ongoing'])
        ->and($past)->toBe(['D0 Tz Ended']);

    // The local-date filter for "today" (2026-04-17) still includes both
    // events; only the past/upcoming split separates them by end instant.
    $component->set('customStartDate', '2026-04-17')
        ->set('customEndDate', '2026-04-17')
        ->call('applyCustomDateRange');

    $upcomingAfterRange = $component->instance()
        ->getUpcomingEventsProperty()
        ->map(static fn ($leaf): string => $leaf->event->title)
        ->all();

    expect($upcomingAfterRange)->toBe(['D0 Tz Ongoing'])
        ->and($component->instance()->getPastEventsProperty()->map(static fn ($leaf): string => $leaf->event->title)->all())
        ->toBe(['D0 Tz Ended']);
});
