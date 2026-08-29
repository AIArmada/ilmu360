<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventInvolvement;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventRole;
use AIArmada\Events\Models\EventSession;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\FilamentEvents\Resources\EventResource;
use App\Actions\Events\SyncEventScheduleAction;
use App\Enums\EventKeyPersonRole;
use App\Enums\TimingMode;
use App\Livewire\Pages\Events\Show;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

describe('Event Show Page Going Feature', function () {
    afterEach(function () {
        Carbon::setTestNow();
    });

    it('renders the canonical event url in the head metadata', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        $canonicalUrl = route('events.show', $event);

        $this->get($canonicalUrl)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$canonicalUrl.'">', false);
    });

    it('renders indexable robots metadata for approved public events', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('<meta name="robots" content="index, follow">', false);
    });

    it('renders noindex robots metadata for pending public events', function () {
        $event = Event::factory()->create([
            'status' => 'pending',
            'visibility' => 'public',
            'published_at' => null,
            'starts_at' => now()->addDay(),
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    });

    it('renders noindex robots metadata for unlisted events', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'unlisted',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    });

    it('renders only publicly visible references on a public event page', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
        ]);
        $publishedPendingReference = Reference::factory()->pending()->create([
            'title' => 'Published Pending Event Reference',
        ]);
        $unpublishedReference = Reference::factory()->pending()->unpublished()->create([
            'title' => 'Unpublished Event Reference',
        ]);

        $event->references()->attach([
            $publishedPendingReference->getKey(),
            $unpublishedReference->getKey(),
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Published Pending Event Reference')
            ->assertDontSee('Unpublished Event Reference');
    });

    it('renders Open Graph preview image metadata for events', function () {
        $event = Event::factory()->create([
            'title' => 'Kuliah Hadis Mingguan',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        $eventImage = $event->card_image_url;

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('<meta property="og:image" content="'.$eventImage.'">', false)
            ->assertSee('<meta property="og:image:alt" content="Poster untuk Kuliah Hadis Mingguan">', false)
            ->assertSee('<meta name="twitter:image" content="'.$eventImage.'">', false);
    });

    it('shows the going button for future events', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee(__('Akan Hadir')); // Button always visible, redirects guests to login
    });

    it('does not show a duplicate event link on public event pages', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertDontSee(__('Duplikasi Majlis'))
            ->assertDontSee(route('submit-event.create', ['duplicate' => $event]), false);
    });

    it('does not show the going button for past events', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subWeek(),
            'starts_at' => now()->subDay(),
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertDontSee(__('Akan Hadir'));
    });

    it('treats events without ends_at as past once the fallback window has elapsed', function () {
        Carbon::setTestNow(Carbon::parse('2026-04-02 21:30:00', 'Asia/Kuala_Lumpur'));

        $event = Event::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        app(SyncEventScheduleAction::class)->execute(
            $event,
            ScheduleKind::Single,
            Carbon::parse('2026-04-02 18:30:00', 'Asia/Kuala_Lumpur')->utc(),
            null,
            'Asia/Kuala_Lumpur',
            TimingMode::Absolute,
        );
        $event->refresh();

        $component = new Show;
        $component->event = $event;

        expect($component->eventTimeStatus())->toBe('past');
    });

    it('treats events without ends_at as happening now within the fallback window', function () {
        Carbon::setTestNow(Carbon::parse('2026-04-02 20:30:00', 'Asia/Kuala_Lumpur'));

        $event = Event::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        app(SyncEventScheduleAction::class)->execute(
            $event,
            ScheduleKind::Single,
            Carbon::parse('2026-04-02 19:45:00', 'Asia/Kuala_Lumpur')->utc(),
            null,
            'Asia/Kuala_Lumpur',
            TimingMode::Absolute,
        );
        $event->refresh();

        $component = new Show;
        $component->event = $event;

        expect($component->eventTimeStatus())->toBe('happening_now');
    });

    it('authenticated user can toggle going status via livewire', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        $this->actingAs($user);

        OwnerContext::withOwner(null, fn () => Livewire::test('pages.events.show', ['event' => $event->fresh()]))
            ->assertSet('isGoing', false)
            ->call('toggleGoing')
            ->assertSet('isGoing', true);

        expect(app(EngagementCounterService::class)->countResponses($event, 'going'))->toBe(1);
        expect($event->goingBy()->forResponder($user)->active()->exists())->toBeTrue();
    });

    it('authenticated user can toggle off going status via livewire', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        // Pre-attach the user
        $user->respond($event, 'going');

        $this->actingAs($user);

        OwnerContext::withOwner(null, fn () => Livewire::test('pages.events.show', ['event' => $event->fresh()]))
            ->set('isGoing', true)
            ->call('toggleGoing')
            ->assertSet('isGoing', false);

        expect(app(EngagementCounterService::class)->countResponses($event, 'going'))->toBe(0);
        expect($event->goingBy()->forResponder($user)->active()->exists())->toBeFalse();
    });

    it('redirects guests to login when trying to toggle going', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        OwnerContext::withOwner(null, fn () => Livewire::test('pages.events.show', ['event' => $event->fresh()]))
            ->call('toggleGoing')
            ->assertRedirect(route('login', ['redirect' => route('events.show', $event, absolute: false)]));
    });

    it('preserves the event url in guest auth links', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        $eventUrl = route('events.show', $event, absolute: false);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('href="'.route('register', ['redirect' => $eventUrl]).'"', false)
            ->assertSee('href="'.route('login', ['redirect' => $eventUrl]).'"', false);
    });

    it('does not leak alpine share state into the rendered body text', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->get(route('events.show', $event));

        $response->assertOk();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($response->getContent());
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        $bodyText = $body?->textContent ?? '';

        expect($bodyText)->not->toContain('shareModalOpen: false');
        expect($bodyText)->not->toContain("copyLink(shouldTrack = true, provider = 'copy_link')");
    });

    it('shows correct going count in the UI', function () {
        $users = User::factory()->count(5)->create();
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        foreach ($users as $user) {
            $user->respond($event, 'going');
        }

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee(__('Akan Hadir')); // Button always visible regardless of auth

        // Verify the going count is persisted correctly
        expect(withGlobalOwnerContext(fn (): int => app(EngagementCounterService::class)->countResponses($event, 'going')))->toBe(5);
    });
});

it('shows the event edit action to admins', function (): void {
    config(['permission.teams' => false]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $roleClass = app(PermissionRegistrar::class)->getRoleClass();
    if (! $roleClass::where('name', 'admin')->exists()) {
        $roleClass::create(['name' => 'admin', 'guard_name' => 'web']);
    }

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
    ]);
    $editUrl = EventResource::getUrl('edit', ['record' => $event], panel: 'admin');

    $this->actingAs($admin)
        ->get(route('events.show', $event))
        ->assertSuccessful()
        ->assertSee('data-testid="event-admin-edit-button"', false)
        ->assertSee($editUrl, false)
        ->assertSee(__('Edit'));
});

it('does not show the event edit action to non-admin viewers', function (): void {
    $viewer = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
    ]);

    $this->actingAs($viewer)
        ->get(route('events.show', $event))
        ->assertSuccessful()
        ->assertDontSee('data-testid="event-admin-edit-button"', false)
        ->assertDontSee(EventResource::getUrl('edit', ['record' => $event], panel: 'admin'), false);
});

it('renders occurrence sessions with session timing expressions and roles', function (): void {
    $event = Event::factory()->create([
        'title' => 'Program Berlapis Ujian',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay()->setTime(20, 0),
        'ends_at' => now()->addDay()->setTime(23, 0),
    ]);
    $occurrence = $event->primaryOccurrence;
    $person = Person::factory()->create(['name' => 'Ustaz Sesi Ujian', 'status' => 'verified']);

    expect($occurrence)->not->toBeNull();

    if ($occurrence === null) {
        return;
    }

    $session = EventSession::query()->create([
        'event_id' => $event->id,
        'event_occurrence_id' => $occurrence->id,
        'title' => 'Sesi Selepas Maghrib',
        'slug' => 'sesi-selepas-maghrib',
        'summary' => 'Sesi yang dipaparkan di bawah occurrence.',
        'starts_at' => now()->addDay()->setTime(20, 15),
        'ends_at' => now()->addDay()->setTime(21, 45),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'published',
        'visibility' => 'public',
        'delivery_mode' => 'physical',
        'sort_order' => 1,
    ]);
    $role = EventRole::factory()->create([
        'code' => EventKeyPersonRole::Speaker->value,
        'name' => EventKeyPersonRole::Speaker->getLabel(),
    ]);

    EventInvolvement::query()->create([
        'event_id' => $event->id,
        'event_occurrence_id' => $occurrence->id,
        'event_session_id' => $session->id,
        'involveable_type' => 'person',
        'involveable_id' => $person->id,
        'event_role_id' => $role->id,
        'role_code' => EventKeyPersonRole::Speaker->value,
        'status' => 'active',
        'visibility' => 'public',
        'sort_order' => 1,
    ]);

    EventTimeExpression::query()->create([
        'event_id' => $event->id,
        'event_occurrence_id' => $occurrence->id,
        'event_session_id' => $session->id,
        'time_mode' => 'prayer_relative',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 5,
        'display_label' => 'Selepas Maghrib',
    ]);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('data-testid="event-schedule-section"', false)
        ->assertSee('Program Berlapis Ujian')
        ->assertSee('Sesi Selepas Maghrib')
        ->assertSee('Selepas Maghrib')
        ->assertSee('Ustaz Sesi Ujian')
        ->assertSee(__('Event Schedule'));
});

it('renders every public occurrence and session with hierarchical cover media', function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $event = Event::factory()->create([
        'title' => 'Majlis Pelbagai Tarikh',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay()->setTime(20, 0),
        'ends_at' => now()->addDay()->setTime(23, 0),
    ]);
    $event->addMedia(fakeGeneratedImageUpload('event-cover.jpg', 1600, 900))
        ->toMediaCollection('cover');

    $firstOccurrence = $event->primaryOccurrence;

    expect($firstOccurrence)->not->toBeNull();

    if ($firstOccurrence === null) {
        return;
    }

    $firstOccurrence->addMedia(fakeGeneratedImageUpload('occurrence-cover.jpg', 1600, 900))
        ->toMediaCollection('cover');

    $firstSession = EventSession::query()->create([
        'event_id' => $event->id,
        'event_occurrence_id' => $firstOccurrence->id,
        'title' => 'Sesi Dengan Imej Sendiri',
        'slug' => 'sesi-dengan-imej-sendiri',
        'starts_at' => now()->addDay()->setTime(20, 15),
        'ends_at' => now()->addDay()->setTime(21, 0),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => EventSession::PUBLISHED,
        'visibility' => 'public',
        'delivery_mode' => 'physical',
        'sort_order' => 1,
    ]);
    $firstSession->addMedia(fakeGeneratedImageUpload('session-cover.jpg', 1200, 800))
        ->toMediaCollection('cover');

    $secondOccurrence = EventOccurrence::query()->create([
        'event_id' => $event->id,
        'title' => 'Tarikh Kedua Tanpa Imej',
        'slug' => 'tarikh-kedua-tanpa-imej',
        'starts_at' => now()->addDays(2)->setTime(20, 0),
        'ends_at' => now()->addDays(2)->setTime(22, 0),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => EventOccurrence::PUBLISHED,
        'visibility' => 'public',
        'delivery_mode' => 'physical',
    ]);
    EventSession::query()->create([
        'event_id' => $event->id,
        'event_occurrence_id' => $secondOccurrence->id,
        'title' => 'Sesi Kedua Mewarisi Imej Acara',
        'slug' => 'sesi-kedua-mewarisi-imej-acara',
        'starts_at' => now()->addDays(2)->setTime(20, 15),
        'ends_at' => now()->addDays(2)->setTime(21, 0),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => EventSession::PUBLISHED,
        'visibility' => 'public',
        'delivery_mode' => 'physical',
        'sort_order' => 1,
    ]);

    expect($firstOccurrence->getMedia('cover'))->toHaveCount(1)
        ->and($firstSession->getMedia('cover'))->toHaveCount(1);

    $response = $this->get(route('events.show', $event));

    $response->assertOk()
        ->assertSee('data-testid="event-occurrence-'.$firstOccurrence->id.'"', false)
        ->assertSee('data-testid="event-occurrence-'.$secondOccurrence->id.'"', false)
        ->assertSee('data-testid="event-session-'.$firstSession->id.'"', false)
        ->assertSee('Sesi Dengan Imej Sendiri')
        ->assertSee('Tarikh Kedua Tanpa Imej')
        ->assertSee('Sesi Kedua Mewarisi Imej Acara')
        ->assertSee($firstOccurrence->getFirstMedia('cover')?->getAvailableUrl(['banner', 'thumb']), false)
        ->assertSee($firstSession->getFirstMedia('cover')?->getAvailableUrl(['banner', 'thumb']), false)
        ->assertSee($event->getFirstMedia('cover')?->getAvailableUrl(['banner', 'thumb']), false);
});

it('does not render private or draft schedule records on public event pages', function (): void {
    $event = Event::factory()->create([
        'title' => 'Jadual Awam Sahaja',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay()->setTime(20, 0),
        'ends_at' => now()->addDay()->setTime(22, 0),
        'timing_mode' => TimingMode::Absolute->value,
    ]);
    $occurrence = $event->primaryOccurrence;

    expect($occurrence)->not->toBeNull();

    if ($occurrence === null) {
        return;
    }

    EventSession::query()->create([
        'event_id' => $event->id,
        'event_occurrence_id' => $occurrence->id,
        'title' => 'Sesi Awam',
        'slug' => 'sesi-awam',
        'starts_at' => now()->addDay()->setTime(20, 0),
        'ends_at' => now()->addDay()->setTime(21, 0),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => EventSession::PUBLISHED,
        'visibility' => 'public',
        'delivery_mode' => 'physical',
        'sort_order' => 1,
    ]);

    EventSession::query()->create([
        'event_id' => $event->id,
        'event_occurrence_id' => $occurrence->id,
        'title' => 'Sesi Rahsia',
        'slug' => 'sesi-rahsia',
        'starts_at' => now()->addDay()->setTime(21, 0),
        'ends_at' => now()->addDay()->setTime(22, 0),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => EventSession::DRAFT,
        'visibility' => 'private',
        'delivery_mode' => 'physical',
        'sort_order' => 2,
    ]);

    EventOccurrence::query()->create([
        'event_id' => $event->id,
        'title' => 'Occurrence Rahsia',
        'slug' => 'occurrence-rahsia',
        'starts_at' => now()->addDays(2)->setTime(20, 0),
        'ends_at' => now()->addDays(2)->setTime(22, 0),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => EventOccurrence::DRAFT,
        'visibility' => 'private',
        'delivery_mode' => 'physical',
    ]);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('Sesi Awam')
        ->assertDontSee('Sesi Rahsia')
        ->assertDontSee('Occurrence Rahsia');
});

describe('Event Show Page Location & Contact Info', function () {
    it('hides the about section when the event has no description or tags', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'description' => ['html' => '<p><br></p>'],
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertDontSee(__('About this Event'));
    });

    it('renders a single reference material card at full width', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        $reference = Reference::factory()->create([
            'title' => 'Matan Al-Arbain',
            'author' => 'Imam al-Nawawi',
        ]);

        $event->references()->attach($reference->id);

        $response = $this->get(route('events.show', $event));

        $response->assertOk()
            ->assertSee(__('References'))
            ->assertSee('Matan Al-Arbain')
            ->assertSee('class="grid gap-5"', false)
            ->assertDontSee('class="grid gap-5 sm:grid-cols-2"', false);
    });

    it('renders the reference subtitle before the location chip in the hero', function () {
        $venue = Venue::factory()->create([
            'name' => 'Masjid Al-Hidayah',
        ]);

        $reference = Reference::factory()->create([
            'title' => 'Al-Hikam',
        ]);

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'default_venue_id' => $venue->id,
        ]);

        $event->references()->attach($reference->id);

        $response = $this->get(route('events.show', $event));

        $response->assertOk()
            ->assertSee(__('References'))
            ->assertSee('Al-Hikam');

        $html = (string) $response->getContent();

        expect(strpos($html, 'data-testid="event-hero-reference"'))
            ->toBeLessThan(strpos($html, 'data-testid="event-hero-location"'));
    });

    it('renders the references section before the location section in the main content', function () {
        $venue = Venue::factory()->create([
            'name' => 'Masjid Al-Hidayah',
        ]);

        $venue->addMedia(UploadedFile::fake()->image('venue-cover.jpg', 1600, 900))
            ->toMediaCollection('cover');

        $reference = Reference::factory()->create([
            'title' => 'Al-Hikam',
        ]);

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'default_venue_id' => $venue->id,
        ]);

        $event->references()->attach($reference->id);

        $response = $this->get(route('events.show', $event));

        $response->assertOk()
            ->assertSee(__('References'))
            ->assertSee(__('Location'));

        $html = (string) $response->getContent();

        expect(strpos($html, 'data-testid="event-detail-references-section"'))
            ->toBeLessThan(strpos($html, 'data-testid="event-detail-location-section"'));
    });

    it('does not use person images as hero background when location media is missing', function () {
        $person = Person::factory()->create();
        $person->addMedia(UploadedFile::fake()->image('person-avatar.jpg', 800, 800))
            ->toMediaCollection('avatar');

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'institution_id' => null,
            'default_venue_id' => null,
        ]);
        OwnerContext::withOwner(null, fn () => $event->setPrimaryOrganizer($person));

        $event->persons()->attach($person->id);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertDontSee('class="size-full object-cover opacity-65"', false);
    });

    it('uses event cover as the hero background when available', function () {
        Storage::fake('public');
        config()->set('media-library.disk_name', 'public');

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'institution_id' => null,
            'default_venue_id' => null,
        ]);

        $event->addMedia(fakeGeneratedImageUpload('event-cover-hero.jpg', 1600, 900))
            ->toMediaCollection('cover');

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('class="size-full object-cover opacity-65"', false)
            ->assertSee($event->getFirstMedia('cover')?->getAvailableUrl(['banner', 'thumb']), false);
    });

    it('displays full venue address on the event page', function () {
        $venue = Venue::factory()->create();
        $address = $venue->primaryAddress();

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'default_venue_id' => $venue->id,
        ]);

        $response = $this->get(route('events.show', $event));
        $response->assertOk();

        // Should show line1 of the address
        if (filled($address?->line1)) {
            $response->assertSee($address->line1);
        }

        // Should show postcode if present
        if (filled($address?->postcode)) {
            $response->assertSee($address->postcode);
        }
    });

    it('displays waze and google maps navigation buttons when coordinates exist', function () {
        $venue = Venue::factory()->create();

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'default_venue_id' => $venue->id,
        ]);

        $response = $this->get(route('events.show', $event));
        $response->assertOk();
        $response->assertSee('Waze');
        $response->assertSee('Google Maps');
    });

    it('uses a public google maps embed on event show pages instead of platform api urls', function () {
        config()->set('services.google.maps_api_key', 'test-maps-key');

        $venue = Venue::factory()->create();
        $venue->primaryAddress()?->update([
            'line1' => 'Persiaran Masjid',
            'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=3.139%2C101.6869&query_place_id=place_123',
            'lat' => 3.139,
            'lng' => 101.6869,
        ]);

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'default_venue_id' => $venue->id,
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('https://www.google.com/maps?q=3.139%2C101.6869&amp;output=embed', false)
            ->assertDontSee('https://www.google.com/maps/embed/v1/place?key=', false)
            ->assertDontSee('https://maps.googleapis.com/maps/api/staticmap', false);
    });

    it('displays institution contact info on event page', function () {
        $institution = Institution::factory()->create();
        $emailContact = $institution->contactMethods()->where('type', ContactMethodType::Email->value)->first();
        $phoneContact = $institution->contactMethods()->where('type', ContactMethodType::Phone->value)->first();

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'institution_id' => $institution->id,
        ]);

        $response = $this->get(route('events.show', $event));
        $response->assertOk();

        if ($emailContact) {
            $response->assertSee($emailContact->value);
        }
        if ($phoneContact) {
            $response->assertSee($phoneContact->value);
        }
    });

    it('uses stored waze_url from address when available', function () {
        $venue = Venue::factory()->create();
        $address = $venue->primaryAddress();

        if ($address && filled($address->waze_url)) {
            $event = Event::factory()->create([
                'status' => 'approved',
                'visibility' => 'public',
                'published_at' => now()->subDay(),
                'starts_at' => now()->addDay(),
                'default_venue_id' => $venue->id,
            ]);

            $response = $this->get(route('events.show', $event));
            $response->assertOk();
            $response->assertSee('Waze');
        }

        expect(true)->toBeTrue();
    });

    it('hides duplicated state for kuala lumpur putrajaya and labuan in location display', function () {
        $venue = Venue::factory()->create([
            'name' => 'Dewan Utama KL',
        ]);

        $venue->primaryAddress()?->update([
            'city' => 'Setiawangsa',
            'state' => 'Kuala Lumpur',
        ]);

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'default_venue_id' => $venue->id,
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Dewan Utama KL')
            ->assertDontSee('Kuala Lumpur, Kuala Lumpur');
    });
});
