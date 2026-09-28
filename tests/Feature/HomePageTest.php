<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventTimeExpression;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\ReferenceType;
use App\Enums\TimingMode;
use App\Livewire\Components\EventFilters;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Support\Location\VisitorCountryResolver;
use App\Support\Timezone\UserDateTimeFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('displays the homepage successfully', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertSee('Cari');
    $response->assertSee('Majlis');
    $response->assertSee('Ilmu');
    $response->assertDontSee('Majlis Berdekatan');
    $response->assertDontSee('grainy-gradients.vercel.app', false);
});

it('contains livewire components on the homepage', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
    // The page should contain Livewire component markers
    $response->assertSee('wire:snapshot', false);
    $response->assertSee('livewire.js', false);
});

it('keeps Hari ini all-day and applies both evening prayer filters to Malam Ini', function () {
    Carbon::setTestNow(Carbon::create(2026, 4, 16, 10, 0, 0, 'UTC'));
    $todayRange = ['starts_after' => '2026-04-16', 'starts_before' => '2026-04-16', 'time_scope' => 'all'];
    $tomorrowRange = ['starts_after' => '2026-04-17', 'starts_before' => '2026-04-17', 'time_scope' => 'all'];
    $filters = Livewire::test(EventFilters::class, [
        'quickFilterRanges' => [
            'today' => $todayRange,
            'malam_ini' => $todayRange,
            'tomorrow' => $tomorrowRange,
        ],
    ]);

    try {
        $filters->call('applyHomeQuickFilter', 'today')
            ->assertSet('filterData.date_shortcut', 'today')
            ->assertSet('filterData.timing_mode', null)
            ->assertSet('filterData.prayer_time', [])
            ->assertSet('selectedHomeQuickFilter', 'today');
        expect($filters->instance()->activeHomeQuickFilters())->toContain('today');

        $filters->call('applyHomeQuickFilter', 'malam_ini')
            ->assertSet('selectedHomeQuickFilter', 'malam_ini')
            ->assertSet('filterData.timing_mode', TimingMode::PrayerRelative->value)
            ->assertSet('filterData.prayer_time', [
                EventPrayerTime::SelepasMaghrib->value,
                EventPrayerTime::SelepasIsyak->value,
            ]);
        expect($filters->instance()->activeHomeQuickFilters())->toContain('malam_ini');

        $filters->call('applyHomeQuickFilter', 'today')
            ->assertSet('filterData.date_shortcut', 'today')
            ->assertSet('filterData.timing_mode', null)
            ->assertSet('filterData.prayer_time', []);
        expect($filters->instance()->activeHomeQuickFilters())
            ->toContain('today')
            ->not->toContain('malam_ini');

        $filters->call('applyHomeQuickFilter', 'malam_ini')
            ->call('applyHomeQuickFilter', 'tomorrow')
            ->assertSet('filterData.date_shortcut', 'tomorrow')
            ->assertSet('filterData.timing_mode', null)
            ->assertSet('filterData.prayer_time', []);
        expect($filters->instance()->activeHomeQuickFilters())->toContain('tomorrow');
    } finally {
        Carbon::setTestNow();
    }
});

it('connects all seven homepage date intervals to the detailed event filter state', function () {
    Carbon::setTestNow(Carbon::create(2026, 4, 16, 10, 0, 0, 'UTC'));
    $quickFilterRanges = [
        'today' => ['starts_after' => '2026-04-16', 'starts_before' => '2026-04-16', 'time_scope' => 'all'],
        'tomorrow' => ['starts_after' => '2026-04-17', 'starts_before' => '2026-04-17', 'time_scope' => 'all'],
        'this_week' => ['starts_after' => '2026-04-13', 'starts_before' => '2026-04-19', 'time_scope' => 'all'],
        'weekend' => ['starts_after' => '2026-04-18', 'starts_before' => '2026-04-19', 'time_scope' => 'all'],
        'this_month' => ['starts_after' => '2026-04-01', 'starts_before' => '2026-04-30', 'time_scope' => 'all'],
        'next_week' => ['starts_after' => '2026-04-20', 'starts_before' => '2026-04-26', 'time_scope' => 'all'],
        'next_month' => ['starts_after' => '2026-05-01', 'starts_before' => '2026-05-31', 'time_scope' => 'all'],
    ];
    $dateShortcuts = [
        'today' => 'today',
        'tomorrow' => 'tomorrow',
        'this_week' => 'this_week',
        'weekend' => 'this_weekend',
        'this_month' => 'this_month',
        'next_week' => 'next_week',
        'next_month' => 'next_month',
    ];
    try {
        $filters = Livewire::test(EventFilters::class, ['quickFilterRanges' => $quickFilterRanges]);

        foreach ($quickFilterRanges as $quickFilterKey => $quickFilterRange) {
            $filters->call('applyHomeQuickFilter', $quickFilterKey)
                ->assertSet('selectedHomeQuickFilter', $quickFilterKey)
                ->assertSet('filterData.date_shortcut', $dateShortcuts[$quickFilterKey])
                ->assertSet('filterData.time_scope', 'all');
            expect($filters->instance()->activeHomeQuickFilters())->toContain($quickFilterKey);
        }
    } finally {
        Carbon::setTestNow();
    }
});

it('loads the stats component', function () {
    // Create some test data
    Event::factory()->count(5)->create(['status' => 'approved']);
    Person::factory()->count(3)->create();
    Institution::factory()->count(2)->create();
    Reference::factory()->count(4)->create();

    Livewire::test('home.stats')
        ->assertSee('Majlis')
        ->assertSee('Penceramah')
        ->assertSee('Institusi')
        ->assertSee('Rujukan')
        ->assertDontSee('Ahli Komuniti')
        ->assertDontSee('Cakupan Lokasi');
});

it('loads the tonight events component when events exist', function () {
    // Create an event for tonight
    Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addHours(2),
    ]);

    Livewire::test('home.tonight-events')
        ->assertSee('Malam Ini');
});

it('renders the homepage interval shortcuts without legacy date aliases', function () {
    $response = $this->get('/');

    $response->assertSuccessful()
        ->assertSee('Hari ini')
        ->assertSee('Esok')
        ->assertSee('Minggu ini')
        ->assertSee('Hujung minggu')
        ->assertSee('Bulan ini')
        ->assertSee('Minggu depan')
        ->assertSee('Bulan depan')
        ->assertSee('Berdekatan')
        ->assertSee('Popular')
        ->assertSee('Malam Ini')
        ->assertDontSee('date=today', false)
        ->assertDontSee('date=friday', false)
        ->assertDontSee('date=this-week', false)
        ->assertDontSee('date=weekend', false);
});

it('loads the featured events component with upcoming events', function () {
    // Create an event for this week
    Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3),
    ]);

    Livewire::test('home.featured-events')
        ->assertSee('Majlis Pilihan');
});

it('uses a 16:9 placeholder aspect ratio on featured home cards without posters', function () {
    Event::factory()->create([
        'title' => 'Majlis Pilihan Tanpa Poster',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3),
    ]);

    Livewire::test('home.featured-events')
        ->assertSee('Majlis Pilihan Tanpa Poster')
        ->assertSee('data-cover-aspect="16:9"', false);
});

it('renders the featured homepage card date badge below the poster image', function () {
    Event::factory()->create([
        'title' => 'Majlis Pilihan Dengan Tarikh Bawah',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3),
    ]);

    Livewire::test('home.featured-events')
        ->assertSee('Majlis Pilihan Dengan Tarikh Bawah')
        ->assertSee('data-testid="homepage-featured-card-meta-row"', false)
        ->assertSee('data-testid="homepage-featured-card-date-badge"', false)
        ->assertSeeInOrder([
            'data-cover-aspect=',
            'data-testid="homepage-featured-card-meta-row"',
            'data-testid="homepage-featured-card-title-link"',
        ], false);
});

it('loads the upcoming events component', function () {
    Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(1),
    ]);

    Livewire::test('home.upcoming-events')
        ->assertSee('Majlis Akan Datang');
});

it('groups homepage date filter counts by the viewer local date', function () {
    $userTimezone = 'Asia/Kuala_Lumpur';
    $originalAppTimezone = config('app.timezone');
    $originalDefaultTimezone = date_default_timezone_get();

    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    Carbon::setTestNow(Carbon::create(2026, 4, 16, 18, 0, 0, 'UTC'));

    try {
        Event::factory()->create([
            'title' => 'Local Today Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => Carbon::create(2026, 4, 16, 16, 30, 0, 'UTC'),
        ]);

        Event::factory()->create([
            'title' => 'Previous Local Day Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => Carbon::create(2026, 4, 16, 15, 30, 0, 'UTC'),
        ]);

        Event::factory()->create([
            'title' => 'Local Tomorrow Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => Carbon::create(2026, 4, 17, 16, 30, 0, 'UTC'),
        ]);

        $dates = Livewire::withCookie('user_timezone', $userTimezone)
            ->test('home.date-filter')
            ->instance()
            ->upcomingDates;

        $today = $dates->first(fn (array $dateItem): bool => $dateItem['date']->format('Y-m-d') === '2026-04-17');
        $tomorrow = $dates->first(fn (array $dateItem): bool => $dateItem['date']->format('Y-m-d') === '2026-04-18');

        expect($today)->not->toBeNull()
            ->and($tomorrow)->not->toBeNull()
            ->and($today['count'])->toBe(1)
            ->and($tomorrow['count'])->toBe(1);
    } finally {
        Carbon::setTestNow();
        config(['app.timezone' => $originalAppTimezone]);
        date_default_timezone_set($originalDefaultTimezone);
    }
});

it('uses canonical date range query parameters in homepage date components', function () {
    Carbon::setTestNow(Carbon::create(2026, 4, 16, 10, 0, 0, 'UTC'));

    Livewire::test('home.tonight-events')
        ->assertSee(route('events.index', [
            'starts_after' => '2026-04-16',
            'starts_before' => '2026-04-16',
            'time_scope' => 'all',
        ]))
        ->assertDontSee('date=today', false);

    Livewire::test('home.date-filter')
        ->assertSee(route('events.index', [
            'starts_after' => '2026-04-16',
            'starts_before' => '2026-04-16',
            'time_scope' => 'all',
        ]))
        ->assertSee(route('events.index', [
            'starts_after' => '2026-04-17',
            'starts_before' => '2026-04-17',
            'time_scope' => 'all',
        ]))
        ->assertDontSee('date=', false);

    Carbon::setTestNow();
});

it('renders the attached book title across homepage event components without parentheses', function () {
    Carbon::setTestNow(Carbon::create(2026, 4, 7, 18, 0, 0));

    $bookReference = Reference::factory()->create([
        'title' => 'Riyadhus Solihin',
        'type' => ReferenceType::Book->value,
    ]);

    $featuredEvent = Event::factory()->create([
        'title' => 'Kuliah Kitab Pilihan',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
    ]);

    $tonightEvent = Event::factory()->create([
        'title' => 'Kuliah Kitab Malam Ini',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addHours(2),
    ]);

    $featuredEvent->references()->attach($bookReference->id);
    $tonightEvent->references()->attach($bookReference->id);

    Livewire::test('home.featured-events')
        ->assertSee('Kuliah Kitab Pilihan')
        ->assertSee('Riyadhus Solihin')
        ->assertDontSee('(Riyadhus Solihin)');

    Livewire::test('home.upcoming-events')
        ->assertSee('Kuliah Kitab Pilihan')
        ->assertSee('Riyadhus Solihin')
        ->assertDontSee('(Riyadhus Solihin)');

    Livewire::test('home.tonight-events')
        ->assertSee('Kuliah Kitab Malam Ini')
        ->assertSee('Riyadhus Solihin')
        ->assertDontSee('(Riyadhus Solihin)');

    Livewire::test('home.upcoming-prayer-events')
        ->assertSee('Kuliah Kitab Malam Ini')
        ->assertSee('Riyadhus Solihin')
        ->assertDontSee('(Riyadhus Solihin)');

    Carbon::setTestNow();
});

it('lists every speaker name below the location on the featured homepage cards', function () {
    $institution = Institution::factory()->create(['name' => 'Masjid Ujian Penempatan']);

    OwnerContext::withOwner(null, function () use ($institution): void {
        $country = AddressCountry::query()->firstOrCreate(
            ['iso2' => 'MY'],
            ['name' => 'Malaysia', 'iso3' => 'MYS', 'region' => 'Asia', 'subregion' => 'South-Eastern Asia', 'phone_code' => '60'],
        );
        $address = Address::create([
            'country_id' => (string) $country->getKey(),
            'country_code' => 'MY',
            'line1' => 'No 1, Jalan Ujian',
            'city' => 'Shah Alam',
            'postcode' => '40000',
            'state' => 'Selangor',
        ]);
        $institution->attachAddress($address, 'primary', true);
    });

    $startsAt = now()->addDays(3)->setTime(9, 20);
    $event = Event::factory()->create([
        'title' => 'Kuliah Pelbagai Penceramah',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->setTime(11, 35),
        'timing_mode' => TimingMode::Absolute->value,
        'institution_id' => $institution->id,
    ]);

    $firstSpeaker = Person::factory()->create(['name' => 'Ahmad Albab']);
    $secondSpeaker = Person::factory()->create(['name' => 'Bilal Badr']);
    $thirdSpeaker = Person::factory()->create(['name' => 'Candra Kirana']);
    $fourthSpeaker = Person::factory()->create(['name' => 'Dawud Salam']);

    $event->persons()->attach($firstSpeaker->id, ['sort_order' => 0]);
    $event->persons()->attach($firstSpeaker->id, ['sort_order' => 1]);
    $event->persons()->attach($secondSpeaker->id, ['sort_order' => 2]);
    $event->persons()->attach($thirdSpeaker->id, ['sort_order' => 3]);
    $event->persons()->attach($fourthSpeaker->id, ['sort_order' => 4]);

    $expectedSpeakers = collect([$firstSpeaker, $secondSpeaker, $thirdSpeaker, $fourthSpeaker])
        ->map(fn (Person $speaker): string => $speaker->refresh()->formatted_name)
        ->join(', ');
    $expectedLocation = 'Masjid Ujian Penempatan, Shah Alam, Selangor';
    $expectedRange = UserDateTimeFormatter::format($event->refresh()->starts_at, 'g:i A')
        .' — '
        .UserDateTimeFormatter::format($event->ends_at, 'g:i A');

    $branchInstitution = Institution::factory()->create(['name' => 'Surau Ujian Cawangan']);

    OwnerContext::withOwner(null, function () use ($branchInstitution): void {
        $country = AddressCountry::query()->firstOrCreate(
            ['iso2' => 'MY'],
            ['name' => 'Malaysia', 'iso3' => 'MYS', 'region' => 'Asia', 'subregion' => 'South-Eastern Asia', 'phone_code' => '60'],
        );
        $branchInstitution->attachAddress(Address::create([
            'country_id' => (string) $country->getKey(),
            'country_code' => 'MY',
            'postcode' => '21003',
            'state' => 'Perlis',
        ]), 'primary', true);
    });

    Event::factory()->create([
        'title' => 'Kuliah Negeri Pantai Timur',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(4),
        'institution_id' => $branchInstitution->id,
    ]);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Kuliah Pelbagai Penceramah')
        ->assertSee($expectedLocation)
        ->assertSee($expectedRange)
        ->assertSee('Surau Ujian Cawangan, Perlis')
        ->assertDontSee('40000')
        ->assertDontSee('21003')
        ->assertDontSee('No 1, Jalan Ujian')
        ->assertSeeInOrder([$expectedLocation, $expectedSpeakers])
        ->assertSee('Penceramah jemputan')
        ->assertSee('data-testid="homepage-featured-card-speaker-avatars"', false)
        ->assertSee('+1')
        ->assertSee('<div class="flex items-center gap-3">', false)
        ->assertSee('<span data-testid="homepage-featured-card-speakers" class="line-clamp-2">', false);
});

it('shows prayer labels and suppresses cross-day end times on featured homepage cards', function () {
    $prayerEvent = Event::factory()->create([
        'title' => 'Kuliah Petang Jumaat',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3)->setTime(17, 30),
        'ends_at' => null,
        'timing_mode' => TimingMode::PrayerRelative->value,
        'prayer_reference' => PrayerReference::Asr->value,
        'prayer_offset' => PrayerOffset::Immediately->value,
    ]);

    EventTimeExpression::create([
        'event_id' => $prayerEvent->id,
        'time_mode' => 'prayer_relative',
        'anchor_type' => 'prayer',
        'anchor_code' => PrayerReference::Asr->value,
    ]);

    $multiDayStartsAt = now()->addDays(4)->setTime(9, 0);
    $multiDayEvent = Event::factory()->create([
        'title' => 'Daurah Tiga Hari',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => $multiDayStartsAt,
        'ends_at' => $multiDayStartsAt->copy()->addDays(2)->setTime(16, 0),
        'timing_mode' => TimingMode::Absolute->value,
    ]);

    $multiDayEvent->refresh();
    $multiDayStart = UserDateTimeFormatter::format($multiDayEvent->starts_at, 'g:i A');
    $multiDayEnd = UserDateTimeFormatter::format($multiDayEvent->ends_at, 'g:i A');

    $this->get('/')
        ->assertSuccessful()
        ->assertSeeInOrder(['Kuliah Petang Jumaat', 'Selepas Asar'])
        ->assertSee($multiDayStart)
        ->assertDontSee($multiDayStart.' — '.$multiDayEnd);
});

it('toggles saved events from the featured homepage cards', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'title' => 'Kuliah Boleh Disimpan',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(3),
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages.home')
        ->assertSee('data-save-icon="event"', false)
        ->assertSee('data-save-state="unsaved"', false);

    $component->call('toggleSave', $event->id);

    $this->assertDatabaseHas('engagement_bookmarks', [
        'bookmarker_type' => $user->getMorphClass(),
        'bookmarker_id' => $user->id,
        'bookmarkable_type' => $event->getMorphClass(),
        'bookmarkable_id' => $event->id,
        'status' => 'active',
    ]);

    $component->call('toggleSave', $event->id)
        ->assertSee('data-save-state="unsaved"', false);

    $this->assertDatabaseMissing('engagement_bookmarks', [
        'bookmarker_type' => $user->getMorphClass(),
        'bookmarker_id' => $user->id,
        'bookmarkable_type' => $event->getMorphClass(),
        'bookmarkable_id' => $event->id,
        'status' => 'active',
    ]);
});

it('redirects guests to login when saving from featured homepage cards', function () {
    $event = Event::factory()->create([
        'title' => 'Kuliah Tetamu Cuba Simpan',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(3),
    ]);

    Livewire::test('pages.home')
        ->call('toggleSave', (string) $event->id)
        ->assertRedirect();

    $this->assertDatabaseMissing('engagement_bookmarks', [
        'bookmarkable_type' => $event->getMorphClass(),
        'bookmarkable_id' => $event->id,
        'status' => 'active',
    ]);
});

it('applies homepage filter defaults for Malaysian visitors', function () {
    ensureTestMalaysiaCountry();
    app(VisitorCountryResolver::class)->forget();

    $filters = Livewire::test(EventFilters::class)
        ->assertSet('event_format', [])
        ->assertSet('filterData.event_format', [])
        ->assertSet('language_codes', ['ms'])
        ->assertSet('filterData.language_codes', ['ms'])
        ->assertSet('gender', EventGenderRestriction::All->value)
        ->assertSet('filterData.gender', EventGenderRestriction::All->value)
        ->assertSet('age_group', [EventAgeGroup::AllAges->value])
        ->assertSet('filterData.age_group', [EventAgeGroup::AllAges->value])
        ->assertSet('children_allowed', true)
        ->assertSet('filterData.children_allowed', '1')
        ->assertSet('is_muslim_only', false)
        ->assertSet('filterData.is_muslim_only', '0')
        ->assertSet('filtersPanelOpen', false);

    expect($filters->instance()->activeFilterCount())->toBe(0);
});

it('leaves the language filter unrestricted for visitors outside Malaysia', function () {
    ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62');
    config()->set('location.default_country_code', 'ID');
    app(VisitorCountryResolver::class)->forget();

    Livewire::test(EventFilters::class)
        ->assertSet('language_codes', [])
        ->assertSet('filterData.language_codes', [])
        ->assertSet('event_format', [])
        ->assertSet('gender', EventGenderRestriction::All->value)
        ->assertSet('age_group', [EventAgeGroup::AllAges->value])
        ->assertSet('children_allowed', true)
        ->assertSet('is_muslim_only', false);
});

it('keeps explicit filter values over homepage defaults', function () {
    ensureTestMalaysiaCountry();
    app(VisitorCountryResolver::class)->forget();

    Livewire::withQueryParams([
        'event_format' => [EventFormat::Physical->value],
        'language_codes' => ['en'],
        'gender' => EventGenderRestriction::MenOnly->value,
        'age_group' => [EventAgeGroup::Youth->value],
        'children_allowed' => '0',
        'is_muslim_only' => '1',
    ])->test(EventFilters::class)
        ->assertSet('event_format', [EventFormat::Physical->value])
        ->assertSet('filterData.event_format', [EventFormat::Physical->value])
        ->assertSet('language_codes', ['en'])
        ->assertSet('filterData.language_codes', ['en'])
        ->assertSet('gender', EventGenderRestriction::MenOnly->value)
        ->assertSet('filterData.gender', EventGenderRestriction::MenOnly->value)
        ->assertSet('age_group', [EventAgeGroup::Youth->value])
        ->assertSet('filterData.age_group', [EventAgeGroup::Youth->value])
        ->assertSet('children_allowed', false)
        ->assertSet('is_muslim_only', true)
        ->assertSet('filtersPanelOpen', true);
});

it('counts only deviations from the homepage defaults as active filters', function () {
    ensureTestMalaysiaCountry();
    app(VisitorCountryResolver::class)->forget();

    $filters = Livewire::test(EventFilters::class);

    expect($filters->instance()->activeFilterCount())->toBe(0);

    $filters->set('filterData.gender', EventGenderRestriction::WomenOnly->value);

    expect($filters->instance()->activeFilterCount())->toBe(1);

    $filters->set('filterData.gender', EventGenderRestriction::All->value);

    expect($filters->instance()->activeFilterCount())->toBe(0);
});

it('resets filters to empty and restores the landed country', function () {
    $malaysia = ensureTestMalaysiaCountry();
    $indonesia = ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62');
    app(VisitorCountryResolver::class)->forget();

    $filters = Livewire::withQueryParams(['gender' => EventGenderRestriction::MenOnly->value])
        ->test(EventFilters::class)
        ->assertSet('filtersPanelOpen', true)
        ->assertSet('country_id', $malaysia->id)
        ->set('filterData.country_id', $indonesia->id)
        ->set('filterData.event_format', [EventFormat::Online->value])
        ->set('filterData.children_allowed', '0')
        ->call('clearAllFilters')
        ->assertSet('filtersPanelOpen', true)
        ->assertSet('country_id', $malaysia->id)
        ->assertSet('filterData.country_id', $malaysia->id)
        ->assertSet('state_id', null)
        ->assertSet('filterData.state_id', null)
        ->assertSet('area_assignments', [])
        ->assertSet('language_codes', [])
        ->assertSet('filterData.language_codes', [])
        ->assertSet('gender', null)
        ->assertSet('filterData.gender', null)
        ->assertSet('age_group', [])
        ->assertSet('filterData.age_group', [])
        ->assertSet('children_allowed', null)
        ->assertSet('filterData.children_allowed', null)
        ->assertSet('is_muslim_only', null)
        ->assertSet('filterData.is_muslim_only', null)
        ->assertSet('event_format', [])
        ->assertSet('filterData.event_format', []);

    expect($filters->instance()->activeFilterCount())->toBe(0);
});
