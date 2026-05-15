<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Models\Country;
use App\Models\District;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\Tag;
use App\Models\Venue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    app('cache')->setDefaultDriver('array');
    Cache::flush();

    fakePrayerTimesApi();
});

/**
 * @return list<string>
 */
function primeMajlisListingCache(): array
{
    $keys = [
        'default_events_search_v2',
    ];
    $supportedLocales = array_keys(config('app.supported_locales', []));

    if ($supportedLocales === []) {
        $supportedLocales = [config('app.locale', 'ms')];
    }

    foreach ($supportedLocales as $locale) {
        $keys[] = "events_institutions_{$locale}_v2";
        $keys[] = "events_speakers_{$locale}_v2";
        $keys[] = "events_disciplines_{$locale}_v2";
        $keys[] = "events_domains_{$locale}_v2";
        $keys[] = "events_sources_{$locale}_v2";
        $keys[] = "events_issues_{$locale}_v2";
        $keys[] = "events_references_{$locale}_v2";
        $keys[] = "events_venues_{$locale}_v2";
    }

    foreach ($keys as $key) {
        Cache::put($key, 'primed', now()->addMinutes(10));
    }

    return $keys;
}

/**
 * @return list<string>
 */
function primeHomepageStatsCache(): array
{
    $keys = [
        'home.stats.events.upcoming',
        'home.stats.speakers.upcoming',
        'home.stats.institutions.upcoming',
    ];

    foreach ($keys as $key) {
        Cache::put($key, 'primed', now()->addMinutes(10));
    }

    return $keys;
}

/**
 * @param  list<string>  $keys
 */
function assertMajlisCacheWasCleared(array $keys): void
{
    foreach ($keys as $key) {
        expect(Cache::has($key))
            ->toBeFalse("Expected cache key [{$key}] to be cleared.");
    }
}

/**
 * @param  list<string>  $keys
 */
function assertHomepageStatsCacheWasCleared(array $keys): void
{
    foreach ($keys as $key) {
        expect(Cache::has($key))
            ->toBeFalse("Expected cache key [{$key}] to be cleared.");
    }
}

it('clears majlis listing cache when event is submitted from public submit form', function () {
    $domainTag = Tag::factory()->domain()->create();
    $disciplineTag = Tag::factory()->discipline()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    $keys = primeMajlisListingCache();
    $homepageKeys = primeHomepageStatsCache();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        [
            'title' => 'Cache Bust Submit '.Str::random(6),
            'description' => 'Cache invalidation check',
            'event_date' => now()->addDays(6)->toDateString(),
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
            'event_type' => [EventType::KuliahCeramah->value],
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'languages' => [101],
            'organizer_type' => 'institution',
            'organizer_institution_id' => $institution->id,
            'speakers' => [$speaker->id],
            'domain_tags' => [$domainTag->id],
            'discipline_tags' => [$disciplineTag->id],
            'submitter_name' => 'Cache Tester',
            'submitter_email' => 'cache-tester@example.com',
        ],
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    assertMajlisCacheWasCleared($keys);
    assertHomepageStatsCacheWasCleared($homepageKeys);
});

it('clears majlis listing cache when events are edited or deleted', function () {
    $event = Event::factory()->create();

    $keysAfterPrimeForUpdate = primeMajlisListingCache();
    $homepageKeysAfterPrimeForUpdate = primeHomepageStatsCache();
    $event->update(['title' => 'Updated '.Str::random(8)]);
    assertMajlisCacheWasCleared($keysAfterPrimeForUpdate);
    assertHomepageStatsCacheWasCleared($homepageKeysAfterPrimeForUpdate);

    $keysAfterPrimeForDelete = primeMajlisListingCache();
    $homepageKeysAfterPrimeForDelete = primeHomepageStatsCache();
    $event->delete();
    assertMajlisCacheWasCleared($keysAfterPrimeForDelete);
    assertHomepageStatsCacheWasCleared($homepageKeysAfterPrimeForDelete);
});

it('clears majlis listing cache when admin-managed related records are created', function () {
    $keysAfterInstitutionPrime = primeMajlisListingCache();
    $homepageKeysAfterInstitutionPrime = primeHomepageStatsCache();
    Institution::factory()->create(['status' => 'verified']);
    assertMajlisCacheWasCleared($keysAfterInstitutionPrime);
    assertHomepageStatsCacheWasCleared($homepageKeysAfterInstitutionPrime);

    $keysAfterSpeakerPrime = primeMajlisListingCache();
    $homepageKeysAfterSpeakerPrime = primeHomepageStatsCache();
    Speaker::factory()->create(['status' => 'verified']);
    assertMajlisCacheWasCleared($keysAfterSpeakerPrime);
    assertHomepageStatsCacheWasCleared($homepageKeysAfterSpeakerPrime);

    $keysAfterTagPrime = primeMajlisListingCache();
    Tag::factory()->issue()->create();
    assertMajlisCacheWasCleared($keysAfterTagPrime);

    $keysAfterVenuePrime = primeMajlisListingCache();
    Venue::factory()->create(['status' => 'verified']);
    assertMajlisCacheWasCleared($keysAfterVenuePrime);
});

it('clears homepage stats cache when event key people are created or deleted', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'starts_at' => now()->addDays(7),
    ]);
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    $homepageKeysAfterCreate = primeHomepageStatsCache();
    $eventKeyPerson = EventKeyPerson::factory()->create([
        'event_id' => $event->getKey(),
        'speaker_id' => $speaker->getKey(),
        'role' => EventKeyPersonRole::Speaker,
        'is_public' => true,
    ]);

    assertHomepageStatsCacheWasCleared($homepageKeysAfterCreate);

    $homepageKeysAfterDelete = primeHomepageStatsCache();
    $eventKeyPerson->delete();

    assertHomepageStatsCacheWasCleared($homepageKeysAfterDelete);
});

it('clears majlis listing cache when geography records are created updated or deleted', function () {
    $country = new Country;
    $country->forceFill([
        'name' => 'Testland',
        'iso2' => 'TL',
        'iso3' => 'TST',
        'phone_code' => '999',
        'region' => 'Test Region',
        'subregion' => 'Test Subregion',
        'status' => 1,
    ]);

    $keysAfterCountryCreate = primeMajlisListingCache();
    $country->save();
    assertMajlisCacheWasCleared($keysAfterCountryCreate);

    $keysAfterCountryUpdate = primeMajlisListingCache();
    $country->forceFill(['name' => 'Updated Testland'])->save();
    assertMajlisCacheWasCleared($keysAfterCountryUpdate);

    $keysAfterStateCreate = primeMajlisListingCache();
    $state = State::query()->create([
        'country_id' => $country->getKey(),
        'name' => 'Alpha State',
        'country_code' => 'TL',
    ]);
    assertMajlisCacheWasCleared($keysAfterStateCreate);

    $keysAfterStateUpdate = primeMajlisListingCache();
    $state->update(['name' => 'Updated Alpha State']);
    assertMajlisCacheWasCleared($keysAfterStateUpdate);

    $keysAfterDistrictCreate = primeMajlisListingCache();
    $district = District::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'name' => 'Alpha District',
        'country_code' => 'TL',
    ]);
    assertMajlisCacheWasCleared($keysAfterDistrictCreate);

    $keysAfterDistrictUpdate = primeMajlisListingCache();
    $district->update(['name' => 'Updated Alpha District']);
    assertMajlisCacheWasCleared($keysAfterDistrictUpdate);

    $keysAfterSubdistrictCreate = primeMajlisListingCache();
    $subdistrict = Subdistrict::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'district_id' => $district->getKey(),
        'name' => 'Alpha Subdistrict',
        'country_code' => 'TL',
    ]);
    assertMajlisCacheWasCleared($keysAfterSubdistrictCreate);

    $keysAfterSubdistrictUpdate = primeMajlisListingCache();
    $subdistrict->update(['name' => 'Updated Alpha Subdistrict']);
    assertMajlisCacheWasCleared($keysAfterSubdistrictUpdate);

    $keysAfterSubdistrictDelete = primeMajlisListingCache();
    $subdistrict->delete();
    assertMajlisCacheWasCleared($keysAfterSubdistrictDelete);

    $keysAfterDistrictDelete = primeMajlisListingCache();
    $district->delete();
    assertMajlisCacheWasCleared($keysAfterDistrictDelete);

    $keysAfterStateDelete = primeMajlisListingCache();
    $state->delete();
    assertMajlisCacheWasCleared($keysAfterStateDelete);

    $keysAfterCountryDelete = primeMajlisListingCache();
    $country->delete();
    assertMajlisCacheWasCleared($keysAfterCountryDelete);
});
