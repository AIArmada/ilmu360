<?php

use App\Enums\EventFormat;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Livewire\Pages\Events\AdvancedFiltersPanel;
use App\Livewire\Pages\Events\Index;
use App\Models\Country;
use App\Models\District;
use App\Models\Event;
use App\Models\EventSettings;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Services\EventSearchService;
use App\Support\Location\PublicGeolocationPermission;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Nnjeim\World\Models\Language;

uses(RefreshDatabase::class);

function createVisibleEventForSearch(array $attributes = []): Event
{
    return Event::factory()->create(array_merge([
        'institution_id' => Institution::factory(),
        'venue_id' => null,
        'event_format' => EventFormat::Physical,
    ], $attributes));
}

function eventsIndexUrl(array|string|null $query = null): string
{
    if (is_array($query)) {
        return route('events.index', $query, false);
    }

    $url = route('events.index', [], false);

    if ($query === null) {
        return $url;
    }

    $query = ltrim($query, '?');

    if ($query === '') {
        return $url;
    }

    return $url.'?'.$query;
}

function eventShowUrl(Event $event): string
{
    return route('events.show', ['event' => $event->slug], false);
}

function eventRegistrationUrl(Event $event): string
{
    return route('events.register', ['event' => $event->slug], false);
}

function ensureMalaysiaStateForTests(string $name = 'Selangor'): State
{
    $country = Country::query()->find(132);

    if (! $country instanceof Country) {
        $country = new Country;
        $country->forceFill([
            'id' => 132,
            'iso2' => 'MY',
            'name' => 'Malaysia',
            'status' => 1,
            'phone_code' => '60',
            'iso3' => 'MYS',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);
        $country->save();
    }

    /** @var State $state */
    $state = State::query()->firstOrCreate(
        [
            'country_id' => 132,
            'name' => $name,
        ],
        [
            'country_code' => 'MY',
        ],
    );

    return $state;
}

function hiddenAttributeRegexForTestId(string $testId): string
{
    return '/data-testid="'.preg_quote($testId, '/').'"[^>]*\shidden(?:=|(?=[\s>]))/';
}

describe('Event Search Filters', function () {
    beforeEach(function () {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        // Set locale to English for tests
        app()->setLocale('en');
        // Get an actual state for filtering
        $this->state = ensureMalaysiaStateForTests();
    });

    it('displays the events index page', function () {
        Event::factory()->count(5)->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        $response = $this->get(eventsIndexUrl());

        $response->assertOk()
            ->assertSee('Circle of')
            ->assertSee('Penceramah & kandungan')
            ->assertDontSee('Advanced Filters')
            ->assertDontSee('/flux/flux.js', false)
            ->assertSee('/js/filament/schemas/schemas.js', false)
            ->assertSee('/js/filament/support/support.js', false)
            ->assertSee('/js/filament/notifications/notifications.js', false)
            ->assertSee('/js/filament/actions/actions.js', false)
            ->assertDontSee('/js/filament/tables/tables.js', false);
    });

    it('does not prime the global default events search cache when the implicit country filter is active', function () {
        config()->set('cache.default', 'array');
        app('cache')->setDefaultDriver('array');
        Cache::flush();

        Event::factory()->count(3)->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
        ]);

        Livewire::test(Index::class)
            ->assertSee('Circle of');

        expect(Cache::get('default_events_search_v2'))
            ->toBeNull();
    });

    it('shows search placeholder on events index', function () {
        $this->get(eventsIndexUrl())
            ->assertOk()
            ->assertSee('Cari tajuk, ustaz, masjid, topik...')
            ->assertSee('search_include_institutions')
            ->assertSee('search_include_speakers')
            ->assertSee('search_include_references');
    });

    it('renders secondary filters directly in the events index sidebar', function () {
        Livewire::test(Index::class)
            ->assertSee('Penceramah & kandungan')
            ->assertSee('Lokasi majlis')
            ->assertSee('Event URL')
            ->assertDontSee('Advanced Filters');
    });

    it('does not preload unrelated filter option labels into the initial events index response', function () {
        Speaker::factory()->create([
            'name' => 'Speaker Hidden Filter Payload Test',
            'status' => 'verified',
            'is_active' => true,
        ]);

        Institution::factory()->create([
            'name' => 'Institution Hidden Filter Payload Test',
            'status' => 'verified',
            'is_active' => true,
        ]);

        Venue::factory()->create([
            'name' => 'Venue Hidden Filter Payload Test',
            'status' => 'verified',
            'is_active' => true,
        ]);

        Reference::factory()->create([
            'title' => 'Reference Hidden Filter Payload Test',
            'is_active' => true,
        ]);

        Tag::factory()->discipline()->create([
            'name' => ['en' => 'Discipline Hidden Filter Payload Test', 'ms' => 'Discipline Hidden Filter Payload Test'],
            'status' => 'verified',
        ]);

        Tag::factory()->domain()->create([
            'name' => ['en' => 'Domain Hidden Filter Payload Test', 'ms' => 'Domain Hidden Filter Payload Test'],
            'status' => 'verified',
        ]);

        $this->get(eventsIndexUrl())
            ->assertOk()
            ->assertDontSee('Speaker Hidden Filter Payload Test')
            ->assertDontSee('Institution Hidden Filter Payload Test')
            ->assertDontSee('Venue Hidden Filter Payload Test')
            ->assertDontSee('Reference Hidden Filter Payload Test')
            ->assertDontSee('Discipline Hidden Filter Payload Test')
            ->assertDontSee('Domain Hidden Filter Payload Test');
    });

    it('shows save this search link for guests when search query is active', function () {
        $response = $this->get(eventsIndexUrl('search=halaqah'));

        $response->assertOk()
            ->assertSee('Save This Search')
            ->assertSee('/carian-tersimpan?search=halaqah', false);
    });

    it('shows a saved searches re-entry link for authenticated users without active filters', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(eventsIndexUrl());

        $response->assertOk()
            ->assertSee('Saved Searches')
            ->assertSee('Keep the filters you use often for quick access.')
            ->assertSee(route('saved-searches.index'), false);
    });

    it('keeps the nearby button visible while gating radius controls on geolocation permission', function () {
        $defaultResponse = $this->get(eventsIndexUrl([
            'lat' => '3.1390',
            'lng' => '101.6869',
        ]));

        $defaultResponse->assertOk();
        expect($defaultResponse->getContent())->not->toMatch(hiddenAttributeRegexForTestId('near-me-button'));
        expect($defaultResponse->getContent())->toMatch(hiddenAttributeRegexForTestId('nearby-radius-inline'));

        $grantedResponse = $this
            ->withUnencryptedCookie(PublicGeolocationPermission::COOKIE_NAME, '1')
            ->get(eventsIndexUrl([
                'lat' => '3.1390',
                'lng' => '101.6869',
            ]));

        $grantedResponse->assertOk();
        expect($grantedResponse->getContent())->not->toMatch(hiddenAttributeRegexForTestId('near-me-button'));
        expect($grantedResponse->getContent())->not->toMatch(hiddenAttributeRegexForTestId('nearby-radius-inline'));
    });

    it('hides the advanced nearby radius until geolocation permission is granted', function () {
        $defaultHtml = Livewire::test(AdvancedFiltersPanel::class, [
            'filters' => [
                'lat' => '3.1390',
                'lng' => '101.6869',
            ],
        ])->html();

        expect($defaultHtml)->toMatch(hiddenAttributeRegexForTestId('advanced-nearby-radius'));

        $grantedHtml = Livewire::withCookie(PublicGeolocationPermission::COOKIE_NAME, '1')
            ->test(AdvancedFiltersPanel::class, [
                'filters' => [
                    'lat' => '3.1390',
                    'lng' => '101.6869',
                ],
            ])->html();

        expect($grantedHtml)->not->toMatch(hiddenAttributeRegexForTestId('advanced-nearby-radius'));
    });

    it('sets default nearby radius to 15 km when location is detected', function () {
        Livewire::test(Index::class)
            ->call('setLocation', 3.0969303799671, 101.48910903397)
            ->assertSet('lat', '3.0969303799671')
            ->assertSet('lng', '101.48910903397')
            ->assertSet('radius_km', 15)
            ->assertSet('filterData.radius_km', 15)
            ->assertSet('sort', 'distance');
    });

    it('shows event location with subdistrict, district, and state on cards', function () {
        $state = ensureMalaysiaStateForTests();
        $district = District::query()->create([
            'country_id' => (int) $state->country_id,
            'state_id' => (int) $state->id,
            'country_code' => 'MY',
            'name' => 'Gombak',
        ]);
        $subdistrict = Subdistrict::query()->create([
            'country_id' => (int) $state->country_id,
            'state_id' => (int) $state->id,
            'district_id' => (int) $district->id,
            'country_code' => 'MY',
            'name' => 'Taman Melawati',
        ]);

        $venue = Venue::factory()->create([
            'name' => 'Surau Taman Melawati',
            'status' => 'verified',
            'is_active' => true,
        ]);

        $venue->addressModel?->update([
            'state_id' => (int) $state->id,
            'district_id' => (int) $district->id,
            'subdistrict_id' => (int) $subdistrict->id,
        ]);

        Event::factory()
            ->for($venue)
            ->create([
                'title' => 'Lokasi Hierarki Event',
                'status' => 'approved',
                'visibility' => 'public',
                'event_format' => EventFormat::Physical,
                'published_at' => now(),
                'starts_at' => now()->addDays(1),
            ]);

        $component = Livewire::test(Index::class);

        $component
            ->assertSee('Surau Taman Melawati')
            ->assertSee('Taman Melawati, Gombak, '.$state->name);
    });

    it('searches events by title', function () {
        createVisibleEventForSearch([
            'title' => 'Kuliah Maghrib Special',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        createVisibleEventForSearch([
            'title' => 'Ceramah Subuh',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $response = $this->get(eventsIndexUrl('search=Maghrib'));

        $response->assertOk()
            ->assertSee('Kuliah Maghrib Special')
            ->assertDontSee('Ceramah Subuh');
    });

    it('does not show parent programs on the public events index while still showing child events', function () {
        $institution = Institution::factory()->create([
            'name' => 'Masjid Hierarki',
            'status' => 'verified',
            'is_active' => true,
        ]);

        $parentEvent = Event::factory()->parentProgram()->for($institution)->create([
            'title' => 'Umbrella Program Hidden From Index',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
            'ends_at' => now()->addDays(5),
        ]);

        Event::factory()->childEvent($parentEvent)->for($institution)->create([
            'title' => 'Child Event Visible On Index',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $this->get(eventsIndexUrl())
            ->assertOk()
            ->assertSee('Child Event Visible On Index')
            ->assertDontSee('Umbrella Program Hidden From Index');
    });

    it('searches events by institution name when the institution name matches', function () {
        $matchInstitution = Institution::factory()->create([
            'name' => 'Pusat Tarbiah Al Hikmah',
            'status' => 'verified',
            'is_active' => true,
        ]);

        $otherInstitution = Institution::factory()->create([
            'name' => 'Kompleks Ilmu An Nur',
            'status' => 'verified',
            'is_active' => true,
        ]);

        Event::factory()->for($matchInstitution)->create([
            'title' => 'Kuliah Subuh Institusi A',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        Event::factory()->for($otherInstitution)->create([
            'title' => 'Kuliah Subuh Institusi B',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        $response = $this->get(eventsIndexUrl('search=Al%20Hikmah'));

        $response->assertOk()
            ->assertSee('Kuliah Subuh Institusi A')
            ->assertDontSee('Kuliah Subuh Institusi B');
    });

    it('searches events by speaker name when the speaker is attached', function () {
        $matchSpeaker = Speaker::factory()->create([
            'name' => 'Ustaz Samad Al-Bakri',
            'status' => 'verified',
            'is_active' => true,
        ]);

        $otherSpeaker = Speaker::factory()->create([
            'name' => 'Ustaz Ahmad Zain',
            'status' => 'verified',
            'is_active' => true,
        ]);

        $matchEvent = createVisibleEventForSearch([
            'title' => 'Kuliah Speaker A',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $matchEvent->speakers()->attach($matchSpeaker->id);

        $otherEvent = createVisibleEventForSearch([
            'title' => 'Kuliah Speaker B',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $otherEvent->speakers()->attach($otherSpeaker->id);

        $response = $this->get(eventsIndexUrl('search=Samad'));

        $response->assertOk()
            ->assertSee('Kuliah Speaker A')
            ->assertDontSee('Kuliah Speaker B');
    });

    it('searches events by free-text key person name when no linked speaker entity exists', function () {
        $matchEvent = createVisibleEventForSearch([
            'title' => 'Kuliah Usul Fiqh',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $matchEvent->keyPeople()->create([
            'name' => 'Ustaz Tarmizi Jamaluddin',
            'role' => EventKeyPersonRole::Speaker,
            'speaker_id' => null,
            'order_column' => 1,
        ]);

        $otherEvent = createVisibleEventForSearch([
            'title' => 'Kuliah Tauhid',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $otherEvent->keyPeople()->create([
            'name' => 'Ustaz Hafiz Rahim',
            'role' => EventKeyPersonRole::Speaker,
            'speaker_id' => null,
            'order_column' => 1,
        ]);

        $response = $this->get(eventsIndexUrl('search=Tarmizi'));

        $response->assertOk()
            ->assertSee('Kuliah Usul Fiqh')
            ->assertDontSee('Kuliah Tauhid');
    });

    it('searches events by reference title when the reference is attached', function () {
        $matchReference = Reference::factory()->create([
            'title' => 'Kitab Al Fiqh Al Islami',
            'status' => 'verified',
        ]);

        $otherReference = Reference::factory()->create([
            'title' => 'Syarah Matan Ghayah',
            'status' => 'verified',
        ]);

        $matchEvent = createVisibleEventForSearch([
            'title' => 'Halaqah Rujukan A',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $matchEvent->references()->attach($matchReference->id);

        $otherEvent = createVisibleEventForSearch([
            'title' => 'Halaqah Rujukan B',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $otherEvent->references()->attach($otherReference->id);

        $response = $this->get(eventsIndexUrl('search=Al%20Fiqh'));

        $response->assertOk()
            ->assertSee('Halaqah Rujukan A')
            ->assertDontSee('Halaqah Rujukan B');
    });

    it('searches events by reference author when the reference is attached', function () {
        $matchReference = Reference::factory()->create([
            'title' => 'Kitab Al Fiqh',
            'author' => 'Qudama AlMaqdisi Unique',
            'status' => 'verified',
        ]);

        $otherReference = Reference::factory()->create([
            'title' => 'Kitab Al Aqidah',
            'author' => 'Taymiyya AlHanbali Unique',
            'status' => 'verified',
        ]);

        $matchEvent = createVisibleEventForSearch([
            'title' => 'Halaqah Author A',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $matchEvent->references()->attach($matchReference->id);

        $otherEvent = createVisibleEventForSearch([
            'title' => 'Halaqah Author B',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $otherEvent->references()->attach($otherReference->id);

        $results = app(EventSearchService::class)->search(
            query: 'Qudama AlMaqdisi',
            filters: [],
            perPage: 20,
            sort: 'time',
        );

        expect(collect($results->items())->pluck('title')->all())
            ->toContain('Halaqah Author A')
            ->not->toContain('Halaqah Author B');
    });

    it('filters events by reference_author_search filter', function () {
        $matchReference = Reference::factory()->create([
            'title' => 'Risalah Tawhid',
            'author' => 'Muhammad Abduh',
            'status' => 'verified',
        ]);

        $otherReference = Reference::factory()->create([
            'title' => 'Al Bidaya Wal Nihaya',
            'author' => 'Ibn Kathir',
            'status' => 'verified',
        ]);

        $matchEvent = createVisibleEventForSearch([
            'title' => 'Kuliah Abduh Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $matchEvent->references()->attach($matchReference->id);

        $noMatchEvent = createVisibleEventForSearch([
            'title' => 'Kuliah Ibn Kathir No Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $noMatchEvent->references()->attach($otherReference->id);

        $results = app(EventSearchService::class)->search(
            query: null,
            filters: ['reference_author_search' => ['Muhammad Abduh']],
            perPage: 20,
            sort: 'time',
        );

        expect(collect($results->items())->pluck('title')->all())
            ->toContain('Kuliah Abduh Match')
            ->not->toContain('Kuliah Ibn Kathir No Match');
    });

    it('excludes institution name from search expansion when search_include_institutions is false', function () {
        $institution = Institution::factory()->create([
            'name' => 'Markaz Ilmu Sejahtera',
            'status' => 'verified',
        ]);

        $institutionEvent = createVisibleEventForSearch([
            'title' => 'Kuliah Unique Xqrz',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
            'institution_id' => $institution->id,
        ]);

        // With institution scope enabled (default), event surfaces via institution name.
        $withScope = app(EventSearchService::class)->search(
            query: 'Markaz Ilmu Sejahtera',
            filters: ['search_include_institutions' => true],
            perPage: 20,
            sort: 'time',
        );

        expect(collect($withScope->items())->pluck('title')->all())
            ->toContain('Kuliah Unique Xqrz');

        // With institution scope disabled, event must not appear.
        $withoutScope = app(EventSearchService::class)->search(
            query: 'Markaz Ilmu Sejahtera',
            filters: ['search_include_institutions' => false],
            perPage: 20,
            sort: 'time',
        );

        expect(collect($withoutScope->items())->pluck('title')->all())
            ->not->toContain('Kuliah Unique Xqrz');
    });

    it('excludes speaker name from search expansion when search_include_speakers is false', function () {
        $speaker = Speaker::factory()->create([
            'name' => 'Ustaz Zakaria Najib',
            'status' => 'verified',
        ]);

        $speakerEvent = createVisibleEventForSearch([
            'title' => 'Kuliah Speaker Scope Xqrz',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $speakerEvent->keyPeople()->create([
            'speaker_id' => $speaker->id,
            'name' => $speaker->name,
            'role' => EventKeyPersonRole::Speaker,
        ]);

        // With speaker scope enabled (default), event surfaces via speaker name.
        $withScope = app(EventSearchService::class)->search(
            query: 'Zakaria Najib',
            filters: ['search_include_speakers' => true],
            perPage: 20,
            sort: 'time',
        );

        expect(collect($withScope->items())->pluck('title')->all())
            ->toContain('Kuliah Speaker Scope Xqrz');

        // With speaker scope disabled, event must not appear.
        $withoutScope = app(EventSearchService::class)->search(
            query: 'Zakaria Najib',
            filters: ['search_include_speakers' => false],
            perPage: 20,
            sort: 'time',
        );

        expect(collect($withoutScope->items())->pluck('title')->all())
            ->not->toContain('Kuliah Speaker Scope Xqrz');
    });

    it('excludes reference from search expansion when search_include_references is false', function () {
        $reference = Reference::factory()->create([
            'title' => 'Tafsir Ibn Juzayy',
            'status' => 'verified',
        ]);

        $referenceEvent = createVisibleEventForSearch([
            'title' => 'Kuliah Reference Scope Xqrz',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $referenceEvent->references()->attach($reference->id);

        // With reference scope enabled (default), event surfaces via reference title.
        $withScope = app(EventSearchService::class)->search(
            query: 'Ibn Juzayy',
            filters: ['search_include_references' => true],
            perPage: 20,
            sort: 'time',
        );

        expect(collect($withScope->items())->pluck('title')->all())
            ->toContain('Kuliah Reference Scope Xqrz');

        // With reference scope disabled, event must not appear.
        $withoutScope = app(EventSearchService::class)->search(
            query: 'Ibn Juzayy',
            filters: ['search_include_references' => false],
            perPage: 20,
            sort: 'time',
        );

        expect(collect($withoutScope->items())->pluck('title')->all())
            ->not->toContain('Kuliah Reference Scope Xqrz');
    });

    it('does not search events by venue name when title does not match', function () {
        $matchVenue = Venue::factory()->create([
            'name' => 'Surau Taman Melawati',
            'status' => 'verified',
            'is_active' => true,
        ]);

        $otherVenue = Venue::factory()->create([
            'name' => 'Masjid Al Irsyad',
            'status' => 'verified',
            'is_active' => true,
        ]);

        Event::factory()->for($matchVenue)->create([
            'title' => 'Kuliah Lokasi A Xyznotmatch',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        Event::factory()->for($otherVenue)->create([
            'title' => 'Kuliah Lokasi B Xyznotmatch',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        $response = $this->get(eventsIndexUrl('search=Melawati'));

        $response->assertOk()
            ->assertDontSee('Kuliah Lokasi A Xyznotmatch')
            ->assertDontSee('Kuliah Lokasi B Xyznotmatch');
    });

    it('supports fuzzy search with minor title typos', function () {
        $matchVenue = Venue::factory()->create([
            'name' => 'Surau Taman Melawati',
            'status' => 'verified',
            'is_active' => true,
        ]);

        $otherVenue = Venue::factory()->create([
            'name' => 'Masjid Al Irsyad',
            'status' => 'verified',
            'is_active' => true,
        ]);

        Event::factory()->for($matchVenue)->create([
            'title' => 'Kuliah Maghrib Melawati',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        Event::factory()->for($otherVenue)->create([
            'title' => 'Kuliah Maghrib Irsyad',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        $response = $this->get(eventsIndexUrl('search=Melawti'));

        $response->assertOk()
            ->assertSee('Kuliah Maghrib Melawati')
            ->assertDontSee('Kuliah Maghrib Irsyad');
    });

    it('supports fuzzy search with adjacent transposition title typos', function () {
        config()->set('scout.driver', 'collection');

        createVisibleEventForSearch([
            'title' => 'Kuliah Ahmad',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
        ]);

        createVisibleEventForSearch([
            'title' => 'Kuliah Aziz',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
        ]);

        $events = app(EventSearchService::class)->search(
            query: 'Ahmda',
            filters: [],
            perPage: 20,
            sort: 'time',
        );

        expect(collect($events->items())->pluck('title')->all())
            ->toContain('Kuliah Ahmad')
            ->not->toContain('Kuliah Aziz');
    });

    it('keeps the best textual event title match inside the capped fuzzy candidate set', function () {
        config()->set('scout.driver', 'collection');

        foreach (range(1, 260) as $index) {
            createVisibleEventForSearch([
                'title' => "Samadx Alpha {$index}",
                'status' => 'approved',
                'visibility' => 'public',
                'published_at' => now(),
                'starts_at' => now()->addMinutes($index),
            ]);
        }

        createVisibleEventForSearch([
            'title' => 'Samadx',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addYear(),
        ]);

        $events = app(EventSearchService::class)->search(
            query: 'Samdax',
            filters: [],
            perPage: 20,
            sort: 'time',
        );

        expect(collect($events->items())->pluck('title')->all())
            ->toContain('Samadx');
    });

    it('updates event results live when search changes', function () {
        $matchVenue = Venue::factory()->create([
            'name' => 'Surau Taman Melawati',
            'status' => 'verified',
            'is_active' => true,
        ]);

        $otherVenue = Venue::factory()->create([
            'name' => 'Masjid Al Irsyad',
            'status' => 'verified',
            'is_active' => true,
        ]);

        Event::factory()->for($matchVenue)->create([
            'title' => 'Live Search Melawati',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        Event::factory()->for($otherVenue)->create([
            'title' => 'Live Search Irsyad',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        $component = Livewire::test(Index::class)
            ->set('search', 'Melawti')
            ->assertSet('search', 'Melawti');

        $eventTitles = $component->instance()
            ->events
            ->getCollection()
            ->pluck('title')
            ->all();

        expect($eventTitles)
            ->toContain('Live Search Melawati')
            ->not->toContain('Live Search Irsyad');
    });

    it('filters events by prayer_time enum value in advanced filters', function () {
        createVisibleEventForSearch([
            'title' => 'Enum Filter Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
            'timing_mode' => TimingMode::PrayerRelative,
            'prayer_reference' => PrayerReference::Maghrib,
            'prayer_display_text' => 'Selepas Maghrib',
        ]);

        createVisibleEventForSearch([
            'title' => 'Enum Filter No Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
            'timing_mode' => TimingMode::PrayerRelative,
            'prayer_reference' => PrayerReference::Asr,
            'prayer_display_text' => 'Selepas Asar',
        ]);

        $response = $this->get(eventsIndexUrl([
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        ]));

        $response->assertOk()
            ->assertSee('Enum Filter Match')
            ->assertDontSee('Enum Filter No Match');
    });

    it('filters events by institution in advanced filters', function () {
        $includedInstitution = Institution::factory()->create(['status' => 'verified', 'is_active' => true]);
        $excludedInstitution = Institution::factory()->create(['status' => 'verified', 'is_active' => true]);

        Event::factory()->for($includedInstitution)->create([
            'title' => 'Institution Match Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        Event::factory()->for($excludedInstitution)->create([
            'title' => 'Institution Excluded Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $response = $this->get(eventsIndexUrl([
            'institution_id' => $includedInstitution->id,
        ]));

        $response->assertOk()
            ->assertSee('Institution Match Event')
            ->assertDontSee('Institution Excluded Event');
    });

    it('filters events by venue in advanced filters', function () {
        $includedVenue = Venue::factory()->create(['status' => 'verified', 'is_active' => true]);
        $excludedVenue = Venue::factory()->create(['status' => 'verified', 'is_active' => true]);

        Event::factory()->for($includedVenue)->create([
            'title' => 'Venue Match Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        Event::factory()->for($excludedVenue)->create([
            'title' => 'Venue Excluded Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $response = $this->get(eventsIndexUrl([
            'venue_id' => $includedVenue->id,
        ]));

        $response->assertOk()
            ->assertSee('Venue Match Event')
            ->assertDontSee('Venue Excluded Event');
    });

    it('filters events by selected speaker ids in advanced filters', function () {
        $includedSpeaker = Speaker::factory()->create(['status' => 'verified', 'is_active' => true]);
        $excludedSpeaker = Speaker::factory()->create(['status' => 'verified', 'is_active' => true]);

        $includedEvent = createVisibleEventForSearch([
            'title' => 'Speaker Match Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);
        $includedEvent->speakers()->attach($includedSpeaker->id);

        $excludedEvent = createVisibleEventForSearch([
            'title' => 'Speaker Excluded Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);
        $excludedEvent->speakers()->attach($excludedSpeaker->id);

        $query = http_build_query([
            'speaker_ids' => [$includedSpeaker->id],
        ]);

        $response = $this->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('Speaker Match Event')
            ->assertDontSee('Speaker Excluded Event');
    });

    it('filters events by a single language_codes value', function () {
        $malay = Language::where('code', 'ms')->first() ?? Language::query()->create(['code' => 'ms', 'name' => 'Malay', 'name_native' => 'Bahasa Melayu', 'dir' => 'ltr']);
        $english = Language::where('code', 'en')->first() ?? Language::query()->create(['code' => 'en', 'name' => 'English', 'name_native' => 'English', 'dir' => 'ltr']);

        $event1 = createVisibleEventForSearch([
            'title' => 'Malay Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $event1->languages()->attach($malay);

        $event2 = createVisibleEventForSearch([
            'title' => 'English Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);
        $event2->languages()->attach($english);

        $query = http_build_query([
            'language_codes' => ['en'],
        ]);

        $response = $this->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('English Event')
            ->assertDontSee('Malay Event');
    });

    it('filters events by language_codes array filter', function () {
        $malay = Language::where('code', 'ms')->first() ?? Language::query()->create(['code' => 'ms', 'name' => 'Malay', 'name_native' => 'Bahasa Melayu', 'dir' => 'ltr']);
        $english = Language::where('code', 'en')->first() ?? Language::query()->create(['code' => 'en', 'name' => 'English', 'name_native' => 'English', 'dir' => 'ltr']);

        $englishEvent = createVisibleEventForSearch([
            'title' => 'English Language Codes Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $englishEvent->languages()->sync([$english->id]);

        $malayEvent = createVisibleEventForSearch([
            'title' => 'Malay Language Codes Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);
        $malayEvent->languages()->sync([$malay->id]);

        $query = http_build_query([
            'language_codes' => ['en'],
        ]);

        $response = $this->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('English Language Codes Event')
            ->assertDontSee('Malay Language Codes Event');
    });

    it('filters events by event_format array filter', function () {
        createVisibleEventForSearch([
            'title' => 'Online Format Event',
            'status' => 'approved',
            'visibility' => 'public',
            'event_format' => EventFormat::Online,
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        createVisibleEventForSearch([
            'title' => 'Physical Format Event',
            'status' => 'approved',
            'visibility' => 'public',
            'event_format' => EventFormat::Physical,
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        $query = http_build_query([
            'event_format' => [EventFormat::Online->value],
        ]);

        $response = $this->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('Online Format Event')
            ->assertDontSee('Physical Format Event');
    });

    it('filters events by is_muslim_only toggle', function () {
        $institution = Institution::factory()->create();

        Event::factory()->create([
            'institution_id' => $institution->getKey(),
            'venue_id' => null,
            'title' => 'Muslim Only Event',
            'status' => 'approved',
            'visibility' => 'public',
            'event_format' => EventFormat::Physical,
            'is_muslim_only' => true,
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        Event::factory()->create([
            'institution_id' => $institution->getKey(),
            'venue_id' => null,
            'title' => 'Open Event',
            'status' => 'approved',
            'visibility' => 'public',
            'event_format' => EventFormat::Physical,
            'is_muslim_only' => false,
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        $response = $this->get(eventsIndexUrl('is_muslim_only=1'));

        $response->assertOk()
            ->assertSee('Muslim Only Event')
            ->assertDontSee('Open Event');
    });

    it('filters events by link presence and timing mode', function () {
        createVisibleEventForSearch([
            'title' => 'Absolute With Links Event',
            'status' => 'approved',
            'visibility' => 'public',
            'timing_mode' => TimingMode::Absolute,
            'event_url' => 'https://example.com/event',
            'live_url' => 'https://youtube.com/live/test',
            'ends_at' => now()->addDays(2),
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        createVisibleEventForSearch([
            'title' => 'Prayer Relative Without Links Event',
            'status' => 'approved',
            'visibility' => 'public',
            'timing_mode' => TimingMode::PrayerRelative,
            'prayer_display_text' => 'Selepas Maghrib',
            'event_url' => null,
            'live_url' => null,
            'ends_at' => null,
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        $query = http_build_query([
            'timing_mode' => TimingMode::Absolute->value,
            'has_event_url' => 1,
            'has_live_url' => 1,
            'has_end_time' => 1,
        ]);

        $response = $this->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('Absolute With Links Event')
            ->assertDontSee('Prayer Relative Without Links Event');
    });

    it('filters absolute timing events by selected start time range', function () {
        createVisibleEventForSearch([
            'title' => 'Evening Absolute Event',
            'status' => 'approved',
            'visibility' => 'public',
            'timing_mode' => TimingMode::Absolute,
            'published_at' => now(),
            'starts_at' => now('UTC')->addDays(2)->setTime(20, 0),
            'ends_at' => null,
        ]);

        createVisibleEventForSearch([
            'title' => 'Morning Absolute Event',
            'status' => 'approved',
            'visibility' => 'public',
            'timing_mode' => TimingMode::Absolute,
            'published_at' => now(),
            'starts_at' => now('UTC')->addDays(2)->setTime(9, 0),
            'ends_at' => null,
        ]);

        createVisibleEventForSearch([
            'title' => 'Evening Prayer Event',
            'status' => 'approved',
            'visibility' => 'public',
            'timing_mode' => TimingMode::PrayerRelative,
            'prayer_display_text' => 'Selepas Maghrib',
            'published_at' => now(),
            'starts_at' => now('UTC')->addDays(2)->setTime(20, 0),
            'ends_at' => null,
        ]);

        $query = http_build_query([
            'timing_mode' => TimingMode::Absolute->value,
            'starts_time_from' => '19:00',
            'starts_time_until' => '21:00',
        ]);

        $response = $this
            ->withCookie('user_timezone', 'UTC')
            ->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('Evening Absolute Event')
            ->assertDontSee('Morning Absolute Event')
            ->assertDontSee('Evening Prayer Event');
    });

    it('applies absolute time range to event start time only, not event end time', function () {
        createVisibleEventForSearch([
            'title' => 'Start In Range Event',
            'status' => 'approved',
            'visibility' => 'public',
            'timing_mode' => TimingMode::Absolute,
            'published_at' => now(),
            'starts_at' => now('UTC')->addDays(2)->setTime(20, 0),
            'ends_at' => now('UTC')->addDays(2)->setTime(22, 30),
        ]);

        createVisibleEventForSearch([
            'title' => 'Start Out Of Range But Ends In Range Event',
            'status' => 'approved',
            'visibility' => 'public',
            'timing_mode' => TimingMode::Absolute,
            'published_at' => now(),
            'starts_at' => now('UTC')->addDays(2)->setTime(18, 0),
            'ends_at' => now('UTC')->addDays(2)->setTime(20, 30),
        ]);

        $query = http_build_query([
            'timing_mode' => TimingMode::Absolute->value,
            'starts_time_from' => '19:00',
            'starts_time_until' => '21:00',
        ]);

        $response = $this
            ->withCookie('user_timezone', 'UTC')
            ->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('Start In Range Event')
            ->assertDontSee('Start Out Of Range But Ends In Range Event');
    });

    it('filters events by district', function () {
        $state = ensureMalaysiaStateForTests();

        $districtA = District::query()->create([
            'country_id' => $state->country_id,
            'state_id' => $state->id,
            'country_code' => 'MY',
            'name' => 'District A '.uniqid(),
        ]);

        $districtB = District::query()->create([
            'country_id' => $state->country_id,
            'state_id' => $state->id,
            'country_code' => 'MY',
            'name' => 'District B '.uniqid(),
        ]);

        $subdistrictA = Subdistrict::query()->create([
            'country_id' => $state->country_id,
            'state_id' => $state->id,
            'district_id' => $districtA->id,
            'country_code' => 'MY',
            'name' => 'Subdistrict A '.uniqid(),
        ]);

        $subdistrictB = Subdistrict::query()->create([
            'country_id' => $state->country_id,
            'state_id' => $state->id,
            'district_id' => $districtB->id,
            'country_code' => 'MY',
            'name' => 'Subdistrict B '.uniqid(),
        ]);

        $venueA = Venue::factory()->create();
        $venueA->address()->update([
            'state_id' => $state->id,
            'district_id' => $districtA->id,
            'subdistrict_id' => $subdistrictA->id,
        ]);

        $venueB = Venue::factory()->create();
        $venueB->address()->update([
            'state_id' => $state->id,
            'district_id' => $districtB->id,
            'subdistrict_id' => $subdistrictB->id,
        ]);

        Event::factory()->for($venueA)->create([
            'title' => 'District Filter Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        Event::factory()->for($venueB)->create([
            'title' => 'District Filter Non Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $response = $this->get(eventsIndexUrl([
            'district_id' => $districtA->id,
        ]));

        $response->assertOk()
            ->assertSee('District Filter Match')
            ->assertDontSee('District Filter Non Match');
    });

    it('filters events by country', function () {
        $malaysiaId = DB::table('countries')->where('id', 132)->value('id');

        if (! $malaysiaId) {
            $malaysiaId = DB::table('countries')->insertGetId([
                'id' => 132,
                'iso2' => 'MY',
                'name' => 'Malaysia',
                'status' => 1,
                'phone_code' => '60',
                'iso3' => 'MYS',
                'region' => 'Asia',
                'subregion' => 'South-Eastern Asia',
            ]);
        }

        $indonesiaId = DB::table('countries')->insertGetId([
            'iso2' => 'ID',
            'name' => 'Indonesia',
            'status' => 1,
            'phone_code' => '62',
            'iso3' => 'IDN',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);

        $malaysiaStateId = DB::table('states')->insertGetId([
            'country_id' => $malaysiaId,
            'name' => 'Selangor',
            'country_code' => 'MY',
        ]);

        $indonesiaStateId = DB::table('states')->insertGetId([
            'country_id' => $indonesiaId,
            'name' => 'DKI Jakarta',
            'country_code' => 'ID',
        ]);

        $malaysiaVenue = Venue::factory()->create();
        $malaysiaVenue->address()->update([
            'country_id' => (int) $malaysiaId,
            'state_id' => (int) $malaysiaStateId,
            'district_id' => null,
            'subdistrict_id' => null,
        ]);

        $malaysiaInstitution = Institution::factory()->create([
            'status' => 'verified',
            'is_active' => true,
        ]);
        $malaysiaInstitution->address()->update([
            'country_id' => (int) $malaysiaId,
            'state_id' => (int) $malaysiaStateId,
            'district_id' => null,
            'subdistrict_id' => null,
        ]);

        $indonesiaVenue = Venue::factory()->create();
        $indonesiaVenue->address()->update([
            'country_id' => (int) $indonesiaId,
            'state_id' => (int) $indonesiaStateId,
            'district_id' => null,
            'subdistrict_id' => null,
        ]);

        $indonesiaInstitution = Institution::factory()->create([
            'status' => 'verified',
            'is_active' => true,
        ]);
        $indonesiaInstitution->address()->update([
            'country_id' => (int) $indonesiaId,
            'state_id' => (int) $indonesiaStateId,
            'district_id' => null,
            'subdistrict_id' => null,
        ]);

        Event::factory()->for($malaysiaVenue)->for($malaysiaInstitution)->create([
            'title' => 'Malaysia Country Filter Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        Event::factory()->for($indonesiaVenue)->for($indonesiaInstitution)->create([
            'title' => 'Indonesia Country Filter Non Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $component = Livewire::withQueryParams([
            'country_id' => (string) $malaysiaId,
        ])->test(Index::class);

        $eventTitles = $component->instance()
            ->events
            ->getCollection()
            ->pluck('title')
            ->all();

        expect($eventTitles)
            ->toContain('Malaysia Country Filter Match')
            ->not->toContain('Indonesia Country Filter Non Match');
    });

    it('defaults the majlis country filter from an unencrypted browser timezone cookie', function () {
        $malaysiaId = DB::table('countries')->where('id', 132)->value('id');

        if (! $malaysiaId) {
            $malaysiaId = DB::table('countries')->insertGetId([
                'id' => 132,
                'iso2' => 'MY',
                'name' => 'Malaysia',
                'status' => 1,
                'phone_code' => '60',
                'iso3' => 'MYS',
                'region' => 'Asia',
                'subregion' => 'South-Eastern Asia',
            ]);
        }

        Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        Livewire::withCookie('user_timezone', 'Asia/Jakarta')
            ->test(Index::class)
            ->assertSet('country_id', (string) 132)
            ->assertSet('state_id', null);

        Livewire::test(AdvancedFiltersPanel::class)
            ->assertDontSee('Country');
    });

    it('filters events by subdistrict', function () {
        $state = ensureMalaysiaStateForTests();

        $district = District::query()->create([
            'country_id' => $state->country_id,
            'state_id' => $state->id,
            'country_code' => 'MY',
            'name' => 'District C '.uniqid(),
        ]);

        $subdistrictA = Subdistrict::query()->create([
            'country_id' => $state->country_id,
            'state_id' => $state->id,
            'district_id' => $district->id,
            'country_code' => 'MY',
            'name' => 'Subdistrict C1 '.uniqid(),
        ]);

        $subdistrictB = Subdistrict::query()->create([
            'country_id' => $state->country_id,
            'state_id' => $state->id,
            'district_id' => $district->id,
            'country_code' => 'MY',
            'name' => 'Subdistrict C2 '.uniqid(),
        ]);

        $venueA = Venue::factory()->create();
        $venueA->address()->update([
            'state_id' => $state->id,
            'district_id' => $district->id,
            'subdistrict_id' => $subdistrictA->id,
        ]);

        $venueB = Venue::factory()->create();
        $venueB->address()->update([
            'state_id' => $state->id,
            'district_id' => $district->id,
            'subdistrict_id' => $subdistrictB->id,
        ]);

        Event::factory()->for($venueA)->create([
            'title' => 'Subdistrict Filter Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        Event::factory()->for($venueB)->create([
            'title' => 'Subdistrict Filter Non Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $response = $this->get(eventsIndexUrl([
            'subdistrict_id' => $subdistrictA->id,
        ]));

        $response->assertOk()
            ->assertSee('Subdistrict Filter Match')
            ->assertDontSee('Subdistrict Filter Non Match');
    });

    it('filters events by federal territory subdistricts without requiring a district', function () {
        $state = State::query()->create([
            'country_id' => 132,
            'name' => 'Kuala Lumpur',
            'country_code' => 'MY',
        ]);

        $subdistrictA = Subdistrict::query()->create([
            'country_id' => 132,
            'state_id' => (int) $state->id,
            'district_id' => null,
            'country_code' => 'MY',
            'name' => 'Setiawangsa '.uniqid(),
        ]);

        $subdistrictB = Subdistrict::query()->create([
            'country_id' => 132,
            'state_id' => (int) $state->id,
            'district_id' => null,
            'country_code' => 'MY',
            'name' => 'Segambut '.uniqid(),
        ]);

        $venueA = Venue::factory()->create();
        $venueA->address()->update([
            'state_id' => (int) $state->id,
            'district_id' => null,
            'subdistrict_id' => (int) $subdistrictA->id,
        ]);

        $venueB = Venue::factory()->create();
        $venueB->address()->update([
            'state_id' => (int) $state->id,
            'district_id' => null,
            'subdistrict_id' => (int) $subdistrictB->id,
        ]);

        Event::factory()->for($venueA)->create([
            'title' => 'Federal Territory Subdistrict Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        Event::factory()->for($venueB)->create([
            'title' => 'Federal Territory Subdistrict Non Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $response = $this->get(eventsIndexUrl([
            'state_id' => $state->id,
            'subdistrict_id' => $subdistrictA->id,
        ]));

        $response->assertOk()
            ->assertSee('Federal Territory Subdistrict Match')
            ->assertDontSee('Federal Territory Subdistrict Non Match');
    });

    it('filters events by event type', function () {
        createVisibleEventForSearch([
            'title' => 'Kuliah Event',
            'event_type' => [EventType::KuliahCeramah],
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        createVisibleEventForSearch([
            'title' => 'Forum Event',
            'event_type' => [EventType::Forum],
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $response = $this->get(eventsIndexUrl('event_type=forum'));

        $response->assertOk()
            ->assertSee('Forum Event')
            ->assertDontSee('Kuliah Event');
    });

    it('filters events by selected bidang ilmu', function () {
        $tafsirTag = Tag::factory()->discipline()->create([
            'name' => ['en' => 'Tafsir', 'ms' => 'Tafsir'],
        ]);

        $fiqhTag = Tag::factory()->discipline()->create([
            'name' => ['en' => 'Fiqh', 'ms' => 'Fiqh'],
        ]);

        $tafsirEvent = createVisibleEventForSearch([
            'title' => 'Tafsir Session',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $tafsirEvent->attachTag($tafsirTag);

        $fiqhEvent = createVisibleEventForSearch([
            'title' => 'Fiqh Session',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);
        $fiqhEvent->attachTag($fiqhTag);

        $query = http_build_query(['topic_ids' => [$tafsirTag->id]]);

        $response = $this->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('Tafsir Session')
            ->assertDontSee('Fiqh Session')
            ->assertSee('Tafsir');
    });

    it('filters events by PIC linked profile and free-text name', function () {
        $linkedPic = Speaker::factory()->create([
            'name' => 'Ustaz Linked PIC',
            'status' => 'verified',
            'is_active' => true,
        ]);

        $linkedPicEvent = createVisibleEventForSearch([
            'title' => 'Linked PIC Majlis',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
        ]);
        $linkedPicEvent->keyPeople()->create([
            'role' => EventKeyPersonRole::PersonInCharge,
            'speaker_id' => $linkedPic->id,
            'order_column' => 1,
            'is_public' => true,
        ]);

        $freeTextPicEvent = createVisibleEventForSearch([
            'title' => 'Free Text PIC Majlis',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);
        $freeTextPicEvent->keyPeople()->create([
            'role' => EventKeyPersonRole::PersonInCharge,
            'name' => 'Encik Free Text Penyelaras',
            'order_column' => 1,
            'is_public' => true,
        ]);

        $otherEvent = createVisibleEventForSearch([
            'title' => 'Other Majlis',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(3),
        ]);
        $otherEvent->keyPeople()->create([
            'role' => EventKeyPersonRole::Moderator,
            'name' => 'Encik Free Text Penyelaras',
            'order_column' => 1,
            'is_public' => true,
        ]);

        $linkedProfileQuery = http_build_query(['person_in_charge_ids' => [$linkedPic->id]]);

        $this->get(eventsIndexUrl($linkedProfileQuery))
            ->assertOk()
            ->assertSee('Linked PIC Majlis')
            ->assertSee('PIC / Coordinator: Ustaz Linked PIC')
            ->assertDontSee('Free Text PIC Majlis')
            ->assertDontSee('Other Majlis');

        $freeTextQuery = http_build_query(['person_in_charge_search' => 'Free Text']);

        $this->get(eventsIndexUrl($freeTextQuery))
            ->assertOk()
            ->assertSee('Free Text PIC Majlis')
            ->assertSee('PIC / Coordinator Name: Free Text')
            ->assertDontSee('Linked PIC Majlis')
            ->assertDontSee('Other Majlis');
    });

    it('filters events by selected kategori (domain tags)', function () {
        $aqidahTag = Tag::factory()->domain()->create([
            'name' => ['en' => 'Aqidah', 'ms' => 'Aqidah'],
        ]);

        $akhlakTag = Tag::factory()->domain()->create([
            'name' => ['en' => 'Akhlak', 'ms' => 'Akhlak'],
        ]);

        $aqidahEvent = createVisibleEventForSearch([
            'title' => 'Aqidah Intensive',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $aqidahEvent->attachTag($aqidahTag);

        $akhlakEvent = createVisibleEventForSearch([
            'title' => 'Akhlak Session',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);
        $akhlakEvent->attachTag($akhlakTag);

        $query = http_build_query(['domain_tag_ids' => [$aqidahTag->id]]);

        $response = $this->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('Aqidah Intensive')
            ->assertDontSee('Akhlak Session')
            ->assertSee('Aqidah');
    });

    it('filters events by selected sumber rujukan utama tags', function () {
        $quranTag = Tag::factory()->source()->create([
            'name' => ['en' => 'Quran', 'ms' => 'Quran'],
        ]);

        $hadithTag = Tag::factory()->source()->create([
            'name' => ['en' => 'Hadith', 'ms' => 'Hadith'],
        ]);

        $quranEvent = createVisibleEventForSearch([
            'title' => 'Quran Study Circle',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $quranEvent->attachTag($quranTag);

        $hadithEvent = createVisibleEventForSearch([
            'title' => 'Hadith Workshop',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);
        $hadithEvent->attachTag($hadithTag);

        $query = http_build_query(['source_tag_ids' => [$quranTag->id]]);

        $response = $this->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('Quran Study Circle')
            ->assertDontSee('Hadith Workshop');
    });

    it('filters events by selected tema isu tags', function () {
        $familyTag = Tag::factory()->issue()->create([
            'name' => ['en' => 'Keluarga', 'ms' => 'Keluarga'],
        ]);

        $economyTag = Tag::factory()->issue()->create([
            'name' => ['en' => 'Ekonomi', 'ms' => 'Ekonomi'],
        ]);

        $familyEvent = createVisibleEventForSearch([
            'title' => 'Isu Keluarga Semasa',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $familyEvent->attachTag($familyTag);

        $economyEvent = createVisibleEventForSearch([
            'title' => 'Perbincangan Isu Ekonomi',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);
        $economyEvent->attachTag($economyTag);

        $query = http_build_query(['issue_tag_ids' => [$familyTag->id]]);

        $response = $this->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('Isu Keluarga Semasa')
            ->assertDontSee('Perbincangan Isu Ekonomi');
    });

    it('filters events by selected rujukan kitab buku', function () {
        $riyadhRef = Reference::factory()->create([
            'title' => 'Riyadhus Solihin',
            'is_active' => true,
        ]);

        $bulughRef = Reference::factory()->create([
            'title' => 'Bulughul Maram',
            'is_active' => true,
        ]);

        $riyadhEvent = createVisibleEventForSearch([
            'title' => 'Riyadh Session',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);
        $riyadhEvent->references()->attach($riyadhRef->id);

        $bulughEvent = createVisibleEventForSearch([
            'title' => 'Bulugh Session',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);
        $bulughEvent->references()->attach($bulughRef->id);

        $query = http_build_query(['reference_ids' => [$riyadhRef->id]]);

        $response = $this->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('Riyadh Session')
            ->assertDontSee('Bulugh Session');
    });

    it('shows approved, pending, and cancelled public events', function () {
        createVisibleEventForSearch([
            'title' => 'Approved Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        createVisibleEventForSearch([
            'title' => 'Pending Event',
            'status' => 'pending',
            'visibility' => 'public',
            'starts_at' => now()->addDays(2),
        ]);

        createVisibleEventForSearch([
            'title' => 'Cancelled Event',
            'status' => 'cancelled',
            'visibility' => 'public',
            'starts_at' => now()->addDays(2),
        ]);

        createVisibleEventForSearch([
            'title' => 'Draft Event',
            'status' => 'draft',
            'visibility' => 'public',
            'starts_at' => now()->addDays(3),
        ]);

        createVisibleEventForSearch([
            'title' => 'Private Event',
            'status' => 'approved',
            'visibility' => 'private',
            'published_at' => now(),
            'starts_at' => now()->addDays(4),
        ]);

        $response = $this->get(eventsIndexUrl());

        $response->assertOk()
            ->assertSee('Approved Event')
            ->assertSee('Pending Event')
            ->assertSee('Cancelled Event')
            ->assertSee('Pending Approval')
            ->assertSee('Dibatalkan')
            ->assertSee('Semak lencana status pada setiap majlis sebelum hadir.')
            ->assertDontSee('Draft Event')
            ->assertDontSee('Private Event');
    });

    it('paginates results', function () {
        foreach (range(1, 13) as $index) {
            Event::factory()->create([
                'institution_id' => Institution::factory(),
                'venue_id' => null,
                'event_format' => EventFormat::Physical,
                'title' => 'Event '.$index,
                'status' => 'approved',
                'visibility' => 'public',
                'published_at' => now(),
                'starts_at' => now()->addDays($index),
            ]);
        }

        $response = $this->get(eventsIndexUrl());

        $response->assertOk()
            ->assertSee('Event 12')
            ->assertDontSee('Event 13');
    });

    it('eager loads event card relationships', function () {
        config(['scout.driver' => 'database']);

        $institution = Institution::factory()->create();
        $venue = Venue::factory()->create();

        Event::factory()
            ->for($institution)
            ->for($venue)
            ->hasSpeakers(1)
            ->create([
                'status' => 'approved',
                'visibility' => 'public',
                'published_at' => now(),
                'starts_at' => now()->addDays(1),
                'event_type' => [EventType::KuliahCeramah],
            ]);

        $events = app(EventSearchService::class)->search(
            query: null,
            filters: [],
            perPage: 20,
            sort: 'time'
        );

        $event = collect($events->items())->first();

        expect($event)->not->toBeNull()
            ->and($event->relationLoaded('institution'))->toBeTrue()
            ->and($event->relationLoaded('venue'))->toBeTrue()
            ->and($event->relationLoaded('speakers'))->toBeTrue()
            ->and($event->relationLoaded('media'))->toBeTrue();

        if ($event->institution) {
            expect($event->institution->relationLoaded('media'))->toBeTrue();
        }

        if ($event->speakers->isNotEmpty()) {
            expect($event->speakers->first()->relationLoaded('media'))->toBeTrue();
        }
    });

    it('uses cover media for card images in search results when poster is missing', function () {
        config(['scout.driver' => 'database']);
        Storage::fake('public');
        config()->set('media-library.disk_name', 'public');

        $event = createVisibleEventForSearch([
            'title' => 'Cover Only Search Card Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        $event->addMedia(UploadedFile::fake()->image('cover-only.jpg', 1600, 900))
            ->toMediaCollection('cover');

        $results = app(EventSearchService::class)->search(
            query: 'Cover Only Search Card Event',
            filters: [],
            perPage: 20,
            sort: 'time',
        );

        /** @var Event|null $resultEvent */
        $resultEvent = collect($results->items())->first();

        expect($resultEvent)->not->toBeNull()
            ->and($resultEvent?->relationLoaded('media'))->toBeTrue()
            ->and($resultEvent?->media->pluck('collection_name')->contains('cover'))->toBeTrue()
            ->and($resultEvent?->card_image_url)->not->toContain('images/placeholders/event.png')
            ->and($resultEvent?->card_image_url)->toContain('cover');
    });

    it('displays event count', function () {
        Event::factory()->count(5)->create([
            'institution_id' => Institution::factory(),
            'venue_id' => null,
            'event_format' => EventFormat::Physical,
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
        ]);

        $response = $this->get(eventsIndexUrl());

        $response->assertOk()
            ->assertSee('5')
            ->assertSee('Upcoming Gatherings');
    });

    it('ignores prayer time filter when timing mode is absolute', function () {
        createVisibleEventForSearch([
            'title' => 'Absolute Timing Event',
            'status' => 'approved',
            'visibility' => 'public',
            'timing_mode' => TimingMode::Absolute,
            'published_at' => now(),
            'starts_at' => now()->addDays(2)->setTime(20, 0),
        ]);

        createVisibleEventForSearch([
            'title' => 'Prayer Relative Timing Event',
            'status' => 'approved',
            'visibility' => 'public',
            'timing_mode' => TimingMode::PrayerRelative,
            'prayer_display_text' => 'Selepas Maghrib',
            'published_at' => now(),
            'starts_at' => now()->addDays(2)->setTime(20, 0),
        ]);

        $response = $this->get(eventsIndexUrl([
            'timing_mode' => TimingMode::Absolute->value,
            'prayer_time' => 'Selepas Maghrib',
        ]));

        $response->assertOk()
            ->assertSee('Absolute Timing Event')
            ->assertDontSee('Prayer Relative Timing Event');
    });

    it('treats explicit false URL filter as active and keeps active filter chips visible', function () {
        createVisibleEventForSearch([
            'title' => 'No URL Event',
            'status' => 'approved',
            'visibility' => 'public',
            'event_url' => null,
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        createVisibleEventForSearch([
            'title' => 'Has URL Event',
            'status' => 'approved',
            'visibility' => 'public',
            'event_url' => 'https://example.com/event',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $response = $this->get(eventsIndexUrl('has_event_url=0'));

        $response->assertOk()
            ->assertSee('No URL Event')
            ->assertDontSee('Has URL Event')
            ->assertSee('No Event URL')
            ->assertSee('Clear All Filters')
            ->assertSee('Save This Search');
    });

    it('filters events by held date overlap range', function () {
        createVisibleEventForSearch([
            'title' => 'Overlap Via End Time',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(4)->setTime(20, 0),
            'ends_at' => now()->addDays(5)->setTime(9, 0),
        ]);

        createVisibleEventForSearch([
            'title' => 'Within Held Range',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(6)->setTime(12, 0),
            'ends_at' => null,
        ]);

        createVisibleEventForSearch([
            'title' => 'Outside Before Range',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2)->setTime(10, 0),
            'ends_at' => now()->addDays(3)->setTime(10, 0),
        ]);

        createVisibleEventForSearch([
            'title' => 'Outside After Range',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(7)->setTime(10, 0),
            'ends_at' => now()->addDays(8)->setTime(10, 0),
        ]);

        $query = http_build_query([
            'starts_after' => now()->addDays(5)->toDateString(),
            'starts_before' => now()->addDays(6)->toDateString(),
        ]);

        $response = $this->get(eventsIndexUrl($query));

        $response->assertOk()
            ->assertSee('Overlap Via End Time')
            ->assertSee('Within Held Range')
            ->assertDontSee('Outside Before Range')
            ->assertDontSee('Outside After Range');
    });

    it('filters events by prayer_time keyword in advanced filters', function () {
        createVisibleEventForSearch([
            'title' => 'Kuliah Selepas Maghrib',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
            'timing_mode' => TimingMode::PrayerRelative,
            'prayer_reference' => PrayerReference::Maghrib,
            'prayer_display_text' => 'Selepas Maghrib',
        ]);

        createVisibleEventForSearch([
            'title' => 'Kuliah Selepas Subuh',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
            'timing_mode' => TimingMode::PrayerRelative,
            'prayer_reference' => PrayerReference::Fajr,
            'prayer_display_text' => 'Selepas Subuh',
        ]);

        $response = $this->get(eventsIndexUrl('prayer_time=Selepas+Maghrib'));

        $response->assertOk()
            ->assertSee('Kuliah Selepas Maghrib')
            ->assertDontSee('Kuliah Selepas Subuh');
    });

    it('interprets starts_after date in the user timezone', function () {
        $userTimezone = 'Asia/Kuala_Lumpur';
        $localFilterDate = now($userTimezone)->addDays(2)->toDateString();

        $includedStartUtc = Carbon::parse($localFilterDate.' 01:00:00', $userTimezone)->setTimezone('UTC');
        $excludedStartUtc = Carbon::parse($localFilterDate.' 23:30:00', $userTimezone)
            ->subDay()
            ->setTimezone('UTC');

        createVisibleEventForSearch([
            'title' => 'Timezone Included Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => $includedStartUtc,
            'ends_at' => null,
        ]);

        createVisibleEventForSearch([
            'title' => 'Timezone Excluded Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => $excludedStartUtc,
            'ends_at' => null,
        ]);

        $component = Livewire::withCookie('user_timezone', $userTimezone)
            ->test(Index::class)
            ->set('starts_after', $localFilterDate);

        $eventTitles = $component->instance()
            ->events
            ->getCollection()
            ->pluck('title')
            ->all();

        expect($eventTitles)
            ->toContain('Timezone Included Event')
            ->not->toContain('Timezone Excluded Event');
    });

    it('hydrates canonical date range query params into public filters and form state', function () {
        $userTimezone = 'Asia/Kuala_Lumpur';
        $expectedDate = '2026-04-17';

        createVisibleEventForSearch([
            'title' => 'Date Query Included Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => Carbon::parse('2026-04-16 17:00:00', 'UTC'),
            'ends_at' => null,
        ]);

        createVisibleEventForSearch([
            'title' => 'Date Query Previous Day Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => Carbon::parse('2026-04-16 15:30:00', 'UTC'),
            'ends_at' => null,
        ]);

        createVisibleEventForSearch([
            'title' => 'Date Query Next Day Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => Carbon::parse('2026-04-17 16:30:00', 'UTC'),
            'ends_at' => null,
        ]);

        $component = Livewire::withCookie('user_timezone', $userTimezone)
            ->withQueryParams([
                'starts_after' => $expectedDate,
                'starts_before' => $expectedDate,
                'time_scope' => 'all',
            ])
            ->test(Index::class);

        $eventTitles = $component->instance()
            ->events
            ->getCollection()
            ->pluck('title')
            ->all();

        expect($eventTitles)
            ->toContain('Date Query Included Event')
            ->not->toContain('Date Query Previous Day Event')
            ->not->toContain('Date Query Next Day Event');

        $component
            ->assertSet('starts_after', $expectedDate)
            ->assertSet('starts_before', $expectedDate)
            ->assertSet('time_scope', 'all')
            ->assertSet('filterData.starts_after', $expectedDate)
            ->assertSet('filterData.starts_before', $expectedDate)
            ->assertSet('filterData.time_scope', 'all');
    });

    it('filters events to past only when time scope is past', function () {
        createVisibleEventForSearch([
            'title' => 'Past Scope Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->subDays(2),
        ]);

        createVisibleEventForSearch([
            'title' => 'Past Scope Non Match',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $response = $this->get(eventsIndexUrl('time_scope=past'));

        $response->assertOk()
            ->assertSee('Past Scope Match')
            ->assertDontSee('Past Scope Non Match');
    });

    it('shows both past and upcoming events when time scope is all', function () {
        createVisibleEventForSearch([
            'title' => 'All Scope Past',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->subDays(2),
        ]);

        createVisibleEventForSearch([
            'title' => 'All Scope Upcoming',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $response = $this->get(eventsIndexUrl('time_scope=all'));

        $response->assertOk()
            ->assertSee('All Scope Past')
            ->assertSee('All Scope Upcoming');
    });

    it('returns distance in database nearby search fallback', function () {
        config(['scout.driver' => 'database']);

        $nearInstitution = Institution::factory()->create();
        $nearVenue = Venue::factory()->create();
        $nearVenue->address()->update([
            'lat' => 3.1390,
            'lng' => 101.6869,
        ]);

        $farInstitution = Institution::factory()->create();
        $farVenue = Venue::factory()->create();
        $farVenue->address()->update([
            'lat' => 3.2600,
            'lng' => 101.8600,
        ]);

        Event::factory()->for($nearInstitution)->for($nearVenue)->create([
            'title' => 'Nearby Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(4),
        ]);

        Event::factory()->for($farInstitution)->for($farVenue)->create([
            'title' => 'Far Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(4),
        ]);

        $events = app(EventSearchService::class)->searchNearby(
            lat: 3.1390,
            lng: 101.6869,
            radiusKm: 12,
            filters: [],
            perPage: 20
        );

        $eventTitles = collect($events->items())->pluck('title')->all();

        expect($eventTitles)->toContain('Nearby Event');
        expect($eventTitles)->not->toContain('Far Event');

        $nearest = collect($events->items())->first();

        expect($nearest)->not->toBeNull()
            ->and($nearest->distance_km ?? null)->not->toBeNull();
    });

    it('returns institution-based events in nearby search when venue is not set', function () {
        config(['scout.driver' => 'database']);

        $nearInstitution = Institution::factory()->create();
        $nearInstitution->address()->update([
            'lat' => 3.1390,
            'lng' => 101.6869,
        ]);

        $farInstitution = Institution::factory()->create();
        $farInstitution->address()->update([
            'lat' => 3.2600,
            'lng' => 101.8600,
        ]);

        Event::factory()->for($nearInstitution)->create([
            'title' => 'Nearby Institution Event',
            'venue_id' => null,
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(4),
        ]);

        Event::factory()->for($farInstitution)->create([
            'title' => 'Far Institution Event',
            'venue_id' => null,
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(4),
        ]);

        $events = app(EventSearchService::class)->searchNearby(
            lat: 3.1390,
            lng: 101.6869,
            radiusKm: 15,
            filters: [],
            perPage: 20
        );

        $eventTitles = collect($events->items())->pluck('title')->all();

        expect($eventTitles)->toContain('Nearby Institution Event');
        expect($eventTitles)->not->toContain('Far Institution Event');

        $nearest = collect($events->items())->firstWhere('title', 'Nearby Institution Event');

        expect($nearest)->not->toBeNull()
            ->and($nearest->distance_km ?? null)->not->toBeNull();
    });

    it('ignores event-level address records in nearby search', function () {
        config(['scout.driver' => 'database']);

        $institution = Institution::factory()->create();

        $event = Event::factory()->for($institution)->create([
            'title' => 'Event Address Should Be Ignored',
            'event_format' => EventFormat::Online,
            'institution_id' => null,
            'venue_id' => null,
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(4),
        ]);

        $event->address()->create([
            'lat' => 3.1390,
            'lng' => 101.6869,
        ]);

        $events = app(EventSearchService::class)->searchNearby(
            lat: 3.1390,
            lng: 101.6869,
            radiusKm: 15,
            filters: [],
            perPage: 20
        );

        $eventTitles = collect($events->items())->pluck('title')->all();

        expect($eventTitles)->not->toContain('Event Address Should Be Ignored');
    });
});

describe('Event Detail Page', function () {
    beforeEach(function () {
        app()->setLocale('en');
    });

    it('displays event details', function () {
        $event = Event::factory()->create([
            'title' => 'My Amazing Event',
            'description' => 'This is the description',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $response = $this->get(eventShowUrl($event));

        $response->assertOk()
            ->assertSee('My Amazing Event')
            ->assertSee('This is the description');
    });

    it('shows pending events on detail page with warning banner', function () {
        $event = Event::factory()->create([
            'title' => 'Pending Detail Event',
            'status' => 'pending',
            'visibility' => 'public',
        ]);

        $response = $this->get(eventShowUrl($event));

        $response->assertOk()
            ->assertSee('Pending Detail Event')
            ->assertSee('Pending Approval');
    });

    it('shows cancelled events on detail page with cancellation banner', function () {
        $event = Event::factory()->create([
            'title' => 'Cancelled Detail Event',
            'status' => 'cancelled',
            'visibility' => 'public',
        ]);

        $response = $this->get(eventShowUrl($event));

        $response->assertOk()
            ->assertSee('Cancelled Detail Event')
            ->assertSee('Majlis Dibatalkan')
            ->assertSee('Kalendar tidak tersedia untuk majlis dibatalkan.')
            ->assertDontSee('Tambah ke Kalendar')
            ->assertSee('https://schema.org/EventCancelled');
    });

    it('shows 404 for draft events', function () {
        $event = Event::factory()->create([
            'status' => 'draft',
            'visibility' => 'public',
        ]);

        $response = $this->get(eventShowUrl($event));

        $response->assertNotFound();
    });

    it('shows 404 for private events', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'private',
            'published_at' => now(),
        ]);

        $response = $this->get(eventShowUrl($event));

        $response->assertNotFound();
    });

    it('includes JSON-LD structured data', function () {
        $event = Event::factory()->create([
            'title' => 'SEO Test Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $response = $this->get(eventShowUrl($event));

        $response->assertOk()
            ->assertSee('application/ld+json', false);
    });

    it('includes OpenGraph meta tags', function () {
        $event = Event::factory()->create([
            'title' => 'OG Test Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $response = $this->get(eventShowUrl($event));

        $response->assertOk()
            ->assertSee('og:title', false);
    });

    it('displays speakers', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $speakerOne = Speaker::factory()->create([
            'name' => 'Ustaz Speaker One',
            'honorific' => null,
            'pre_nominal' => null,
            'post_nominal' => null,
            'job_title' => 'Pensyarah',
            'is_freelance' => false,
            'status' => 'verified',
            'is_active' => true,
        ]);

        $speakerTwo = Speaker::factory()->create([
            'name' => 'Ustaz Speaker Two',
            'honorific' => null,
            'pre_nominal' => null,
            'post_nominal' => null,
            'job_title' => 'Mudir',
            'is_freelance' => false,
            'status' => 'verified',
            'is_active' => true,
        ]);

        $event->speakers()->attach($speakerOne->id);
        $event->speakers()->attach($speakerTwo->id);

        $response = $this->get(eventShowUrl($event));

        $response->assertOk()
            ->assertSee('Speakers')
            ->assertSee('Ustaz Speaker One')
            ->assertSee('Pensyarah')
            ->assertSee('Ustaz Speaker Two')
            ->assertSee('Mudir');
    });

    it('displays image gallery slider when gallery media exists', function () {
        Storage::fake('public');
        config()->set('media-library.disk_name', 'public');

        $event = Event::factory()->create([
            'title' => 'Gallery Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $event->addMedia(UploadedFile::fake()->image('gallery-1.jpg', 1200, 800))
            ->toMediaCollection('gallery');
        $event->addMedia(UploadedFile::fake()->image('gallery-2.jpg', 1200, 800))
            ->toMediaCollection('gallery');

        $response = $this->get(eventShowUrl($event));

        $response->assertOk()
            ->assertSee('Event Gallery');
    });

    it('displays related events section', function () {
        $institution = Institution::factory()->create();
        $sharedTag = Tag::factory()->discipline()->create();

        $event = Event::factory()->for($institution)->create([
            'title' => 'Main Related Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
        ]);
        $event->attachTag($sharedTag);

        Event::factory()->for($institution)->create([
            'title' => 'Institution Related Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
        ]);

        $tagRelatedEvent = Event::factory()->create([
            'title' => 'Tag Related Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(3),
        ]);
        $tagRelatedEvent->attachTag($sharedTag);

        Event::factory()->create([
            'title' => 'Private Hidden Event',
            'status' => 'approved',
            'visibility' => 'private',
            'published_at' => now(),
            'starts_at' => now()->addDays(4),
        ]);

        $response = $this->get(eventShowUrl($event));

        $response->assertOk()
            ->assertSee('Main Related Event')
            ->assertDontSee('Private Hidden Event');
    });

    it('renders share preview modal content', function () {
        $event = Event::factory()->create([
            'title' => 'Shareable Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $response = $this->get(eventShowUrl($event));

        $response->assertOk()
            ->assertSee('Share Preview')
            ->assertSee('Copy Link');
    });
});

describe('Event Registration', function () {
    beforeEach(function () {
        app()->setLocale('en');
    });

    it('shows registration button for events requiring registration', function () {
        $event = Event::factory()
            ->has(EventSettings::factory()->state(['registration_required' => true]), 'settings')
            ->create([
                'title' => 'Registration Event',
                'status' => 'approved',
                'visibility' => 'public',
                'published_at' => now(),
            ]);

        $response = $this->get(eventShowUrl($event));

        $response->assertOk()
            ->assertSee('Register');
    });

    it('shows no registration message for open events', function () {
        $event = Event::factory()
            ->has(EventSettings::factory()->state(['registration_required' => false]), 'settings')
            ->create([
                'status' => 'approved',
                'visibility' => 'public',
                'published_at' => now(),
            ]);

        $response = $this->get(eventShowUrl($event));

        $response->assertOk()
            ->assertDontSee('Registration Required')
            ->assertDontSee('Register Now');
    });

    it('allows guest registration', function () {
        $event = Event::factory()
            ->has(EventSettings::factory()->state([
                'registration_required' => true,
                'registration_opens_at' => now()->subDay(),
                'registration_closes_at' => now()->addDay(),
                'capacity' => 100,
            ]), 'settings')
            ->create([
                'status' => 'approved',
                'visibility' => 'public',
                'published_at' => now(),
                'registrations_count' => 0,
            ]);

        $response = $this->post(eventRegistrationUrl($event), [
            'name' => 'Ahmad',
            'email' => 'ahmad@example.com',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('registrations', [
            'event_id' => $event->id,
            'name' => 'Ahmad',
            'email' => 'ahmad@example.com',
        ]);
    });

    it('prevents duplicate registration', function () {
        $event = Event::factory()
            ->has(EventSettings::factory()->state([
                'registration_required' => true,
                'registration_opens_at' => now()->subDay(),
                'registration_closes_at' => now()->addDay(),
            ]), 'settings')
            ->create([
                'status' => 'approved',
                'visibility' => 'public',
                'published_at' => now(),
            ]);

        // First registration
        $this->post(eventRegistrationUrl($event), [
            'name' => 'Ahmad',
            'email' => 'ahmad@example.com',
        ]);

        // Duplicate
        $response = $this->post(eventRegistrationUrl($event), [
            'name' => 'Ahmad Again',
            'email' => 'ahmad@example.com',
        ]);

        $response->assertSessionHasErrors(['registration']);
    });

    it('enforces capacity limits', function () {
        $event = Event::factory()
            ->has(EventSettings::factory()->state([
                'registration_required' => true,
                'registration_opens_at' => now()->subDay(),
                'registration_closes_at' => now()->addDay(),
                'capacity' => 1,
            ]), 'settings')
            ->create([
                'status' => 'approved',
                'visibility' => 'public',
                'published_at' => now(),
                'registrations_count' => 1,
            ]);

        Registration::factory()->create([
            'event_id' => $event->id,
            'status' => 'registered',
            'name' => 'Existing Registrant',
            'email' => 'existing-capacity@example.com',
        ]);

        $response = $this->post(eventRegistrationUrl($event), [
            'name' => 'Late Registrant',
            'email' => 'late@example.com',
        ]);

        $response->assertSessionHasErrors(['registration']);
    });
});
