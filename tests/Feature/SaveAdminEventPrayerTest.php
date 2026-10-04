<?php

use AIArmada\Events\Models\EventTimeExpression;
use App\Actions\Events\SaveAdminEventAction;
use App\Data\Prayer\PrayerTimesDTO;
use App\Enums\EventFormat;
use App\Enums\EventPrayerTime;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Services\Prayer\JakimZoneResolver;
use App\Services\Prayer\PrayerTimesCache;
use App\Services\PrayerTimeExpressionResolver;
use App\Support\Submission\SubmissionContextResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakePrayerTimesApi();
    Http::preventStrayRequests();
    Cache::flush();
    Bus::fake();

    $this->seed(EventRoleSeeder::class);
});

it('saves a localized admin date with providers enabled', function () {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', [
        '2026-02-20' => prayerCacheDto('2026-02-20'),
    ], 'MY');

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Localized Admin Event',
        'primary_organizer_id' => $institution->getKey(),
        'institution_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => '20/02/2026',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    // Provider clock: maghrib 19:02 + Immediately (+5) => 19:07, proving
    // the d/m/Y input normalized before provider resolution.
    $expected = CarbonImmutable::parse('2026-02-20 19:07:00', 'Asia/Kuala_Lumpur')->utc();

    expect($event->starts_at->toIso8601String())->toBe($expected->toIso8601String());
});

it('resolves an online event from the organizer institution like frontend submit', function () {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $organizer = Institution::factory()->create(['status' => 'verified']);
    $organizer->primaryAddress()->update(['latitude' => 1.4927, 'longitude' => 103.7414]);
    $person = Person::factory()->create(['status' => 'verified']);

    app(JakimZoneResolver::class)->rememberZone(1.4927, 103.7414, 'JHR02');

    app(PrayerTimesCache::class)->putMonthly('JHR02', '2026-02', [
        '2026-02-20' => prayerCacheDto('2026-02-20', 'jakim:v2/JHR02'),
    ], 'MY');

    // The KL default carries a different maghrib so a KL resolution fails.
    $klBase = prayerCacheDto('2026-02-20');
    $klTimes = $klBase->timesUtc;
    $klTimes['maghrib'] = CarbonImmutable::parse('2026-02-20 11:12:00', 'UTC');
    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', [
        '2026-02-20' => new PrayerTimesDTO(
            timesUtc: $klTimes,
            source: $klBase->source,
            fetchedAt: $klBase->fetchedAt,
            timezoneUsed: $klBase->timezoneUsed,
            date: $klBase->date,
            zoneOrCell: $klBase->zoneOrCell,
        ),
    ], 'MY');

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Online Organizer Zone Event',
        'primary_organizer_id' => $organizer->getKey(),
        'event_format' => EventFormat::Online->value,
        'persons' => [$person->getKey()],
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    // Organizer zone: maghrib 19:02 + Immediately (+5) => 19:07.
    $expected = CarbonImmutable::parse('2026-02-20 19:07:00', 'Asia/Kuala_Lumpur')->utc();

    expect($event->starts_at->toIso8601String())->toBe($expected->toIso8601String())
        ->and($event->institution_id)->toBeNull();

    $expression = EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression?->metadata['prayer']['zone'] ?? null)->toBe('JHR02')
        ->and($expression?->metadata['prayer']['institution_id'] ?? null)->toBe((string) $organizer->getKey())
        ->and($expression?->metadata['prayer']['country'] ?? null)->toBe('MY');

    // Frontend submit resolves identical targets through the shared selector.
    [$frontendInstitutionId, $frontendVenueId] = app(SubmissionContextResolver::class)
        ->resolveTargetLocation(['event_format' => EventFormat::Online->value], $organizer);

    expect([$frontendInstitutionId, $frontendVenueId])->toBe([(string) $organizer->getKey(), null]);
});

it('preserves resolved timing on a title-only edit during provider unavailability', function () {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', [
        '2026-02-20' => prayerCacheDto('2026-02-20'),
    ], 'MY');

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Preserved Timing Event',
        'primary_organizer_id' => $institution->getKey(),
        'institution_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    $expected = CarbonImmutable::parse('2026-02-20 19:07:00', 'Asia/Kuala_Lumpur')->utc();

    expect($event->starts_at->toIso8601String())->toBe($expected->toIso8601String());

    // Providers go away: cold cache plus disabled flag. A title-only edit
    // must keep the resolved 19:07, not fall back to the 20:00 estimate.
    Cache::flush();
    config(['prayer.enabled' => false]);

    $updated = app(SaveAdminEventAction::class)->handle(['title' => 'Renamed Timing Event'], $actor, $event->fresh());

    expect($updated->starts_at->toIso8601String())->toBe($expected->toIso8601String());

    $expression = EventTimeExpression::query()
        ->where('event_id', $updated->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression?->metadata['prayer']['source'] ?? null)->toBe('jakim:v2/WLY01');
});

it('rejects prayer timings outside their calendar eligibility', function () {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    $base = [
        'primary_organizer_id' => $institution->getKey(),
        'institution_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'timezone' => 'Asia/Kuala_Lumpur',
    ];

    // Thursday Jumaat rejected without writes (2026-02-19 is Thursday).
    try {
        app(SaveAdminEventAction::class)->handle($base + [
            'title' => 'Thursday Jumaat Event',
            'event_date' => '2026-02-19',
            'prayer_time' => EventPrayerTime::SelepasJumaat->value,
        ], $actor);

        $this->fail('Expected ValidationException for Thursday Jumaat.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('prayer_time');
    }

    expect(Event::query()->where('title', 'Thursday Jumaat Event')->exists())->toBeFalse();

    // Friday Jumaat passes.
    $friday = app(SaveAdminEventAction::class)->handle($base + [
        'title' => 'Friday Jumaat Event',
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasJumaat->value,
    ], $actor);

    expect($friday->exists)->toBeTrue();

    // Moving it to Thursday is rejected too.
    try {
        app(SaveAdminEventAction::class)->handle(['event_date' => '2026-02-19'], $actor, $friday->fresh());

        $this->fail('Expected ValidationException for the Thursday move.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('prayer_time');
    }

    // Tarawih outside the declared evening window rejected without writes.
    try {
        app(SaveAdminEventAction::class)->handle($base + [
            'title' => 'Off Window Tarawih Event',
            'event_date' => '2026-03-20',
            'prayer_time' => EventPrayerTime::SelepasTarawih->value,
        ], $actor);

        $this->fail('Expected ValidationException for off-window Tarawih.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('prayer_time');
    }

    expect(Event::query()->where('title', 'Off Window Tarawih Event')->exists())->toBeFalse();

    // The declared boundary evening passes.
    $tarawih = app(SaveAdminEventAction::class)->handle($base + [
        'title' => 'Boundary Tarawih Event',
        'event_date' => '2026-03-19',
        'prayer_time' => EventPrayerTime::SelepasTarawih->value,
    ], $actor);

    expect($tarawih->exists)->toBeTrue();
});

it('rejects unrelated edits when persisted timing violates calendar eligibility', function () {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Friday Jumaat Event',
        'primary_organizer_id' => $institution->getKey(),
        'institution_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasJumaat->value,
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    // Persisted timing moved off Friday without a timing change: the
    // eligibility rule applies on every save, not just timing edits.
    $expression = EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->firstOrFail();
    $metadata = $expression->metadata;
    $metadata['prayer']['prayer_date'] = '2026-02-19';
    $expression->forceFill(['metadata' => $metadata])->save();

    try {
        app(SaveAdminEventAction::class)->handle(['title' => 'Renamed Jumaat Event'], $actor, $event->fresh());

        $this->fail('Expected ValidationException for the ineligible persisted timing.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('prayer_time');
    }
});

it('preserves complete provenance including country on a title-only edit during an outage', function () {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', [
        '2026-02-20' => prayerCacheDto('2026-02-20'),
    ], 'MY');

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Provenance Country Event',
        'primary_organizer_id' => $institution->getKey(),
        'institution_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    $before = EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->firstOrFail()
        ->metadata['prayer'];

    expect($before['country'] ?? null)->toBe('MY');

    // Providers go away: a title-only edit must keep every provenance
    // field, not just the clock — re-resolution needs the country.
    Cache::flush();
    config(['prayer.enabled' => false]);

    $updated = app(SaveAdminEventAction::class)->handle(['title' => 'Renamed Country Event'], $actor, $event->fresh());

    $after = EventTimeExpression::query()
        ->where('event_id', $updated->getKey())
        ->where('anchor_type', 'prayer')
        ->firstOrFail()
        ->metadata['prayer'];

    expect($after)->toEqual($before);

    // Providers return with a changed anchor: re-resolution still works
    // because the pinned country survived the outage edit.
    config(['prayer.enabled' => true]);

    $changed = prayerCacheDto('2026-02-20');
    $times = $changed->timesUtc;
    $times['maghrib'] = CarbonImmutable::parse('2026-02-20 11:12:00', 'UTC');
    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', [
        '2026-02-20' => new PrayerTimesDTO(
            timesUtc: $times,
            source: $changed->source,
            fetchedAt: $changed->fetchedAt,
            timezoneUsed: $changed->timezoneUsed,
            date: $changed->date,
            zoneOrCell: $changed->zoneOrCell,
        ),
    ], 'MY');

    $expression = EventTimeExpression::query()
        ->where('event_id', $updated->getKey())
        ->where('anchor_type', 'prayer')
        ->firstOrFail();

    $resolved = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    // Changed anchor: maghrib 19:12 + Immediately (+5) => 19:17.
    expect($resolved?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:17');
});

it('preserves start, end, and provenance on a title-only edit when the fallback overruns the end', function () {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', [
        '2026-02-20' => prayerCacheDto('2026-02-20'),
    ], 'MY');

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Timed Admin Event',
        'primary_organizer_id' => $institution->getKey(),
        'institution_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'end_time' => '19:30',
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    $expectedStart = CarbonImmutable::parse('2026-02-20 19:07:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedEnd = CarbonImmutable::parse('2026-02-20 19:30:00', 'Asia/Kuala_Lumpur')->utc();

    expect($event->starts_at->toIso8601String())->toBe($expectedStart->toIso8601String())
        ->and($event->ends_at->toIso8601String())->toBe($expectedEnd->toIso8601String());

    // Providers go away: the 20:00 fallback start lands after the persisted
    // 19:30 end, so a title-only edit must preserve before normalizing.
    Cache::flush();
    config(['prayer.enabled' => false]);

    $updated = app(SaveAdminEventAction::class)->handle(['title' => 'Renamed Timed Event'], $actor, $event->fresh());

    expect($updated->starts_at->toIso8601String())->toBe($expectedStart->toIso8601String())
        ->and($updated->ends_at->toIso8601String())->toBe($expectedEnd->toIso8601String());

    $expression = EventTimeExpression::query()
        ->where('event_id', $updated->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression?->metadata['prayer']['source'] ?? null)->toBe('jakim:v2/WLY01')
        ->and($expression?->metadata['prayer']['prayer_date'] ?? null)->toBe('2026-02-20');
});

it('reopens a midnight-rolled event on its original prayer day', function () {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    // Isha 23:58 + Immediately (+5) rolls the start past midnight.
    $base = prayerCacheDto('2026-02-20');
    $times = $base->timesUtc;
    $times['isha'] = CarbonImmutable::parse('2026-02-20 15:58:00', 'UTC');
    $dto = new PrayerTimesDTO(
        timesUtc: $times,
        source: $base->source,
        fetchedAt: $base->fetchedAt,
        timezoneUsed: $base->timezoneUsed,
        date: $base->date,
        zoneOrCell: $base->zoneOrCell,
    );

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', ['2026-02-20' => $dto], 'MY');

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Rolled Isyak Event',
        'primary_organizer_id' => $institution->getKey(),
        'institution_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasIsyak->value,
        'end_time' => '01:00',
        'end_date' => '2026-02-21',
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    $expectedStart = CarbonImmutable::parse('2026-02-21 00:03:00', 'Asia/Kuala_Lumpur')->utc();

    expect($event->starts_at->toIso8601String())->toBe($expectedStart->toIso8601String());

    // Reopening labels the original prayer day, not the rolled start day.
    $form = app(SaveAdminEventAction::class)->formStateForRecord($event->fresh());

    expect($form['event_date'])->toBe('2026-02-20');

    // Changing the end re-resolves Feb 20 Isha: the start must not shift to Feb 22.
    $updated = app(SaveAdminEventAction::class)->handle(['end_time' => '01:30'], $actor, $event->fresh());
    $expectedEnd = CarbonImmutable::parse('2026-02-21 01:30:00', 'Asia/Kuala_Lumpur')->utc();

    expect($updated->starts_at->toIso8601String())->toBe($expectedStart->toIso8601String())
        ->and($updated->ends_at->toIso8601String())->toBe($expectedEnd->toIso8601String());

    $expression = EventTimeExpression::query()
        ->where('event_id', $updated->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression?->metadata['prayer']['prayer_date'] ?? null)->toBe('2026-02-20');
});

it('stores admin Tarawih label-only and hydrates it back', function () {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Admin Tarawih Event',
        'primary_organizer_id' => $institution->getKey(),
        'institution_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasTarawih->value,
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    $expected = CarbonImmutable::parse('2026-02-20 22:30:00', 'Asia/Kuala_Lumpur')->utc();

    // Label-only like frontend storage: null anchor, estimate start.
    expect($event->prayer_reference)->toBeNull()
        ->and($event->starts_at->toIso8601String())->toBe($expected->toIso8601String());

    $expression = EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression?->anchor_code)->toBeNull()
        ->and($expression?->offset_minutes)->toBe(60);

    $form = app(SaveAdminEventAction::class)->formStateForRecord($event->fresh());

    expect($form['prayer_time'])->toBe(EventPrayerTime::SelepasTarawih->value);

    // A title-only save keeps the label-only expression (not custom time).
    $updated = app(SaveAdminEventAction::class)->handle(['title' => 'Renamed Tarawih Event'], $actor, $event->fresh());

    expect($updated->starts_at->toIso8601String())->toBe($expected->toIso8601String());

    $expression = EventTimeExpression::query()
        ->where('event_id', $updated->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression)->not->toBeNull()
        ->and($expression?->anchor_code)->toBeNull();
});

it('preserves non-default prayer offsets on title-only admin saves', function (PrayerOffset $offset) {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', [
        '2026-02-20' => prayerCacheDto('2026-02-20'),
    ], 'MY');

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Offset Prayer Event',
        'primary_organizer_id' => $institution->getKey(),
        'institution_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    // Maghrib 19:02 plus the dataset offset.
    $expectedStart = CarbonImmutable::parse('2026-02-20 19:02:00', 'Asia/Kuala_Lumpur')->addMinutes($offset->minutes());

    rewritePrayerExpressionOffset($event, $offset, $expectedStart);

    app(SaveAdminEventAction::class)->handle(['title' => 'Offset Prayer Event Renamed'], $actor, $event->fresh());

    $event = $event->fresh();
    $expression = EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->whereNull('event_occurrence_id')
        ->whereNull('event_session_id')
        ->firstOrFail();

    expect($expression->anchor_code)->toBe('maghrib')
        ->and($expression->relation)->toBe($offset->minutes() < 0 ? 'before' : 'after')
        ->and($expression->offset_minutes)->toBe(abs($offset->minutes()))
        ->and($expression->display_label)->toBe($offset->displayText(PrayerReference::Maghrib))
        ->and($event->starts_at->toIso8601String())->toBe($expectedStart->utc()->toIso8601String());

    // Warm-cache re-resolution honors the preserved offset.
    expect(app(PrayerTimeExpressionResolver::class)->resolve($expression->fresh())?->toIso8601String())
        ->toBe($expectedStart->utc()->toIso8601String());
})->with([
    'before 30' => [PrayerOffset::Before30],
    'after 30' => [PrayerOffset::After30],
    'after 60' => [PrayerOffset::After60],
    'before 15' => [PrayerOffset::Before15],
]);

function adminAddressedInstitution(string $iso2, string $name): Institution
{
    $country = $iso2 === 'MY'
        ? ensureTestMalaysiaCountry()
        : ensureTestAddressCountry(iso2: $iso2, name: $name, iso3: $iso2.'X', timezones: ['Asia/Jakarta'], phoneCode: '62');

    $institution = Institution::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($institution, ['country_id' => (string) $country->getKey()]);

    return $institution->fresh();
}

function adminPrayerCountry(Event $event): ?string
{
    return EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->whereNull('event_occurrence_id')
        ->whereNull('event_session_id')
        ->firstOrFail()->metadata['prayer']['country'] ?? null;
}

it('derives ID country when an admin moves an event to an ID target without country input', function () {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $malaysia = adminAddressedInstitution('MY', 'Malaysia');
    $indonesia = adminAddressedInstitution('ID', 'Indonesia');
    $person = Person::factory()->create(['status' => 'verified']);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', [
        '2026-02-20' => prayerCacheDto('2026-02-20'),
    ], 'MY');

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Moving Prayer Event',
        'primary_organizer_id' => $malaysia->getKey(),
        'institution_id' => $malaysia->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasIsyak->value,
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    expect(adminPrayerCountry($event))->toBe('MY');

    // Move the physical target to ID, omitting country input: the new
    // address governs, not the inherited MY provenance.
    $updated = app(SaveAdminEventAction::class)->handle([
        'institution_id' => $indonesia->getKey(),
    ], $actor, $event->fresh());

    expect(adminPrayerCountry($updated->fresh()))->toBe('ID');

    // ID Ramadan eligibility applies: Feb 17 admits Tarawih while exact
    // MY would reject it.
    $moved = app(SaveAdminEventAction::class)->handle([
        'event_date' => '2026-02-17',
        'prayer_time' => EventPrayerTime::SelepasTarawih->value,
    ], $actor, $updated->fresh());

    expect(adminPrayerCountry($moved->fresh()))->toBe('ID');
});

it('derives ID country when an admin moves an online organizer to ID without country input', function () {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $malaysia = adminAddressedInstitution('MY', 'Malaysia');
    $indonesia = adminAddressedInstitution('ID', 'Indonesia');
    $person = Person::factory()->create(['status' => 'verified']);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', [
        '2026-02-20' => prayerCacheDto('2026-02-20'),
    ], 'MY');

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Moving Online Event',
        'primary_organizer_id' => $malaysia->getKey(),
        'event_format' => EventFormat::Online->value,
        'persons' => [$person->getKey()],
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasIsyak->value,
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    expect(adminPrayerCountry($event))->toBe('MY');

    $updated = app(SaveAdminEventAction::class)->handle([
        'primary_organizer_id' => $indonesia->getKey(),
    ], $actor, $event->fresh());

    expect(adminPrayerCountry($updated->fresh()))->toBe('ID');

    $moved = app(SaveAdminEventAction::class)->handle([
        'event_date' => '2026-02-17',
        'prayer_time' => EventPrayerTime::SelepasTarawih->value,
    ], $actor, $updated->fresh());

    expect(adminPrayerCountry($moved->fresh()))->toBe('ID');
});

it('rejects explicit admin country input conflicting with the event location', function () {
    config(['prayer.enabled' => true]);

    $actor = User::factory()->create();
    $malaysia = adminAddressedInstitution('MY', 'Malaysia');
    $indonesia = adminAddressedInstitution('ID', 'Indonesia');
    $person = Person::factory()->create(['status' => 'verified']);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', [
        '2026-02-20' => prayerCacheDto('2026-02-20'),
    ], 'MY');

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Country Conflict Event',
        'primary_organizer_id' => $malaysia->getKey(),
        'institution_id' => $malaysia->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasIsyak->value,
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    $indonesiaCountryId = $indonesia->primaryAddress()->country_id;

    try {
        app(SaveAdminEventAction::class)->handle([
            'submission_country_id' => (string) $indonesiaCountryId,
        ], $actor, $event->fresh());

        $this->fail('Expected ValidationException for the country/location conflict.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('submission_country_id');
    }

    expect(adminPrayerCountry($event->fresh()))->toBe('MY');
});
