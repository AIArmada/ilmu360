<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventSession;
use AIArmada\Events\Models\EventTimeExpression;
use App\Actions\Prayer\ResolvePrayerStartClockAction;
use App\Data\Prayer\PrayerQuery;
use App\Enums\EventPrayerTime;
use App\Enums\InstitutionVenueRole;
use App\Enums\PrayerOffset;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Venue;
use App\Services\Prayer\AladhanPrayerProvider;
use App\Services\Prayer\PrayerProviderRegistry;
use App\Services\Prayer\PrayerTimesCache;
use App\Services\PrayerTimeExpressionResolver;
use App\Support\Prayer\PrayerLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('returns null when anchor type is not prayer', function () {
    $expression = EventTimeExpression::factory()->create([
        'time_mode' => 'fixed',
        'anchor_type' => 'fixed',
        'anchor_code' => null,
    ]);

    $result = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($result)->toBeNull();
});

it('returns null when anchor code is null', function () {
    $expression = EventTimeExpression::factory()->create([
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => null,
    ]);

    $result = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($result)->toBeNull();
});

it('returns null when anchor code is not a valid prayer reference', function () {
    $expression = EventTimeExpression::factory()->create([
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'invalid_prayer',
    ]);

    $result = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($result)->toBeNull();
});

it('returns null when parent event is not found', function () {
    $existingEvent = Event::factory()->create();
    $expression = EventTimeExpression::factory()->create([
        'event_id' => $existingEvent->id,
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
    ]);

    $expression->event_id = '00000000-0000-0000-0000-000000000000';

    $result = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($result)->toBeNull();
});

it('keeps the persisted instant for expressions without provenance', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'published_at' => now(),
        'starts_at' => now()->addDays(7),
        'ends_at' => now()->addDays(7)->addHours(2),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $expression = EventTimeExpression::factory()->create([
        'event_id' => $event->id,
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 30,
        'metadata' => null,
        'resolved_starts_at' => CarbonImmutable::parse('2026-10-15 11:32:00', 'UTC'),
        'resolved_at' => now(),
    ]);

    $resolved = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($resolved?->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-10-15 11:32:00');
});

it('returns null when no provenance and no persisted instant exist', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'published_at' => now(),
        'starts_at' => now()->addDays(7),
        'ends_at' => now()->addDays(7)->addHours(2),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $expression = EventTimeExpression::factory()->create([
        'event_id' => $event->id,
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 30,
        'metadata' => null,
        'resolved_starts_at' => null,
        'resolved_at' => null,
    ]);

    expect(app(PrayerTimeExpressionResolver::class)->resolve($expression))->toBeNull();
});

it('round-trips every offset case through stored relation and magnitude', function () {
    fakePrayerTimesApi();
    Bus::fake();
    config(['prayer.enabled' => true]);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto('2026-10-15')], 'MY');

    $institution = Institution::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'country_code' => 'MY',
        'city' => 'Kuala Lumpur',
        'state' => 'Wilayah Persekutuan',
    ]);

    foreach (PrayerOffset::cases() as $expected) {
        $event = Event::factory()->create([
            'institution_id' => $institution->getKey(),
            'status' => 'approved',
            'published_at' => now(),
            'starts_at' => CarbonImmutable::parse('2026-10-15 11:07:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-10-15 13:07:00', 'UTC'),
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        $expression = EventTimeExpression::factory()->create([
            'event_id' => $event->getKey(),
            'time_mode' => 'prayer_time',
            'anchor_type' => 'prayer',
            'anchor_code' => 'maghrib',
            'relation' => $expected->minutes() < 0 ? 'before' : 'after',
            'offset_minutes' => abs($expected->minutes()),
            'metadata' => ['prayer' => [
                'source' => 'jakim:v2/WLY01',
                'fetched_at' => now()->toIso8601String(),
                'country' => 'MY',
                'zone' => 'WLY01',
                'prayer_date' => '2026-10-15',
            ]],
        ]);

        $resolved = app(PrayerTimeExpressionResolver::class)->resolve($expression);

        // Fixture maghrib anchor 11:02Z on D, plus the signed offset.
        $expectedInstant = CarbonImmutable::parse('2026-10-15 11:02:00', 'UTC')->addMinutes($expected->minutes());

        expect($resolved?->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe($expectedInstant->format('Y-m-d H:i:s'));
    }
});

it('treats a missing relation as after', function () {
    fakePrayerTimesApi();
    Bus::fake();
    config(['prayer.enabled' => true]);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto('2026-10-15')], 'MY');

    $institution = Institution::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'country_code' => 'MY',
        'city' => 'Kuala Lumpur',
        'state' => 'Wilayah Persekutuan',
    ]);

    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'published_at' => now(),
        'starts_at' => CarbonImmutable::parse('2026-10-15 11:07:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-15 13:07:00', 'UTC'),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $expression = EventTimeExpression::factory()->create([
        'event_id' => $event->getKey(),
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => null,
        'offset_minutes' => 15,
        'metadata' => ['prayer' => [
            'source' => 'jakim:v2/WLY01',
            'fetched_at' => now()->toIso8601String(),
            'country' => 'MY',
            'zone' => 'WLY01',
            'prayer_date' => '2026-10-15',
        ]],
    ]);

    $resolved = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($resolved?->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-10-15 11:17:00');
});

it('re-resolves rolled provenance-bearing event expressions on the original prayer day', function () {
    fakePrayerTimesApi();
    Bus::fake();
    config(['prayer.enabled' => true]);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto('2026-10-15')], 'MY');

    $institution = Institution::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'country_code' => 'MY',
        'city' => 'Kuala Lumpur',
        'state' => 'Wilayah Persekutuan',
    ]);

    // Late-night event rolled past midnight: the stored start sits on D+1
    // in MYT while the prayer anchor belongs to D.
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'published_at' => now(),
        'starts_at' => CarbonImmutable::parse('2026-10-15 16:33:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-15 18:33:00', 'UTC'),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $expression = EventTimeExpression::factory()->create([
        'event_id' => $event->getKey(),
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 30,
        'metadata' => ['prayer' => [
            'source' => 'jakim:v2/WLY01',
            'fetched_at' => now()->toIso8601String(),
            'country' => 'MY',
            'zone' => 'WLY01',
            'prayer_date' => '2026-10-15',
        ]],
    ]);

    $resolved = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    // Fixture maghrib anchor 19:02 MYT on D + 30 min => 11:32Z on D:
    // re-resolution anchors on the prayer day, not the rolled start day.
    expect($resolved?->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-10-15 11:32:00');
});

it('re-resolves session expressions on the session prayer day instead of the parent day', function () {
    fakePrayerTimesApi();
    Bus::fake();
    config(['prayer.enabled' => true]);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-17' => prayerCacheDto('2026-10-17')], 'MY');

    $institution = Institution::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'country_code' => 'MY',
        'city' => 'Kuala Lumpur',
        'state' => 'Wilayah Persekutuan',
    ]);

    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'published_at' => now(),
        'starts_at' => CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-15 14:00:00', 'UTC'),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $session = EventSession::query()->create([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => $event->primaryOccurrence->getKey(),
        'title' => 'Sesi Kedua',
        'slug' => 'sesi-kedua',
        'starts_at' => CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-17 14:00:00', 'UTC'),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'scheduled',
        'visibility' => 'public',
        'delivery_mode' => 'in_person',
        'sort_order' => 1,
    ]);

    $session->locations()->create([
        'event_id' => $event->getKey(),
        'location_role' => 'primary',
        'locationable_type' => $institution->getMorphClass(),
        'locationable_id' => $institution->getKey(),
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);

    $expression = EventTimeExpression::factory()->create([
        'event_id' => $event->getKey(),
        'event_session_id' => $session->getKey(),
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 30,
        'metadata' => ['prayer' => [
            'source' => 'jakim:v2/WLY01',
            'fetched_at' => now()->toIso8601String(),
            'country' => 'MY',
            'zone' => 'WLY01',
            'prayer_date' => '2026-10-17',
        ]],
    ]);

    $resolved = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    // The session anchor belongs to the session day, not the parent day.
    expect($resolved?->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-10-17 11:32:00');
});

it('keeps the persisted instant when providers are disabled', function () {
    config(['prayer.enabled' => false]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'published_at' => now(),
        'starts_at' => CarbonImmutable::parse('2026-10-15 16:33:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-15 18:33:00', 'UTC'),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $expression = EventTimeExpression::factory()->create([
        'event_id' => $event->getKey(),
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 30,
        'metadata' => ['prayer' => [
            'source' => 'jakim:v2/WLY01',
            'fetched_at' => now()->toIso8601String(),
            'country' => 'MY',
            'zone' => 'WLY01',
            'prayer_date' => '2026-10-15',
        ]],
        'resolved_starts_at' => CarbonImmutable::parse('2026-10-15 11:32:00', 'UTC'),
        'resolved_at' => now(),
    ]);

    $resolved = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    // Disabled providers skip provider re-resolution even with usable
    // provenance; the persisted instant stands.
    expect($resolved?->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-10-15 11:32:00');
});

it('re-resolves no-GPS addresses through the persisted resolver coordinates', function () {
    fakePrayerTimesApi();
    Bus::fake();
    config(['prayer.enabled' => true]);

    $institution = Institution::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'country_code' => 'MY',
        'city' => 'Kuala Lumpur',
        'state' => 'Wilayah Persekutuan',
        'latitude' => null,
        'longitude' => null,
    ]);

    $location = PrayerLocation::fromAddress($institution->primaryAddress(), 'MY');

    expect($location['latitude'])->toBeNull()
        ->and($location['longitude'])->toBeNull();

    // Whatever the resolver returns for this coordinate-less address,
    // warm exactly that coordinate cell — submission reads it, and
    // re-resolution must read it too instead of the no-coords cell.
    // The zone month itself stays cold: the mirror is down for it.
    $registry = app(PrayerProviderRegistry::class);
    $resolved = $registry->zoneResolverFor('MY')->resolve(
        $location['latitude'], $location['longitude'], $location['stateCode'], $location['districtCandidates'],
    );
    $methods = $registry->methodsFor('MY');

    expect($resolved['lat'])->not->toBeNull()
        ->and($resolved['lng'])->not->toBeNull();

    $aladhan = app(AladhanPrayerProvider::class);
    $probe = new PrayerQuery('MY', '2026-10-15', 'Asia/Kuala_Lumpur', $resolved['lat'], $resolved['lng'], $resolved['zone'], $methods['ummah'], $methods['madhab']);
    $cell = "MY:Asia/Kuala_Lumpur:{$aladhan->cacheIdentitySegment($probe)}:".sprintf('%.2F:%.2F', $resolved['lat'], $resolved['lng']);
    app(PrayerTimesCache::class)->putDaily(
        'aladhan',
        $cell,
        '2026-10-15',
        prayerCacheDto('2026-10-15', sprintf('aladhan:%s/%s', config('prayer.methods.MY.aladhan'), $methods['madhab'])),
    );

    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'published_at' => now(),
        'starts_at' => CarbonImmutable::parse('2026-10-15 11:07:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-15 13:07:00', 'UTC'),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $submitted = app(ResolvePrayerStartClockAction::class)->handle(
        'MY', '2026-10-15', 'Asia/Kuala_Lumpur', EventPrayerTime::SelepasMaghrib,
        $location['latitude'], $location['longitude'], $location['stateCode'], $location['districtCandidates'],
    );

    expect($submitted)->not->toBeNull();

    $expression = EventTimeExpression::factory()->create([
        'event_id' => $event->getKey(),
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 5,
        'metadata' => ['prayer' => [
            'source' => $submitted['source'],
            'fetched_at' => $submitted['fetched_at'],
            'country' => $submitted['country'],
            'zone' => $submitted['zone'],
            'prayer_date' => '2026-10-15',
            'lat' => $submitted['lat'],
            'lng' => $submitted['lng'],
            'institution_id' => (string) $institution->getKey(),
        ]],
    ]);

    $resolvedStart = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    // Same anchor + same +5 offset as submission: the scoped UTC instant
    // round-trips exactly, with no live HTTP fallback.
    expect($resolvedStart?->toIso8601String())->toBe($submitted['starts_at']);
    Http::assertNothingSent();
});

it('borrows the venue owner address when re-resolving unpinned session expressions', function () {
    fakePrayerTimesApi();
    Bus::fake();
    config(['prayer.enabled' => true]);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-17' => prayerCacheDto('2026-10-17')], 'MY');

    $owner = Institution::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($owner, [
        'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'country_code' => 'MY',
        'city' => 'Kuala Lumpur',
        'state' => 'Wilayah Persekutuan',
    ]);

    $venue = OwnerContext::withOwner(null, fn (): Venue => Venue::factory()->create());
    OwnerContext::withOwner(null, function () use ($venue): void {
        $venue->addresses()->detach();
        $venue->unsetRelation('addresses');
    });
    $venue->institutions()->attach($owner->getKey(), ['role' => InstitutionVenueRole::Operated, 'is_primary' => true]);

    $event = Event::factory()->create([
        'institution_id' => $owner->getKey(),
        'status' => 'approved',
        'published_at' => now(),
        'starts_at' => CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-15 14:00:00', 'UTC'),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $session = EventSession::query()->create([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => $event->primaryOccurrence->getKey(),
        'title' => 'Sesi Venue',
        'slug' => 'sesi-venue',
        'starts_at' => CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-17 14:00:00', 'UTC'),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'scheduled',
        'visibility' => 'public',
        'delivery_mode' => 'in_person',
        'sort_order' => 1,
    ]);

    $session->locations()->create([
        'event_id' => $event->getKey(),
        'location_role' => 'primary',
        'locationable_type' => $venue->getMorphClass(),
        'locationable_id' => $venue->getKey(),
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);

    // Unpinned provenance: no coordinates or targets.
    $expression = EventTimeExpression::factory()->create([
        'event_id' => $event->getKey(),
        'event_session_id' => $session->getKey(),
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 30,
        'metadata' => ['prayer' => [
            'source' => 'jakim:v2/WLY01',
            'fetched_at' => now()->toIso8601String(),
            'country' => 'MY',
            'zone' => 'WLY01',
            'prayer_date' => '2026-10-17',
        ]],
    ]);

    $resolved = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    // The owner's KL address resolves the session-day anchor — the same
    // approximation submission used — instead of the persisted instant.
    expect($resolved?->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-10-17 11:32:00');
    Http::assertNothingSent();
});

it('re-resolves online sessions through the persisted organizer targets', function () {
    fakePrayerTimesApi();
    Bus::fake();
    config(['prayer.enabled' => true]);

    $indonesia = ensureTestAddressCountry(iso2: 'ID', name: 'Indonesia', iso3: 'IDN', timezones: ['Asia/Jakarta'], phoneCode: '62');

    $organizer = Institution::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($organizer, [
        'country_id' => (string) $indonesia->getKey(),
        'country_code' => 'ID',
        'city' => 'Jakarta',
        'latitude' => -6.2,
        'longitude' => 106.8,
    ]);

    // The parent stays in KL on another day: the session must resolve in
    // its own organizer scope, never inherit the parent's.
    $parentInstitution = Institution::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($parentInstitution, [
        'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'country_code' => 'MY',
        'city' => 'Kuala Lumpur',
        'state' => 'Wilayah Persekutuan',
    ]);

    $event = Event::factory()->create([
        'institution_id' => $parentInstitution->getKey(),
        'status' => 'approved',
        'published_at' => now(),
        'starts_at' => CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-15 14:00:00', 'UTC'),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $session = EventSession::query()->create([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => $event->primaryOccurrence->getKey(),
        'title' => 'Sesi Dalam Talian',
        'slug' => 'sesi-dalam-talian',
        'starts_at' => CarbonImmutable::parse('2026-10-18 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-18 14:00:00', 'UTC'),
        'timezone' => 'Asia/Jakarta',
        'status' => 'scheduled',
        'visibility' => 'public',
        'delivery_mode' => 'online',
        'sort_order' => 1,
    ]);

    // Online sessions persist no locations.

    $methods = app(PrayerProviderRegistry::class)->methodsFor('ID');
    $probe = new PrayerQuery('ID', '2026-10-18', 'Asia/Jakarta', -6.2, 106.8, null, $methods['ummah'], $methods['madhab']);
    $segment = app(AladhanPrayerProvider::class)->cacheIdentitySegment($probe);
    app(PrayerTimesCache::class)->putDaily(
        'aladhan',
        "ID:Asia/Jakarta:{$segment}:-6.20:106.80",
        '2026-10-18',
        prayerCacheDto('2026-10-18', sprintf('aladhan:%s/%s', config('prayer.methods.default.aladhan'), $methods['madhab'])),
    );

    $submitted = app(ResolvePrayerStartClockAction::class)->handle(
        'ID', '2026-10-18', 'Asia/Jakarta', EventPrayerTime::SelepasMaghrib, -6.2, 106.8,
    );

    expect($submitted)->not->toBeNull()
        ->and($submitted['zone'])->toBeNull();

    $expression = EventTimeExpression::factory()->create([
        'event_id' => $event->getKey(),
        'event_session_id' => $session->getKey(),
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 5,
        'metadata' => ['prayer' => [
            'source' => $submitted['source'],
            'fetched_at' => $submitted['fetched_at'],
            'country' => $submitted['country'],
            'prayer_date' => '2026-10-18',
            'lat' => $submitted['lat'],
            'lng' => $submitted['lng'],
            'institution_id' => (string) $organizer->getKey(),
        ]],
    ]);

    $resolved = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($resolved?->toIso8601String())->toBe($submitted['starts_at']);
    Http::assertNothingSent();
});

it('re-resolves with the persisted country when the current address country changed', function () {
    fakePrayerTimesApi();
    Bus::fake();
    config(['prayer.enabled' => true]);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto('2026-10-15')], 'MY');

    $institution = Institution::factory()->create(['status' => 'verified']);
    $address = syncPrimaryAddressForTest($institution, [
        'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'country_code' => 'MY',
        'city' => 'Kuala Lumpur',
        'state' => 'Wilayah Persekutuan',
    ]);

    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'published_at' => now(),
        'starts_at' => CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-15 14:00:00', 'UTC'),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $expression = EventTimeExpression::factory()->create([
        'event_id' => $event->getKey(),
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 30,
        'metadata' => ['prayer' => [
            'source' => 'jakim:v2/WLY01',
            'fetched_at' => now()->toIso8601String(),
            'country' => 'MY',
            'zone' => 'WLY01',
            'prayer_date' => '2026-10-15',
            'lat' => 3.139,
            'lng' => 101.6869,
        ]],
        'resolved_starts_at' => CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'),
        'resolved_at' => now(),
    ]);

    // The organizer address is later corrected to Indonesia: the pinned
    // submission identity (MY + WLY01 + KL coords) must still win over
    // the current address country.
    $indonesia = ensureTestAddressCountry(iso2: 'ID', name: 'Indonesia', iso3: 'IDN', timezones: ['Asia/Jakarta'], phoneCode: '62');
    $address->update(['country_id' => $indonesia->getKey(), 'country_code' => 'ID']);

    $resolved = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($resolved?->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-10-15 11:32:00');
    Http::assertNothingSent();
});

it('keeps the persisted instant when provenance lacks a coherent country', function () {
    fakePrayerTimesApi();
    Bus::fake();
    config(['prayer.enabled' => true]);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto('2026-10-15')], 'MY');

    $institution = Institution::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'country_code' => 'MY',
        'city' => 'Kuala Lumpur',
        'state' => 'Wilayah Persekutuan',
    ]);

    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'published_at' => now(),
        'starts_at' => CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-15 14:00:00', 'UTC'),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    // Old-shape provenance: no country, so no coherent original identity
    // can be reconstructed — the persisted instant stands even though
    // the warm cell would resolve 11:32.
    $expression = EventTimeExpression::factory()->create([
        'event_id' => $event->getKey(),
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 30,
        'metadata' => ['prayer' => [
            'source' => 'jakim:v2/WLY01',
            'fetched_at' => now()->toIso8601String(),
            'zone' => 'WLY01',
            'prayer_date' => '2026-10-15',
        ]],
        'resolved_starts_at' => CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'),
        'resolved_at' => now(),
    ]);

    $resolved = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($resolved?->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-10-15 12:00:00');
});
