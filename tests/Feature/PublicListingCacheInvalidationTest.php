<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Person;
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
        $keys[] = "events_persons_{$locale}_v2";
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
        'home.stats.persons.upcoming',
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
    $domainTag = submitEventTerm('domain');
    $disciplineTag = submitEventTerm('discipline');
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    $keys = primeMajlisListingCache();
    $homepageKeys = primeHomepageStatsCache();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        [
            'title' => 'Cache Bust Submit '.Str::random(6),
            'description' => 'Cache invalidation check',
            'event_date' => now()->addDays(6)->toDateString(),
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
            'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'languages' => [101],
            'primary_organizer_id' => $institution->id,
            'persons' => [$person->id],
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

    $keysAfterPersonPrime = primeMajlisListingCache();
    $homepageKeysAfterPersonPrime = primeHomepageStatsCache();
    Person::factory()->create(['status' => 'verified']);
    assertMajlisCacheWasCleared($keysAfterPersonPrime);
    assertHomepageStatsCacheWasCleared($homepageKeysAfterPersonPrime);

    $keysAfterTagPrime = primeMajlisListingCache();
    submitEventTerm('issue');
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
    $person = Person::factory()->create(['status' => 'verified']);

    $homepageKeysAfterCreate = primeHomepageStatsCache();
    $eventKeyPerson = EventKeyPerson::query()->create([
        'event_id' => $event->getKey(),
        'involveable_type' => 'person',
        'involveable_id' => $person->getKey(),
        'role_code' => EventKeyPersonRole::Speaker->value,
        'visibility' => 'public',
    ]);

    assertHomepageStatsCacheWasCleared($homepageKeysAfterCreate);

    $homepageKeysAfterDelete = primeHomepageStatsCache();
    $eventKeyPerson->delete();

    assertHomepageStatsCacheWasCleared($homepageKeysAfterDelete);
});

it('clears majlis listing cache when geography records are created updated or deleted', function () {
    $keysAfterCountryCreate = primeMajlisListingCache();
    $country = ensureTestAddressCountry(
        iso2: 'TL',
        name: 'Testland',
        iso3: 'TST',
        timezones: ['UTC'],
        phoneCode: '999',
    );
    assertMajlisCacheWasCleared($keysAfterCountryCreate);

    $keysAfterCountryUpdate = primeMajlisListingCache();
    $country->update(['name' => 'Updated Testland']);
    assertMajlisCacheWasCleared($keysAfterCountryUpdate);

    $keysAfterStateCreate = primeMajlisListingCache();
    $state = createTestAddressArea('Alpha State', 1, country: $country);
    assertMajlisCacheWasCleared($keysAfterStateCreate);

    $keysAfterStateUpdate = primeMajlisListingCache();
    $state->update(['name' => 'Updated Alpha State']);
    assertMajlisCacheWasCleared($keysAfterStateUpdate);

    $keysAfterDistrictCreate = primeMajlisListingCache();
    $district = createTestAddressArea('Alpha District', 2, parent: $state, country: $country);
    assertMajlisCacheWasCleared($keysAfterDistrictCreate);

    $keysAfterDistrictUpdate = primeMajlisListingCache();
    $district->update(['name' => 'Updated Alpha District']);
    assertMajlisCacheWasCleared($keysAfterDistrictUpdate);

    $keysAfterSubdistrictCreate = primeMajlisListingCache();
    $subdistrict = createTestAddressArea('Alpha Subdistrict', 3, parent: $district, country: $country);
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
