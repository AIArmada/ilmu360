<?php

use AIArmada\FilamentAuthz\Models\Role;
use App\Enums\ContactCategory;
use App\Enums\ContributionSubjectType;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\ReferenceType;
use App\Models\District;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Series;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

it('loads public index pages', function () {
    $this->get(route('home'))->assertSuccessful()->assertSee('Majlis Ilmu');
    $this->get(route('events.index'))->assertSuccessful()->assertSee('Majlis Ilmu');
    $this->get(route('institutions.index'))->assertSuccessful()->assertSee('Majlis Ilmu');
    $this->get(route('speakers.index'))->assertSuccessful()->assertSee('Majlis Ilmu');
    $this->get(route('venues.index'))->assertSuccessful()->assertSee('Majlis Ilmu');
    $this->get(route('references.index'))->assertSuccessful()->assertSee('Majlis Ilmu');
    $this->get(route('submit-event.landing'))
        ->assertSuccessful()
        ->assertSee('Tambah Majlis')
        ->assertSee('Ada majlis ilmu')
        ->assertSee('yang patut orang tahu?')
        ->assertSee(route('submit-event.create'), false);
    $this->get(route('submit-event.create'))->assertSuccessful()->assertSee('Hantar Majlis');
    $this->get(route('submit-event.success'))->assertSuccessful()->assertSee(__('Event Submitted!'));

    $this->get('/events')->assertNotFound();
    $this->get('/institutions')->assertNotFound();
    $this->get('/speakers')->assertNotFound();
    $this->get('/venues')->assertNotFound();
    $this->get('/references')->assertNotFound();
    $this->get('/submit-event')->assertNotFound();
    $this->get('/submit-event/success')->assertNotFound();
});

it('respects the signals geolocation toggle in tracker markup', function () {
    config()->set('signals.features.geolocation.enabled', false);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('data-enable-geolocation="false"', false);

    config()->set('signals.features.geolocation.enabled', true);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('data-enable-geolocation="true"', false);
});

it('keeps the homepage nearby button visible', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('data-testid="near-me-button"', false);
});

it('uses homepage-like vertical spacing on the public listing pages', function () {
    collect([
        route('events.index'),
        route('institutions.index'),
        route('speakers.index'),
        route('venues.index'),
        route('references.index'),
    ])->each(function (string $url): void {
        $this->get($url)
            ->assertSuccessful()
            ->assertSee('class="relative pt-12 pb-16 bg-white border-b border-slate-100 overflow-hidden"', false)
            ->assertDontSee('class="relative pt-24 pb-16 bg-white border-b border-slate-100 overflow-hidden"', false)
            ->assertDontSee('class="relative min-h-screen pb-32"', false);
    });
});

it('renders accessible labels on the public submit-event form', function () {
    $this->get(route('submit-event.create'))
        ->assertSuccessful()
        ->assertSee('Hantar Majlis Ilmu')
        ->assertSee('Kongsi majlis ilmu dengan komuniti. Penghantaran anda akan disemak sebelum diterbitkan.')
        ->assertSee('aria-label="Fizikal"', false)
        ->assertSee('aria-label="Dalam talian"', false)
        ->assertSee('aria-label="Hibrid"', false);
});

it('renders the submit-event upload copy in the selected locale', function () {
    $this->withSession(['locale' => 'en'])
        ->get(route('submit-event.create'))
        ->assertSuccessful()
        ->assertSee('Submit Knowledge Event')
        ->assertSee('Share your knowledge event with the community. Your submission will be reviewed before it is published.')
        ->assertDontSee('Hantar Majlis Ilmu')
        ->assertDontSee('Kongsi majlis ilmu dengan komuniti. Penghantaran anda akan disemak sebelum diterbitkan.');
});

it('does not expose experimental AI homepage variants', function () {
    $this->get('/glm')->assertNotFound();
    $this->get('/kimi')->assertNotFound();
});

it('loads public detail pages', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $institution = Institution::factory()->create(['status' => 'verified']);
    $speaker = Speaker::factory()->create(['status' => 'verified']);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $series = Series::factory()->create([
        'visibility' => 'public',
    ]);
    $series->events()->attach($event->id, [
        'id' => (string) Str::uuid(),
        'order_column' => 1,
    ]);

    $this->get(route('events.show', $event))->assertSuccessful()->assertSee($event->title);
    $this->get(route('institutions.show', $institution))->assertSuccessful()->assertSee($institution->name);
    $this->get(route('speakers.show', $speaker))->assertSuccessful()->assertSee($speaker->name);
    $this->get(route('venues.show', $venue))->assertSuccessful()->assertSee($venue->name);
    $this->get(route('series.show', $series))
        ->assertSuccessful()
        ->assertSee($series->title)
        ->assertSee($event->title);
});

it('renders public event poster containers using the poster aspect ratio', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $portraitEvent = Event::factory()->create([
        'title' => 'Poster Portrait Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
        'event_format' => EventFormat::Physical->value,
        'institution_id' => $institution->id,
    ]);
    $portraitEvent->addMedia(UploadedFile::fake()->image('portrait-poster.jpg', 800, 1200))
        ->toMediaCollection('poster');

    $wideEvent = Event::factory()->create([
        'title' => 'Poster Wide Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(2),
        'event_format' => EventFormat::Physical->value,
        'institution_id' => $institution->id,
    ]);
    $wideEvent->addMedia(UploadedFile::fake()->image('wide-poster.jpg', 1600, 900))
        ->toMediaCollection('poster');

    $this->get(route('events.index'))
        ->assertSuccessful()
        ->assertSee('data-cover-aspect="16:9"', false);

    // Detail page still shows the real poster aspect ratio
    $this->get(route('events.show', $wideEvent))
        ->assertSuccessful()
        ->assertSee('data-poster-aspect="16:9"', false);

    $this->get(route('events.show', $portraitEvent))
        ->assertSuccessful()
        ->assertSee('data-poster-aspect="4:5"', false);
});

it('uses a 16:9 placeholder aspect ratio for public events index cards without posters', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    Event::factory()->create([
        'title' => 'Majlis Tanpa Poster',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
        'event_format' => EventFormat::Physical->value,
        'institution_id' => $institution->id,
        'is_active' => true,
    ]);

    $this->get(route('events.index', ['search' => 'Tanpa Poster']))
        ->assertSuccessful()
        ->assertSee('Majlis Tanpa Poster')
        ->assertSee('data-cover-aspect="16:9"', false);
});

it('uses the real speaker avatar in public speaker share metadata and preview', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    $speaker->addMedia(UploadedFile::fake()->image('speaker-avatar.jpg', 1200, 1200))
        ->toMediaCollection('avatar');

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee('<meta property="og:image" content="'.$speaker->public_avatar_url.'">', false)
        ->assertSee('<meta name="twitter:image" content="'.$speaker->public_avatar_url.'">', false)
        ->assertSee('src="'.$speaker->public_avatar_url.'"', false);
});

it('shows share actions on public series and reference pages', function () {
    $series = Series::factory()->create([
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $reference = Reference::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    $this->get(route('series.show', $series))
        ->assertSuccessful()
        ->assertSee('Kongsi')
        ->assertSee('Kongsi Siri');

    $this->get(route('references.show', $reference))
        ->assertSuccessful()
        ->assertSee('Kongsi')
        ->assertSee('Kongsi Rujukan');
});

it('shows federal territory event cards on series pages with subdistrict and state', function () {
    $series = Series::factory()->create([
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $venue = Venue::factory()->create([
        'name' => 'Dewan Utama KL',
    ]);

    $state = State::query()->create([
        'country_id' => 132,
        'name' => 'Kuala Lumpur',
        'country_code' => 'MY',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => 132,
        'state_id' => (int) $state->id,
        'district_id' => null,
        'country_code' => 'MY',
        'name' => 'Setiawangsa',
    ]);

    $venue->address()->update([
        'state_id' => (int) $state->id,
        'district_id' => null,
        'subdistrict_id' => (int) $subdistrict->id,
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'starts_at' => now()->addDay(),
        'event_format' => EventFormat::Physical,
        'venue_id' => $venue->id,
    ]);

    $series->events()->attach($event->id, [
        'id' => (string) Str::uuid(),
        'order_column' => 1,
    ]);

    $this->get(route('series.show', $series))
        ->assertSuccessful()
        ->assertSee('Dewan Utama KL, Setiawangsa, Kuala Lumpur')
        ->assertDontSee('Dewan Utama KL, Kuala Lumpur, Kuala Lumpur');
});

it('uses a 16:9 placeholder aspect ratio in the shared series event card partial without posters', function () {
    $event = Event::factory()->create([
        'title' => 'Kuliah Siri Tanpa Poster',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'starts_at' => now()->addDay(),
        'event_format' => EventFormat::Physical,
        'is_active' => true,
    ]);

    $html = view('components.pages.series._event-card', [
        'event' => $event,
        'past' => false,
    ])->render();

    expect($html)
        ->toContain('data-cover-aspect="16:9"')
        ->toContain('aspect-[16/9]');
});

it('shows comma-separated location hierarchy text on public events index cards', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahudin Abdul Aziz Shah',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $state = State::query()->create([
        'country_id' => 132,
        'name' => 'Selangor',
        'country_code' => 'MY',
    ]);

    $district = District::query()->create([
        'country_id' => 132,
        'state_id' => (int) $state->id,
        'country_code' => 'MY',
        'name' => 'Petaling',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => 132,
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'country_code' => 'MY',
        'name' => 'Shah Alam',
    ]);

    $institution->address()->update([
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'subdistrict_id' => (int) $subdistrict->id,
    ]);

    Event::factory()->create([
        'title' => 'Diskusi Dhuha Al-Quran',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'starts_at' => now()->addDay(),
        'event_format' => EventFormat::Physical,
        'institution_id' => $institution->id,
        'is_active' => true,
    ]);

    $this->get(route('events.index', ['search' => 'Diskusi Dhuha']))
        ->assertSuccessful()
        ->assertSee('Shah Alam, Petaling, Selangor')
        ->assertDontSee('Shah Alam, Petaling &amp; Selangor', false)
        ->assertDontSee('Shah Alam, Petaling & Selangor');
});

it('renders the date and event-type badges below the poster on public events index cards', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahudin Abdul Aziz Shah',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Event::factory()->create([
        'title' => 'Diskusi Dhuha',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'starts_at' => now()->addDay(),
        'event_format' => EventFormat::Physical,
        'institution_id' => $institution->id,
        'is_active' => true,
    ]);

    $this->get(route('events.index', ['search' => 'Diskusi Dhuha']))
        ->assertSuccessful()
        ->assertSee('data-testid="event-card-badge-row"', false)
        ->assertSee('data-testid="event-card-date-badge"', false)
        ->assertSee('data-testid="event-card-type-badge"', false)
        ->assertSeeInOrder([
            'data-cover-aspect=',
            'data-testid="event-card-badge-row"',
            'data-testid="event-card-title-link"',
        ], false);
});

it('renders the book title on public event and series cards without parentheses', function () {
    $series = Series::factory()->create([
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $bookEvent = Event::factory()->create([
        'title' => 'Kuliah Indeks Kitab',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'starts_at' => now()->addDay(),
        'event_format' => EventFormat::Physical,
        'is_active' => true,
    ]);

    $articleEvent = Event::factory()->create([
        'title' => 'Kuliah Indeks Artikel',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'starts_at' => now()->addDays(2),
        'event_format' => EventFormat::Physical,
        'is_active' => true,
    ]);

    $bookReference = Reference::factory()->create([
        'title' => 'Bulugh al-Maram',
        'type' => ReferenceType::Book->value,
    ]);

    $articleReference = Reference::factory()->create([
        'title' => 'Artikel Semasa',
        'type' => ReferenceType::Article->value,
    ]);

    $bookEvent->references()->attach($bookReference->id);
    $articleEvent->references()->attach($articleReference->id);

    $series->events()->attach($bookEvent->id, [
        'id' => (string) Str::uuid(),
        'order_column' => 1,
    ]);

    $series->events()->attach($articleEvent->id, [
        'id' => (string) Str::uuid(),
        'order_column' => 2,
    ]);

    $eventsIndexHtml = $this->get(route('events.index', ['search' => 'Kuliah Indeks']))
        ->assertSuccessful()
        ->getContent();

    $seriesPageHtml = $this->get(route('series.show', $series))
        ->assertSuccessful()
        ->getContent();

    expect($eventsIndexHtml)
        ->toContain('Kuliah Indeks Kitab')
        ->toContain('Kuliah Indeks Artikel')
        ->toContain('Bulugh al-Maram')
        ->not->toContain('(Bulugh al-Maram)')
        ->and(substr_count((string) $eventsIndexHtml, 'Bulugh al-Maram'))->toBe(1);

    expect($seriesPageHtml)
        ->toContain('Kuliah Indeks Kitab')
        ->toContain('Kuliah Indeks Artikel')
        ->toContain('Bulugh al-Maram')
        ->not->toContain('(Bulugh al-Maram)')
        ->and(substr_count((string) $seriesPageHtml, 'Bulugh al-Maram'))->toBe(1);
});

it('renders threads in public share modals instead of line', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $institution = Institution::factory()->create(['status' => 'verified']);
    $speaker = Speaker::factory()->create(['status' => 'verified']);
    $series = Series::factory()->create([
        'visibility' => 'public',
        'is_active' => true,
    ]);
    $reference = Reference::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    collect([
        $this->get(route('events.show', $event)),
        $this->get(route('institutions.show', $institution)),
        $this->get(route('speakers.show', $speaker)),
        $this->get(route('series.show', $series)),
        $this->get(route('references.show', $reference)),
    ])->each(function ($response): void {
        $response->assertSuccessful()
            ->assertSee('storage/social-media-icons/threads.svg', false)
            ->assertSee('title="Threads"', false)
            ->assertDontSee('storage/social-media-icons/line.svg', false)
            ->assertDontSee('title="LINE"', false);
    });
});

it('does not leak share tracking javascript into public page body text', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $institution = Institution::factory()->create(['status' => 'verified']);
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    collect([
        $this->get(route('events.show', $event)),
        $this->get(route('institutions.show', $institution)),
        $this->get(route('speakers.show', $speaker)),
    ])->each(function ($response): void {
        $response->assertSuccessful();

        $visibleText = strip_tags((string) $response->getContent());

        expect($visibleText)
            ->not->toContain('trackShare(')
            ->not->toContain('copy_link')
            ->not->toContain('native_share');
    });
});

it('renders speaker contribution links with penceramah route segments', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    $speakerRouteSegment = ContributionSubjectType::Speaker->publicRouteSegment();

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee("/sumbangan/{$speakerRouteSegment}/{$speaker->slug}/kemas-kini", false)
        ->assertSee("/lapor/{$speakerRouteSegment}/{$speaker->slug}", false);
});

it('renders institution contribution links with institusi route segments', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    $institutionRouteSegment = ContributionSubjectType::Institution->publicRouteSegment();

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee("/sumbangan/{$institutionRouteSegment}/{$institution->slug}/kemas-kini", false)
        ->assertSee("/lapor/{$institutionRouteSegment}/{$institution->slug}", false);
});

it('renders reference contribution links with rujukan route segments', function () {
    $reference = Reference::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    $referenceRouteSegment = ContributionSubjectType::Reference->publicRouteSegment();

    $this->get(route('references.show', $reference))
        ->assertSuccessful()
        ->assertSee("/sumbangan/{$referenceRouteSegment}/{$reference->slug}/kemas-kini", false)
        ->assertSee("/lapor/{$referenceRouteSegment}/{$reference->slug}", false);
});

it('renders event contribution links with majlis route segments', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $eventRouteSegment = ContributionSubjectType::Event->publicRouteSegment();

    $this->get(route('events.show', $event))
        ->assertSuccessful()
        ->assertSee("/sumbangan/{$eventRouteSegment}/{$event->slug}/kemas-kini", false)
        ->assertSee("/lapor/{$eventRouteSegment}/{$event->slug}", false);
});

it('renders noindex robots metadata for moderation-only or non-public detail pages', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findOrCreate('moderator', 'web');

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $pendingInstitution = Institution::factory()->create([
        'status' => 'pending',
        'is_active' => true,
    ]);

    $pendingSpeaker = Speaker::factory()->create([
        'status' => 'pending',
        'is_active' => true,
    ]);

    $privateSeries = Series::factory()->create([
        'visibility' => 'private',
        'is_active' => true,
    ]);

    $pendingReference = Reference::factory()->create([
        'status' => 'pending',
        'is_active' => true,
    ]);

    $this->actingAs($moderator);

    $this->get(route('institutions.show', $pendingInstitution))
        ->assertSuccessful()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

    $this->get(route('speakers.show', $pendingSpeaker))
        ->assertSuccessful()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

    $this->get(route('series.show', $privateSeries))
        ->assertSuccessful()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

    $this->get(route('references.show', $pendingReference))
        ->assertSuccessful()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('renders optimized seo metadata on public listing pages', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('<title>Majlis Ilmu - Cari Kuliah &amp; Majlis Ilmu di Malaysia</title>', false)
        ->assertSee('<meta name="description" content="Platform terbesar untuk mencari kuliah, ceramah, tazkirah, dan majlis ilmu di seluruh Malaysia. Cari yang berdekatan dengan anda.">', false)
        ->assertSee('<meta property="og:image" content="'.asset('images/default-mosque-hero.png').'">', false)
        ->assertSee('<meta property="og:image:width" content="1024">', false)
        ->assertSee('<meta property="og:image:height" content="1024">', false);

    $this->get(route('events.index'))
        ->assertSuccessful()
        ->assertSee('<title>Kuliah &amp; Majlis Ilmu Akan Datang di Malaysia - Majlis Ilmu</title>', false)
        ->assertSee('Terokai kuliah, ceramah, kelas, dan majlis ilmu akan datang di seluruh Malaysia.', false);

    $this->get(route('institutions.index'))
        ->assertSuccessful()
        ->assertSee('<title>Direktori Institusi Islam di Malaysia - Majlis Ilmu</title>', false)
        ->assertSee('Terokai masjid, surau, pusat pengajian, dan institusi penganjur majlis ilmu di seluruh Malaysia.', false);

    $this->get(route('speakers.index'))
        ->assertSuccessful()
        ->assertSee('<title>Direktori Penceramah Islam - Majlis Ilmu</title>', false)
        ->assertSee('Cari profil penceramah, ustaz, dan pendakwah serta semak majlis ilmu mereka yang akan datang di seluruh Malaysia.', false);
});

it('renders optimized seo metadata on public detail pages', function () {
    $event = Event::factory()->create([
        'title' => 'Kuliah Fiqh Munakahat',
        'description' => 'Kupasan fiqh munakahat untuk keluarga Muslim, termasuk panduan asas, adab, dan soal jawab bersama penceramah jemputan.',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al-Hidayah Taman Melawati',
        'description' => 'Pusat komuniti Islam yang aktif menganjurkan kuliah, kelas, dan program ilmu untuk masyarakat setempat.',
        'status' => 'verified',
    ]);

    $speaker = Speaker::factory()->create([
        'name' => 'Ahmad Fauzi',
        'honorific' => null,
        'pre_nominal' => null,
        'post_nominal' => null,
        'bio' => [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Penceramah yang aktif mengendalikan kuliah aqidah, tafsir, dan pembinaan keluarga di seluruh negara.',
                ]],
            ]],
        ],
        'status' => 'verified',
        'is_active' => true,
    ]);

    $series = Series::factory()->create([
        'title' => 'Siri Tafsir Juz Amma',
        'description' => 'Siri pengajian berkala yang menghimpunkan tadabbur ayat-ayat pilihan daripada Juz Amma untuk masyarakat umum.',
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $reference = Reference::factory()->create([
        'title' => 'Riyadus Salihin Edisi Syarah',
        'description' => 'Rujukan hadis dan adab yang sering digunakan dalam kuliah pengajian umum serta sesi pembelajaran mingguan.',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $this->get(route('events.show', $event))
        ->assertSuccessful()
        ->assertSee('<title>Kuliah Fiqh Munakahat - Majlis Ilmu</title>', false)
        ->assertSee(Str::limit($event->description_text, 160), false);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('<title>Masjid Al-Hidayah Taman Melawati - Majlis Ilmu</title>', false)
        ->assertSee('Pusat komuniti Islam yang aktif menganjurkan kuliah, kelas, dan program ilmu untuk masyarakat setempat.', false)
        ->assertSee('<meta property="og:image" content="'.asset('images/placeholders/institution.png').'">', false)
        ->assertSee('<meta property="og:image:alt" content="Profil institusi Masjid Al-Hidayah Taman Melawati">', false);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee('<title>'.$speaker->formatted_name.' - Majlis Ilmu</title>', false)
        ->assertSee('Penceramah yang aktif mengendalikan kuliah aqidah, tafsir, dan pembinaan keluarga di seluruh negara.', false);

    $this->get(route('series.show', $series))
        ->assertSuccessful()
        ->assertSee('<title>Siri Tafsir Juz Amma - Majlis Ilmu</title>', false)
        ->assertSee('Siri pengajian berkala yang menghimpunkan tadabbur ayat-ayat pilihan daripada Juz Amma untuk masyarakat umum.', false);

    $this->get(route('references.show', $reference))
        ->assertSuccessful()
        ->assertSee('<title>Riyadus Salihin Edisi Syarah - Majlis Ilmu</title>', false)
        ->assertSee('Rujukan hadis dan adab yang sering digunakan dalam kuliah pengajian umum serta sesi pembelajaran mingguan.', false);
});

it('loads institution detail page with upcoming event type enum collection', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $eventType = EventType::KuliahCeramah;
    $event = Event::factory()
        ->for($institution)
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'starts_at' => now()->addDay(),
            'event_type' => [$eventType],
            'title' => 'Institution Upcoming Event',
        ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee($institution->name)
        ->assertSee($event->title)
        ->assertSee($eventType->getLabel());
});

it('hides unverified speakers and institutions from public pages', function () {
    $institution = Institution::factory()->create(['status' => 'pending']);
    $speaker = Speaker::factory()->create(['status' => 'pending']);

    $this->get(route('institutions.show', $institution))->assertNotFound();
    $this->get(route('speakers.show', $speaker))->assertNotFound();
});

it('updates submit event age group without error', function () {
    Livewire::test('pages.submit-event.create')
        ->set('data.age_group', [EventAgeGroup::Children->value])
        ->assertSet('data.age_group', [EventAgeGroup::Children->value]);
});

it('records guest submissions without a submitter id', function () {
    $title = 'Guest Submission '.uniqid();
    $email = 'guest@example.com';

    $domainTag = Tag::factory()->domain()->create();
    $disciplineTag = Tag::factory()->discipline()->create();
    $speaker = Speaker::factory()->create(['status' => 'verified']);
    $institution = Institution::factory()->create(['status' => 'verified']);
    Livewire::test('pages.submit-event.create')
        ->set('data.title', $title)
        ->set('data.description', 'Test event description')
        ->set('data.event_date', now()->addDay()->toDateString())
        ->set('data.prayer_time', EventPrayerTime::SelepasMaghrib->value)
        ->set('data.event_type', [EventType::KuliahCeramah->value])
        ->set('data.gender', EventGenderRestriction::All->value)
        ->set('data.age_group', [EventAgeGroup::AllAges->value])
        ->set('data.domain_tags', [$domainTag->id])
        ->set('data.discipline_tags', [$disciplineTag->id])
        ->set('data.speakers', [$speaker->id])
        ->set('data.organizer_type', 'institution')
        ->set('data.organizer_institution_id', $institution->id)
        ->set('data.submitter_name', 'Guest User')
        ->set('data.submitter_email', $email)
        ->set('data.visibility', EventVisibility::Public->value)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', $title)->first();

    expect($event)->not->toBeNull();
    expect($event?->submitter_id)->toBeNull();

    $submission = EventSubmission::query()->where('event_id', $event->id)->first();

    expect($submission)->not->toBeNull();
    expect($submission->submitted_by)->toBeNull();
    expect($submission->contacts()->where('category', ContactCategory::Email->value)->where('value', $email)->exists())->toBeTrue();
});
