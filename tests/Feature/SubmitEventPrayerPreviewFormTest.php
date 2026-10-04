<?php

use AIArmada\Events\Models\EventTimeExpression;
use App\Actions\Events\SaveAdminEventAction;
use App\Data\Prayer\PrayerTimesDTO;
use App\Enums\EventFormat;
use App\Enums\EventPrayerTime;
use App\Jobs\RefreshPrayerTimes;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Models\Venue;
use App\Services\Prayer\JakimZoneResolver;
use App\Services\Prayer\PrayerTimesCache;
use App\Support\Submission\SubmitEventPrefill;
use Carbon\CarbonImmutable;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
    Http::preventStrayRequests();
    Cache::flush();
    config(['prayer.enabled' => true]);

    $this->seed(EventRoleSeeder::class);
});

it('refreshes exact prayer hints when the event date changes', function () {
    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');

    $component = Livewire::test(Create::class)
        ->set('data.submission_country_id', (string) ensureTestMalaysiaCountry()->getKey())
        ->set('data.event_date', '2026-10-15');

    $preview = json_decode((string) $component->get('data.prayer_preview'), true);

    // Fixture maghrib anchor 19:02 MYT + Immediately (+5) => 19:07.
    expect($preview['exact'])->toBeTrue()
        ->and($preview['zone'])->toBe('WLY01')
        ->and($preview['starts']['selepas_maghrib'])->toBe('19:07');

    Http::assertNothingSent();
});

it('clears prayer hints when the event date is removed', function () {
    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');

    $component = Livewire::test(Create::class)
        ->set('data.submission_country_id', (string) ensureTestMalaysiaCountry()->getKey())
        ->set('data.event_date', '2026-10-15')
        ->set('data.event_date', null);

    expect($component->get('data.prayer_preview'))->toBeNull();
});

it('builds no hints when providers are disabled', function () {
    config(['prayer.enabled' => false]);
    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');

    $component = Livewire::test(Create::class)
        ->set('data.submission_country_id', (string) ensureTestMalaysiaCountry()->getKey())
        ->set('data.event_date', '2026-10-15');

    expect($component->get('data.prayer_preview'))->toBeNull();

    Http::assertNothingSent();
});

it('defers refresh and sends zero HTTP on cold form updates', function () {
    Bus::fake();

    $component = Livewire::test(Create::class)
        ->set('data.submission_country_id', (string) ensureTestMalaysiaCountry()->getKey())
        ->set('data.event_date', '2026-10-15');

    // Cold cache: the hint degrades to hardcoded estimates, computed
    // cache-only, while the miss is deferred to after-response jobs.
    $preview = json_decode((string) $component->get('data.prayer_preview'), true);

    expect($preview['exact'])->toBeFalse()
        ->and($preview['starts']['selepas_maghrib'])->toBe('20:00');

    Http::assertNothingSent();
    Bus::assertDispatchedAfterResponse(RefreshPrayerTimes::class);
});

it('keeps preview and submit on the organizer zone when switching online', function () {
    Bus::fake();

    $organizer = Institution::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $organizer->primaryAddress()->update(['latitude' => 3.139, 'longitude' => 101.6869]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $venue->primaryAddress()->update(['latitude' => 1.4927, 'longitude' => 103.7414]);

    $zones = app(JakimZoneResolver::class);
    $zones->rememberZone(3.139, 101.6869, 'WLY01');
    $zones->rememberZone(1.4927, 103.7414, 'JHR02');

    $form = submitEventPrayerFormData([
        'title' => 'Online Parity Submit Event',
        'primary_organizer_id' => $organizer->getKey(),
        'location_same_as_institution' => false,
        'location_type' => 'venue',
        'location_venue_id' => $venue->getKey(),
    ]);
    $eventDate = $form['event_date'];
    $yearMonth = substr($eventDate, 0, 7);

    app(PrayerTimesCache::class)->putMonthly('WLY01', $yearMonth, [$eventDate => prayerCacheDto($eventDate)], 'MY');
    app(PrayerTimesCache::class)->putMonthly('JHR02', $yearMonth, [$eventDate => prayerCacheDto($eventDate, 'jakim:v2/JHR02')], 'MY');

    $component = setSubmitEventFormState(Livewire::test(Create::class), $form);

    // Physical at the Johor venue: the hint follows the venue.
    $preview = json_decode((string) $component->get('data.prayer_preview'), true);
    expect($preview['zone'])->toBe('JHR02');

    // Switching online repoints the hint at the KL organizer.
    $component->set('data.event_format', EventFormat::Online->value);
    $preview = json_decode((string) $component->get('data.prayer_preview'), true);
    expect($preview['zone'])->toBe('WLY01');

    $component->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Online Parity Submit Event')->sole();
    $expression = EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    // Submit persists the organizer zone the preview showed.
    expect($expression?->metadata['prayer']['zone'] ?? null)->toBe('WLY01')
        ->and($expression?->metadata['prayer']['source'] ?? null)->toBe('jakim:v2/WLY01');
});

it('accepts an end between the provider start and a stale cold hint', function () {
    Bus::fake();

    $form = submitEventPrayerFormData(['title' => 'Stale Hint Submit Event', 'end_time' => '19:30']);
    $eventDate = $form['event_date'];
    $yearMonth = substr($eventDate, 0, 7);

    // Cold cache: the recorded hint degrades to the 20:00 estimate.
    $component = setSubmitEventFormState(Livewire::test(Create::class), $form);
    $preview = json_decode((string) $component->get('data.prayer_preview'), true);

    expect($preview['starts']['selepas_maghrib'])->toBe('20:00');

    // Deferred warming lands after the hint was recorded.
    app(PrayerTimesCache::class)->putMonthly('WLY01', $yearMonth, [$eventDate => prayerCacheDto($eventDate)], 'MY');

    // The end (19:30) sits between the provider start (19:07) and the stale
    // hint (20:00): the fresh comparison accepts what submit persists.
    $component->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Stale Hint Submit Event')->sole();

    expect($event->starts_at->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:07')
        ->and($event->ends_at->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:30');
});

it('preserves a frontend Tarawih expression across an admin title-only save', function () {
    Bus::fake();

    // Future Ramadan date: submit rejects Tarawih outside Ramadan and
    // rejects past starts.
    $form = submitEventPrayerFormData([
        'title' => 'Frontend Tarawih Event',
        'event_date' => '2027-02-10',
        'prayer_time' => EventPrayerTime::SelepasTarawih->value,
    ]);

    setSubmitEventFormState(Livewire::test(Create::class), $form)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Frontend Tarawih Event')->sole();

    // Frontend stores Tarawih label-only with a null anchor.
    expect($event->prayer_reference)->toBeNull();

    $actor = User::factory()->create();
    $updated = app(SaveAdminEventAction::class)->handle(['title' => 'Renamed Tarawih Event'], $actor, $event->fresh());

    $expression = EventTimeExpression::query()
        ->where('event_id', $updated->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression)->not->toBeNull()
        ->and($expression?->anchor_code)->toBeNull()
        ->and($updated->starts_at->toIso8601String())->toBe($event->starts_at->toIso8601String());
});

it('duplicates a midnight-rolled event on its original prayer day', function () {
    Bus::fake();

    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $person = Person::factory()->create(['status' => 'verified']);

    $eventDate = now()->addDays(9)->format('Y-m-d');
    $yearMonth = substr($eventDate, 0, 7);

    // Isha 23:58 KL + 5 rolls the start past midnight.
    $base = prayerCacheDto($eventDate);
    $times = $base->timesUtc;
    $times['isha'] = CarbonImmutable::parse($eventDate.' 15:58:00', 'UTC');
    app(PrayerTimesCache::class)->putMonthly('WLY01', $yearMonth, [$eventDate => new PrayerTimesDTO(
        timesUtc: $times,
        source: $base->source,
        fetchedAt: $base->fetchedAt,
        timezoneUsed: $base->timezoneUsed,
        date: $base->date,
        zoneOrCell: $base->zoneOrCell,
    )], 'MY');

    $source = app(SaveAdminEventAction::class)->handle([
        'title' => 'Rolled Source Event',
        'primary_organizer_id' => $institution->getKey(),
        'institution_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => $eventDate,
        'prayer_time' => EventPrayerTime::SelepasIsyak->value,
        'end_time' => '01:00',
        'end_date' => CarbonImmutable::parse($eventDate)->addDay()->format('Y-m-d'),
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    $expectedStart = CarbonImmutable::parse($eventDate.' 00:00:00', 'Asia/Kuala_Lumpur')->addDay()->setTime(0, 3)->utc();

    expect($source->starts_at->toIso8601String())->toBe($expectedStart->toIso8601String());

    $defaults = SubmitEventPrefill::duplicateDefaults(
        $source->fresh(), null, (string) ensureTestMalaysiaCountry()->getKey()
    );

    // Form initialization shows the prayer day, not the rolled start day.
    expect($defaults['event_date'])->toBe($eventDate)
        ->and($defaults['prayer_time'])->toBe(EventPrayerTime::SelepasIsyak->value);

    $base = submitEventPrayerFormData(['title' => 'Duplicated Rolled Event']);
    $form = array_merge($base, array_filter($defaults, fn ($value) => $value !== null), [
        'title' => 'Duplicated Rolled Event',
        'event_category_ids' => $base['event_category_ids'],
        'domain_tags' => $base['domain_tags'],
        'discipline_tags' => $base['discipline_tags'],
        'languages' => $base['languages'],
        'submitter_name' => 'Guest Submitter',
        'submitter_email' => 'guest@example.com',
    ]);

    setSubmitEventFormState(Livewire::test(Create::class), $form)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $duplicate = Event::query()->where('title', 'Duplicated Rolled Event')->sole();

    expect($duplicate->starts_at->toIso8601String())->toBe($expectedStart->toIso8601String());
});

it('matches frontend country and start for an address-less online event', function () {
    Bus::fake();

    $indonesia = ensureTestAddressCountry(iso2: 'ID', name: 'Indonesia', iso3: 'IDN', timezones: ['Asia/Jakarta'], phoneCode: '62');
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);

    $form = submitEventPrayerFormData([
        'title' => 'Address-less Online Parity Event',
        'primary_organizer_id' => $organizer->getKey(),
        'event_format' => EventFormat::Online->value,
        'submission_country_id' => (string) $indonesia->getKey(),
    ]);
    $eventDate = $form['event_date'];
    $yearMonth = substr($eventDate, 0, 7);

    // Conflicting warm MY cache: neither path may read KL anchors for ID.
    app(PrayerTimesCache::class)->putMonthly('WLY01', $yearMonth, [$eventDate => prayerCacheDto($eventDate)], 'MY');

    setSubmitEventFormState(Livewire::test(Create::class), $form)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $frontend = Event::query()->where('title', 'Address-less Online Parity Event')->sole();
    $frontendProvenance = EventTimeExpression::query()
        ->where('event_id', $frontend->getKey())
        ->where('anchor_type', 'prayer')
        ->firstOrFail()->metadata['prayer'];

    $actor = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified']);

    $admin = app(SaveAdminEventAction::class)->handle([
        'title' => 'Address-less Online Admin Event',
        'primary_organizer_id' => $organizer->getKey(),
        'event_format' => EventFormat::Online->value,
        'submission_country_id' => (string) $indonesia->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => $eventDate,
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'Asia/Jakarta',
    ], $actor);

    $adminProvenance = EventTimeExpression::query()
        ->where('event_id', $admin->getKey())
        ->where('anchor_type', 'prayer')
        ->firstOrFail()->metadata['prayer'];

    // Both fall back to the 20:00 estimate under ID — never WLY01 anchors.
    expect($admin->starts_at->toIso8601String())->toBe($frontend->starts_at->toIso8601String())
        ->and($adminProvenance['country'] ?? null)->toBe('ID')
        ->and($frontendProvenance['country'] ?? null)->toBe('ID')
        ->and($adminProvenance['source'] ?? null)->toBe($frontendProvenance['source'] ?? null)
        ->and($admin->starts_at->setTimezone('Asia/Jakarta')->format('H:i'))->toBe('20:00');
});
