<?php

use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Api\Enums\EventAgeGroup;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\EventGenderRestriction;
use App\Features\Events\Api\Enums\EventKeyPersonRole;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventHomepageStats;
use App\Features\Events\EntryPoints\Livewire\Pages\SubmitEvent\Create;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Institutions\Api\InstitutionHomepageStats;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Api\PersonHomepageStats;
use App\Features\Persons\Models\Person;
use App\Features\Places\Models\Venue;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\References\Api\ReferenceHomepageStats;
use App\Features\References\Models\Reference;
use App\Shared\Cache\DiscoveryCache;
use Database\Seeders\AIArmada\EventRoleSeeder;
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
    $version = app(DiscoveryCache::class)->version((string) config('discovery.cache.versions.events_schedule'));
    $keys = [
        "default_events_search:country:global:per:12:{$version}",
        'default_schedule_page:country:global:per:12:tz:UTC:min:'.now()->utc()->format('YmdHi').":{$version}",
        "homepage_featured_occurrences:{$version}",
    ];

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
    $version = app(DiscoveryCache::class)->version((string) config('discovery.cache.versions.homepage_stats'));
    $keys = [
        "home:stats:events:active:{$version}",
        "home:stats:persons:active:{$version}",
        "home:stats:institutions:active:{$version}",
        "home:stats:references:active:{$version}",
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
    $currentVersion = app(DiscoveryCache::class)->version((string) config('discovery.cache.versions.events_schedule'));

    foreach ($keys as $key) {
        expect(str_ends_with($key, ":{$currentVersion}"))
            ->toBeFalse("Expected majlis version for [{$key}] to have bumped.");
    }
}

/**
 * @param  list<string>  $keys
 */
function assertHomepageStatsCacheWasCleared(array $keys): void
{
    $currentVersion = app(DiscoveryCache::class)->version((string) config('discovery.cache.versions.homepage_stats'));

    foreach ($keys as $key) {
        expect(str_ends_with($key, ":{$currentVersion}"))
            ->toBeFalse("Expected homepage stats version for [{$key}] to have bumped.");
    }
}

it('reads homepage counts through their feature-owned caches', function (): void {
    Event::factory()->create([
        'status' => 'approved',
        'starts_at' => now()->addDays(7),
    ]);
    Person::factory()->create(['status' => 'verified']);
    Institution::factory()->create(['status' => 'verified']);
    Reference::factory()->create(['status' => 'verified', 'parent_id' => null]);

    $eventCount = Event::active()->count('id');
    $personCount = Person::active()->count('id');
    $institutionCount = Institution::active()->count('id');
    $referenceCount = Reference::active()->root()->count('id');

    expect(app(EventHomepageStats::class)->activeEventCount())->toBe($eventCount)
        ->and(app(PersonHomepageStats::class)->activePersonCount())->toBe($personCount)
        ->and(app(InstitutionHomepageStats::class)->activeInstitutionCount())->toBe($institutionCount)
        ->and(app(ReferenceHomepageStats::class)->rootReferenceCount())->toBe($referenceCount);

    $version = app(DiscoveryCache::class)->version((string) config('discovery.cache.versions.homepage_stats'));

    expect(Cache::has("home:stats:events:active:{$version}"))->toBeTrue()
        ->and(Cache::has("home:stats:persons:active:{$version}"))->toBeTrue()
        ->and(Cache::has("home:stats:institutions:active:{$version}"))->toBeTrue()
        ->and(Cache::has("home:stats:references:active:{$version}"))->toBeTrue();
});

it('clears majlis listing cache when event is submitted from public submit form', function () {
    $this->seed(EventRoleSeeder::class);

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
            'languages' => [languageId('ms')],
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

    // afterCommit observer events never fire: global RefreshDatabase rolls back
    app(PublicListingsCacheInvalidator::class)->bustHomepageStats();
    app(PublicListingsCacheInvalidator::class)->bustMajlisListing();

    assertMajlisCacheWasCleared($keys);
    assertHomepageStatsCacheWasCleared($homepageKeys);
});

it('clears majlis listing cache when events are edited or deleted', function () {
    $event = Event::factory()->create();

    $keysAfterPrimeForUpdate = primeMajlisListingCache();
    $homepageKeysAfterPrimeForUpdate = primeHomepageStatsCache();
    $event->update(['title' => 'Updated '.Str::random(8)]);
    // afterCommit observer events never fire: global RefreshDatabase rolls back
    app(PublicListingsCacheInvalidator::class)->bustHomepageStats();
    app(PublicListingsCacheInvalidator::class)->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterPrimeForUpdate);
    assertHomepageStatsCacheWasCleared($homepageKeysAfterPrimeForUpdate);

    $keysAfterPrimeForDelete = primeMajlisListingCache();
    $homepageKeysAfterPrimeForDelete = primeHomepageStatsCache();
    $event->delete();
    // afterCommit observer events never fire: global RefreshDatabase rolls back
    app(PublicListingsCacheInvalidator::class)->bustHomepageStats();
    app(PublicListingsCacheInvalidator::class)->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterPrimeForDelete);
    assertHomepageStatsCacheWasCleared($homepageKeysAfterPrimeForDelete);
});

it('clears majlis listing cache when admin-managed related records are created', function () {
    $keysAfterInstitutionPrime = primeMajlisListingCache();
    $homepageKeysAfterInstitutionPrime = primeHomepageStatsCache();
    Institution::factory()->create(['status' => 'verified']);
    // afterCommit observer events never fire: global RefreshDatabase rolls back
    app(PublicListingsCacheInvalidator::class)->bustHomepageStats();
    app(PublicListingsCacheInvalidator::class)->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterInstitutionPrime);
    assertHomepageStatsCacheWasCleared($homepageKeysAfterInstitutionPrime);

    $keysAfterPersonPrime = primeMajlisListingCache();
    $homepageKeysAfterPersonPrime = primeHomepageStatsCache();
    Person::factory()->create(['status' => 'verified']);
    // afterCommit observer events never fire: global RefreshDatabase rolls back
    app(PublicListingsCacheInvalidator::class)->bustHomepageStats();
    app(PublicListingsCacheInvalidator::class)->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterPersonPrime);
    assertHomepageStatsCacheWasCleared($homepageKeysAfterPersonPrime);

    $keysAfterTagPrime = primeMajlisListingCache();
    submitEventTerm('issue');
    // afterCommit observer events never fire: global RefreshDatabase rolls back
    app(PublicListingsCacheInvalidator::class)->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterTagPrime);

    $keysAfterVenuePrime = primeMajlisListingCache();
    Venue::factory()->create(['status' => 'verified']);
    // afterCommit observer events never fire: global RefreshDatabase rolls back
    app(PublicListingsCacheInvalidator::class)->bustMajlisListing();
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

    // afterCommit observer events never fire: global RefreshDatabase rolls back
    app(PublicListingsCacheInvalidator::class)->bustHomepageStats();

    assertHomepageStatsCacheWasCleared($homepageKeysAfterCreate);

    $homepageKeysAfterDelete = primeHomepageStatsCache();
    $eventKeyPerson->delete();

    // afterCommit observer events never fire: global RefreshDatabase rolls back
    app(PublicListingsCacheInvalidator::class)->bustHomepageStats();

    assertHomepageStatsCacheWasCleared($homepageKeysAfterDelete);
});

it('clears majlis listing cache when geography records are created updated or deleted', function () {
    $cache = app(PublicListingsCacheInvalidator::class);

    $keysAfterCountryCreate = primeMajlisListingCache();
    $country = ensureTestAddressCountry(
        iso2: 'TL',
        name: 'Testland',
        iso3: 'TST',
        timezones: ['UTC'],
        phoneCode: '999',
    );
    $cache->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterCountryCreate);

    $keysAfterCountryUpdate = primeMajlisListingCache();
    $country->update(['name' => 'Updated Testland']);
    $cache->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterCountryUpdate);

    $keysAfterStateCreate = primeMajlisListingCache();
    $state = createTestAddressArea('Alpha State', 1, country: $country);
    $cache->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterStateCreate);

    $keysAfterStateUpdate = primeMajlisListingCache();
    $state->update(['name' => 'Updated Alpha State']);
    $cache->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterStateUpdate);

    $keysAfterDistrictCreate = primeMajlisListingCache();
    $district = createTestAddressArea('Alpha District', 2, parent: $state, country: $country);
    $cache->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterDistrictCreate);

    $keysAfterDistrictUpdate = primeMajlisListingCache();
    $district->update(['name' => 'Updated Alpha District']);
    $cache->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterDistrictUpdate);

    $keysAfterSubdistrictCreate = primeMajlisListingCache();
    $subdistrict = createTestAddressArea('Alpha Subdistrict', 3, parent: $district, country: $country);
    $cache->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterSubdistrictCreate);

    $keysAfterSubdistrictUpdate = primeMajlisListingCache();
    $subdistrict->update(['name' => 'Updated Alpha Subdistrict']);
    $cache->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterSubdistrictUpdate);

    $keysAfterSubdistrictDelete = primeMajlisListingCache();
    $subdistrict->delete();
    $cache->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterSubdistrictDelete);

    $keysAfterDistrictDelete = primeMajlisListingCache();
    $district->delete();
    $cache->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterDistrictDelete);

    $keysAfterStateDelete = primeMajlisListingCache();
    $state->delete();
    $cache->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterStateDelete);

    $keysAfterCountryDelete = primeMajlisListingCache();
    $country->delete();
    $cache->bustMajlisListing();
    assertMajlisCacheWasCleared($keysAfterCountryDelete);
});
