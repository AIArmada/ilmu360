<?php

use App\Enums\ContactCategory;
use App\Enums\ContactType;
use App\Enums\EventFormat;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventVisibility;
use App\Enums\InstitutionType;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\ReferenceType;
use App\Enums\TimingMode;
use App\Models\District;
use App\Models\Event;
use App\Models\Inspiration;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

it('renders the institution show page for a verified institution', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'type' => InstitutionType::Masjid,
        'description' => 'Masjid yang terkenal di kawasan ini.',
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee($institution->name)
        ->assertSee('Masjid yang terkenal di kawasan ini.');
});

it('returns 404 for unverified institution for guest', function () {
    $institution = Institution::factory()->create(['status' => 'pending']);

    $this->get(route('institutions.show', $institution))
        ->assertNotFound();
});

it('allows super_admin to view unverified institution', function () {
    config(['permission.teams' => false]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $roleClass = app(PermissionRegistrar::class)->getRoleClass();
    if (! $roleClass::where('name', 'super_admin')->exists()) {
        $roleClass::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $institution = Institution::factory()->create(['status' => 'pending']);

    $this->actingAs($admin)
        ->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee($institution->name);
});

it('displays institution type badge', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'type' => InstitutionType::Masjid,
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee(InstitutionType::Masjid->getLabel());
});

it('uses the institution logo as the public preview image when no cover exists', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create([
        'status' => 'verified',
        'name' => 'Institusi Tanpa Cover',
    ]);

    $institution->addMedia(UploadedFile::fake()->image('logo.png', 400, 400))
        ->toMediaCollection('logo');

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('<meta property="og:image" content="'.$institution->public_image_url.'">', false)
        ->assertSee('src="'.$institution->public_image_url.'"', false);
});

it('deduplicates matching district and subdistrict labels on institution show page', function () {
    $state = State::query()
        ->where('country_code', 'MY')
        ->where('name', 'Pahang')
        ->first();

    if (! $state) {
        $countryId = DB::table('countries')->insertGetId([
            'iso2' => 'MY',
            'name' => 'Malaysia',
            'status' => 1,
            'phone_code' => '60',
            'iso3' => 'MYS',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);

        $stateId = DB::table('states')->insertGetId([
            'country_id' => $countryId,
            'name' => 'Pahang',
            'country_code' => 'MY',
        ]);

        $state = State::query()->findOrFail($stateId);
    }

    $district = District::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'country_code' => 'MY',
        'name' => 'Temerloh',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'country_code' => 'MY',
        'name' => 'Temerloh',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Temerloh',
        'status' => 'verified',
    ]);

    $institution->address()->update([
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'subdistrict_id' => (int) $subdistrict->id,
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('Temerloh, Pahang')
        ->assertDontSee('Temerloh, Temerloh, Pahang');
});

it('displays the institution contact address block in street locality and regional lines', function () {
    $state = State::query()
        ->where('country_code', 'MY')
        ->where('name', 'Selangor')
        ->first();

    if (! $state) {
        $countryId = DB::table('countries')->insertGetId([
            'iso2' => 'MY',
            'name' => 'Malaysia',
            'status' => 1,
            'phone_code' => '60',
            'iso3' => 'MYS',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);

        $stateId = DB::table('states')->insertGetId([
            'country_id' => $countryId,
            'name' => 'Selangor',
            'country_code' => 'MY',
        ]);

        $state = State::query()->findOrFail($stateId);
    }

    $district = District::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'country_code' => 'MY',
        'name' => 'Petaling',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'country_code' => 'MY',
        'name' => 'Shah Alam',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Shah Alam',
        'status' => 'verified',
    ]);

    $institution->address()->update([
        'line1' => 'Persiaran Masjid',
        'line2' => 'Seksyen 14',
        'postcode' => '40000',
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'subdistrict_id' => (int) $subdistrict->id,
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Persiaran Masjid, Seksyen 14',
            'Shah Alam, 40000',
            'Petaling, Selangor',
        ]);
});

it('uses a public google maps embed on institution show pages instead of platform api urls', function () {
    config()->set('services.google.maps_api_key', 'test-maps-key');

    $institution = Institution::factory()->create([
        'name' => 'Masjid Shah Alam',
        'status' => 'verified',
    ]);

    $institution->address()->update([
        'line1' => 'Persiaran Masjid',
        'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=3.139%2C101.6869&query_place_id=place_123',
        'waze_url' => 'https://ul.waze.com/ul?place=ChIJ-test',
        'lat' => 3.139,
        'lng' => 101.6869,
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('https://www.google.com/maps?q=3.139%2C101.6869&amp;output=embed', false)
        ->assertSeeInOrder([
            'output=embed',
            'Waze',
            'Google Maps',
        ], false)
        ->assertDontSee('https://www.google.com/maps/embed/v1/place?key=', false)
        ->assertDontSee('https://maps.googleapis.com/maps/api/staticmap', false);
});

it('displays upcoming events for the institution', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    $upcomingEvent = Event::factory()
        ->for($institution)
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'starts_at' => now()->addDays(3),
            'title' => 'Kuliah Maghrib Akan Datang',
        ]);

    $pastEvent = Event::factory()
        ->for($institution)
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'starts_at' => now()->subDays(3),
            'title' => 'Kuliah Subuh Lalu',
        ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('Kuliah Maghrib Akan Datang')
        ->assertSee('Kuliah Subuh Lalu');
});

it('renders institution event cards with localized prayer timing stacked speaker avatars and no institution fallback location', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $originalLocale = app()->getLocale();
    app()->setLocale('en');

    $state = State::query()
        ->where('country_code', 'MY')
        ->where('name', 'Selangor')
        ->first();

    if (! $state) {
        $countryId = DB::table('countries')->insertGetId([
            'iso2' => 'MY',
            'name' => 'Malaysia',
            'status' => 1,
            'phone_code' => '60',
            'iso3' => 'MYS',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);

        $stateId = DB::table('states')->insertGetId([
            'country_id' => $countryId,
            'name' => 'Selangor',
            'country_code' => 'MY',
        ]);

        $state = State::query()->findOrFail($stateId);
    }

    $district = District::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'country_code' => 'MY',
        'name' => 'Petaling',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'country_code' => 'MY',
        'name' => 'Shah Alam',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'status' => 'verified',
    ]);

    $institution->address()->update([
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'subdistrict_id' => (int) $subdistrict->id,
    ]);

    try {
        $event = Event::factory()
            ->for($institution)
            ->create([
                'status' => 'approved',
                'visibility' => EventVisibility::Public,
                'starts_at' => now()->addDays(3)->setTime(18, 45),
                'title' => 'Kuliah Maghrib Institusi',
                'timing_mode' => TimingMode::PrayerRelative,
                'prayer_reference' => PrayerReference::Maghrib,
                'prayer_offset' => PrayerOffset::Immediately,
                'prayer_display_text' => 'Selepas Maghrib',
            ]);

        $speaker = Speaker::factory()->create([
            'status' => 'verified',
            'name' => 'Ustaz Abdullah Fahmi',
            'honorific' => null,
            'pre_nominal' => null,
            'post_nominal' => null,
        ]);
        $speaker->addMedia(UploadedFile::fake()->image('speaker-one.jpg', 320, 320))
            ->toMediaCollection('avatar');

        $secondSpeaker = Speaker::factory()->create([
            'status' => 'verified',
            'name' => 'Ustaz Ahmad Razak',
            'honorific' => null,
            'pre_nominal' => null,
            'post_nominal' => null,
        ]);
        $secondSpeaker->addMedia(UploadedFile::fake()->image('speaker-two.jpg', 320, 320))
            ->toMediaCollection('avatar');

        $moderator = Speaker::factory()->create([
            'status' => 'verified',
            'name' => 'Ustazah Mariam Yusuf',
            'honorific' => null,
            'pre_nominal' => null,
            'post_nominal' => null,
        ]);

        $event->keyPeople()->create([
            'speaker_id' => $speaker->id,
            'role' => EventKeyPersonRole::Speaker,
            'order_column' => 1,
            'is_public' => true,
        ]);

        $event->keyPeople()->create([
            'speaker_id' => $secondSpeaker->id,
            'role' => EventKeyPersonRole::Speaker,
            'order_column' => 2,
            'is_public' => true,
        ]);

        $event->keyPeople()->create([
            'speaker_id' => $moderator->id,
            'role' => EventKeyPersonRole::Moderator,
            'order_column' => 3,
            'is_public' => true,
        ]);

        $response = $this->get(route('institutions.show', $institution));
        $response->assertSuccessful();

        $html = $response->getContent();

        preg_match('/<a[^>]*wire:key="upcoming-'.preg_quote($event->id, '/').'"[^>]*>.*?<\/a>/s', (string) $html, $matches);

        $eventCard = $matches[0] ?? null;

        expect($eventCard)->not->toBeNull();
        expect($eventCard)
            ->toContain('After Maghrib')
            ->toContain('Ustaz Abdullah Fahmi')
            ->toContain('Ustaz Ahmad Razak')
            ->toContain('Moderator: Ustazah Mariam Yusuf')
            ->toContain($speaker->public_avatar_url)
            ->toContain($secondSpeaker->public_avatar_url)
            ->toContain('-space-x-3')
            ->toContain('sm:h-11 sm:w-11')
            ->not->toContain('Selepas Maghrib')
            ->not->toContain('Penceramah:')
            ->not->toContain('Shah Alam, Petaling, Selangor');
    } finally {
        app()->setLocale($originalLocale);
    }
});

it('renders the book title on institution event cards without parentheses', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'status' => 'verified',
    ]);

    $bookEvent = Event::factory()
        ->for($institution)
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'starts_at' => now()->addDays(2)->setTime(19, 30),
            'title' => 'Kuliah Maghrib Kitab',
        ]);

    $articleEvent = Event::factory()
        ->for($institution)
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'starts_at' => now()->addDays(3)->setTime(19, 30),
            'title' => 'Kuliah Maghrib Artikel',
        ]);

    $bookReference = Reference::factory()->create([
        'title' => 'Riyadhus Solihin',
        'type' => ReferenceType::Book->value,
    ]);

    $articleReference = Reference::factory()->create([
        'title' => 'Artikel Dakwah Semasa',
        'type' => ReferenceType::Article->value,
    ]);

    $bookEvent->references()->attach($bookReference->id);
    $articleEvent->references()->attach($articleReference->id);

    $response = $this->get(route('institutions.show', $institution));
    $response->assertSuccessful();

    $html = $response->getContent();

    preg_match('/<a[^>]*wire:key="upcoming-'.preg_quote($bookEvent->id, '/').'"[^>]*>.*?<\/a>/s', (string) $html, $bookMatches);
    preg_match('/<a[^>]*wire:key="upcoming-'.preg_quote($articleEvent->id, '/').'"[^>]*>.*?<\/a>/s', (string) $html, $articleMatches);

    $bookEventCard = $bookMatches[0] ?? null;
    $articleEventCard = $articleMatches[0] ?? null;

    expect($bookEventCard)->not->toBeNull();
    expect($articleEventCard)->not->toBeNull();

    expect($bookEventCard)
        ->toContain('Kuliah Maghrib Kitab')
        ->toContain('Riyadhus Solihin')
        ->not->toContain('(Riyadhus Solihin)')
        ->toContain('font-bold')
        ->toContain('italic')
        ->toContain('sm:pl-4');

    expect($articleEventCard)
        ->toContain('Kuliah Maghrib Artikel')
        ->not->toContain('Riyadhus Solihin')
        ->not->toContain('Artikel Dakwah Semasa');
});

it('renders institution event cards cleanly when an event has no speakers', function () {
    $institution = Institution::factory()->create([
        'name' => 'Akademi Tahfiz Tanpa Penceramah',
        'status' => 'verified',
    ]);

    $event = Event::factory()
        ->for($institution)
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'starts_at' => now()->addDays(4)->setTime(20, 0),
            'title' => 'Kuliah Tanpa Penceramah',
        ]);

    $response = $this->get(route('institutions.show', $institution));
    $response->assertSuccessful();

    $html = $response->getContent();

    preg_match('/<a[^>]*wire:key="upcoming-'.preg_quote($event->id, '/').'"[^>]*>.*?<\/a>/s', (string) $html, $matches);

    $eventCard = $matches[0] ?? null;

    expect($eventCard)->not->toBeNull();
    expect($eventCard)
        ->toContain('Kuliah Tanpa Penceramah')
        ->not->toContain('aria-label="Penceramah"')
        ->not->toContain('Penceramah:');
});

it('uses stronger calendar event colors on institution page', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    Event::factory()
        ->for($institution)
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'starts_at' => now()->addDays(3),
            'title' => 'Kuliah Kalender Institusi',
        ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('border-emerald-300 bg-emerald-100 text-emerald-900 shadow-emerald-200/80 hover:bg-emerald-200', false)
        ->assertDontSee('bg-emerald-50 text-emerald-700 hover:bg-emerald-100', false);
});

it('displays affiliated speakers', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'name' => 'Ustaz Ahmad bin Abdullah',
        'is_freelance' => true,
    ]);

    $institution->speakers()->attach($speaker, [
        'position' => 'Imam Besar',
        'is_primary' => true,
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('Ustaz Ahmad bin Abdullah')
        ->assertSee('Imam Besar');
});

it('displays spaces and facilities', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    $space = Space::factory()->create([
        'name' => 'Dewan Kuliah Utama',
        'capacity' => 500,
        'is_active' => true,
    ]);

    $institution->spaces()->attach($space);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('Dewan Kuliah Utama')
        ->assertSee('500');
});

it('displays donation channels', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    $institution->donationChannels()->create([
        'method' => 'bank_account',
        'bank_code' => 'BIMB',
        'bank_name' => 'Bank Islam',
        'account_number' => '123456789012',
        'recipient' => 'Tabung Masjid Al-Ikhlas',
        'label' => 'Infaq Bulanan',
        'status' => 'verified',
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('Tabung Masjid Al-Ikhlas')
        ->assertSee('Infaq Bulanan');
});

it('renders donation qr thumbnails without the rounded border shell', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create(['status' => 'verified']);

    $channel = $institution->donationChannels()->create([
        'method' => 'bank_account',
        'bank_code' => 'BIMB',
        'bank_name' => 'Bank Islam',
        'account_number' => '123456789012',
        'recipient' => 'Tabung Masjid Al-Ikhlas',
        'label' => 'Infaq Bulanan',
        'status' => 'verified',
    ]);

    $channel->addMedia(UploadedFile::fake()->image('qr.png', 300, 300))
        ->toMediaCollection('qr');

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('class="group relative shrink-0 transition-transform active:scale-95"', false)
        ->assertDontSee('rounded-xl border-2 border-gold-200/60 bg-white p-1 shadow-sm', false);
});

it('displays public contacts', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    $institution->contacts()->create([
        'category' => ContactCategory::Phone->value,
        'type' => ContactType::Work->value,
        'value' => '03-12345678',
        'is_public' => true,
    ]);

    $institution->contacts()->create([
        'category' => ContactCategory::Email->value,
        'type' => ContactType::Main->value,
        'value' => 'contact@test.com',
        'is_public' => true,
    ]);

    $institution->contacts()->create([
        'category' => ContactCategory::Email->value,
        'type' => ContactType::Main->value,
        'value' => 'private@test.com',
        'is_public' => false,
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('href="tel:0312345678"', false)
        ->assertSee('href="mailto:contact@test.com"', false)
        ->assertSee('03-12345678')
        ->assertSee('contact@test.com')
        ->assertDontSee('private@test.com');
});

it('loads more upcoming events via Livewire', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    Event::factory(8)
        ->for($institution)
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'starts_at' => now()->addDays(5),
        ]);

    Livewire::test('pages.institutions.show', ['institution' => $institution])
        ->assertSet('upcomingPerPage', 6)
        ->call('loadMoreUpcoming')
        ->assertSet('upcomingPerPage', 12);
});

it('loads more past events via Livewire', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    Event::factory(8)
        ->for($institution)
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'starts_at' => now()->subDays(5),
        ]);

    Livewire::test('pages.institutions.show', ['institution' => $institution])
        ->assertSet('pastPerPage', 6)
        ->call('loadMorePast')
        ->assertSet('pastPerPage', 12);
});

it('does not show private events', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Private,
        'starts_at' => now()->addDay(),
        'title' => 'Secret Event XYZ',
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertDontSee('Secret Event XYZ');
});

it('shows pending public events', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    Event::factory()->for($institution)->create([
        'status' => 'pending',
        'visibility' => EventVisibility::Public,
        'starts_at' => now()->addDay(),
        'title' => 'Pending Event ABC',
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('Pending Event ABC')
        ->assertSee('Menunggu Kelulusan')
        ->assertSee('Semak lencana status pada setiap majlis sebelum hadir.');
});

it('shows cancelled public events with cancelled badge', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    Event::factory()->for($institution)->create([
        'status' => 'cancelled',
        'visibility' => EventVisibility::Public,
        'starts_at' => now()->addDays(2),
        'title' => 'Cancelled Event ABC',
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('Cancelled Event ABC')
        ->assertSee('Dibatalkan');
});

it('does not show events outside approved and pending statuses', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    Event::factory()->for($institution)->create([
        'status' => 'rejected',
        'visibility' => EventVisibility::Public,
        'starts_at' => now()->addDay(),
        'title' => 'Rejected Event Hidden',
    ]);

    Event::factory()->for($institution)->create([
        'status' => 'draft',
        'visibility' => EventVisibility::Public,
        'starts_at' => now()->addDays(2),
        'title' => 'Draft Event Hidden',
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertDontSee('Rejected Event Hidden')
        ->assertDontSee('Draft Event Hidden');
});

it('allows an authenticated user to follow and unfollow an institution', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    $this->actingAs($user);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee(__('Ikuti'));

    expect($user->isFollowing($institution))->toBeFalse();

    Livewire::actingAs($user)
        ->test('pages.institutions.show', ['institution' => $institution])
        ->assertSet('isFollowing', false)
        ->call('toggleFollow')
        ->assertSet('isFollowing', true)
        ->call('toggleFollow')
        ->assertSet('isFollowing', false);

    expect($user->isFollowing($institution))->toBeFalse();
});

it('keeps institution detail sections revealed after following', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    Inspiration::factory()->locale(app()->getLocale())->create();

    Livewire::actingAs($user)
        ->test('pages.institutions.show', ['institution' => $institution])
        ->assertSee('scroll-reveal reveal-up revealed', false)
        ->assertSee('scroll-reveal reveal-right revealed', false)
        ->assertSee('scroll-reveal reveal-right revealed" x-data="{ showComicModal: false, showMediaModal: false }"', false)
        ->call('toggleFollow')
        ->assertSet('isFollowing', true)
        ->assertSee('scroll-reveal reveal-up revealed', false)
        ->assertSee('scroll-reveal reveal-right revealed', false)
        ->assertSee('scroll-reveal reveal-right revealed" x-data="{ showComicModal: false, showMediaModal: false }"', false);
});

it('redirects guest to login when trying to follow an institution', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    Livewire::test('pages.institutions.show', ['institution' => $institution])
        ->call('toggleFollow')
        ->assertRedirect(route('login', ['redirect' => route('institutions.show', $institution, absolute: false)]));
});

it('preserves the institution url in guest auth links', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'name' => 'Institusi Arah Balik',
    ]);

    $institutionUrl = route('institutions.show', $institution, absolute: false);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('href="'.route('register', ['redirect' => $institutionUrl]).'"', false)
        ->assertSee('href="'.route('login', ['redirect' => $institutionUrl]).'"', false)
        ->assertDontSee('href="'.route('register').'"', false)
        ->assertDontSee('href="'.route('login').'"', false);
});

it('does not render breadcrumb and removed hero/page summary actions', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'name' => 'Institusi Ujian',
    ]);

    Event::factory(2)
        ->for($institution)
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'starts_at' => now()->addDays(2),
        ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertDontSee('Lihat Semua Majlis')
        ->assertDontSee('2 majlis')
        ->assertDontSee('3 penceramah')
        ->assertDontSee('<nav class="animate-fade-in-up flex items-center gap-2 text-sm" style="animation-delay: 100ms; opacity: 0;">', false);
});

it('renders prayer-relative start time and event timezone end time in institution event list', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    $event = Event::factory()
        ->for($institution)
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => Carbon::parse('2026-02-18 09:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-02-18 12:40:00', 'UTC'),
            'timing_mode' => TimingMode::PrayerRelative,
            'prayer_display_text' => 'Selepas Asar',
            'title' => 'Kuliah Khas Timing',
        ]);

    $expectedEndTime = $event->ends_at?->copy()->timezone('Asia/Kuala_Lumpur')->format('h:i A');

    $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSeeText('Kuliah Khas Timing')
        ->assertSeeText('Selepas Asar')
        ->assertSeeText((string) $expectedEndTime)
        ->assertDontSeeText('12:40 PM');
});

it('hides duplicated state for kuala lumpur putrajaya and labuan in institution event location list', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $venue = Venue::factory()->create(['name' => 'Dewan Utama KL']);

    $stateId = DB::table('states')->insertGetId([
        'country_id' => 132,
        'name' => 'Kuala Lumpur',
        'country_code' => 'MY',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => 132,
        'state_id' => (int) $stateId,
        'district_id' => null,
        'country_code' => 'MY',
        'name' => 'Setiawangsa',
    ]);

    $venue->address()->update([
        'state_id' => (int) $stateId,
        'district_id' => null,
        'subdistrict_id' => $subdistrict->id,
    ]);

    Event::factory()
        ->for($institution)
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'event_format' => EventFormat::Physical,
            'venue_id' => $venue->id,
            'starts_at' => now()->addDay(),
            'title' => 'Kuliah KL',
        ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('Dewan Utama KL • Setiawangsa, Kuala Lumpur')
        ->assertDontSee('Dewan Utama KL • Negeri: - • Daerah: Kuala Lumpur • Bandar / Mukim / Zon: -')
        ->assertDontSee('Dewan Utama KL • Negeri: Kuala Lumpur • Daerah: Kuala Lumpur');
});
