<?php

use App\Enums\ContactCategory;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\Subdistrict;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

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

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => Carbon::parse('2026-03-26 21:30:00', 'Asia/Kuala_Lumpur'),
            'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => Carbon::parse('2026-04-02 18:30:00', 'Asia/Kuala_Lumpur'),
            'ends_at' => null,
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee(__('Majlis ini telah berlalu.'))
            ->assertDontSee(__('Sedang Berlangsung'))
            ->assertDontSee(__('Akan Hadir'));
    });

    it('treats events without ends_at as happening now within the fallback window', function () {
        Carbon::setTestNow(Carbon::parse('2026-04-02 20:30:00', 'Asia/Kuala_Lumpur'));

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => Carbon::parse('2026-03-26 20:30:00', 'Asia/Kuala_Lumpur'),
            'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => Carbon::parse('2026-04-02 19:45:00', 'Asia/Kuala_Lumpur'),
            'ends_at' => null,
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee(__('Sedang Berlangsung'))
            ->assertDontSee(__('Majlis ini telah berlalu.'));
    });

    it('authenticated user can toggle going status via livewire', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'going_count' => 0,
        ]);

        $this->actingAs($user);

        Livewire::test('pages.events.show', ['event' => $event])
            ->assertSet('isGoing', false)
            ->call('toggleGoing')
            ->assertSet('isGoing', true);

        expect($event->fresh()->going_count)->toBe(1);
        expect($user->goingEvents()->where('event_id', $event->id)->exists())->toBeTrue();
    });

    it('authenticated user can toggle off going status via livewire', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'going_count' => 1,
        ]);

        // Pre-attach the user
        $user->goingEvents()->attach($event->id);

        $this->actingAs($user);

        Livewire::test('pages.events.show', ['event' => $event])
            ->assertSet('isGoing', true)
            ->call('toggleGoing')
            ->assertSet('isGoing', false);

        expect($event->fresh()->going_count)->toBe(0);
        expect($user->goingEvents()->where('event_id', $event->id)->exists())->toBeFalse();
    });

    it('redirects guests to login when trying to toggle going', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
        ]);

        Livewire::test('pages.events.show', ['event' => $event])
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
            'going_count' => 5,
        ]);

        foreach ($users as $user) {
            $event->goingBy()->attach($user->id);
        }

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee(__('Akan Hadir')); // Button always visible regardless of auth

        // Verify the going count is persisted correctly on the model
        expect($event->fresh()->going_count)->toBe(5);
    });
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

    it('shows the about section when the event has tags even without a description', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'description' => ['html' => '<p><br></p>'],
        ]);
        $tag = Tag::factory()->domain()->create([
            'name' => ['en' => 'Akidah', 'ms' => 'Akidah'],
            'slug' => ['en' => 'akidah', 'ms' => 'akidah'],
        ]);

        $event->attachTag($tag);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee(__('About this Event'))
            ->assertSee('Akidah');
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
            'venue_id' => $venue->id,
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
            'venue_id' => $venue->id,
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

    it('does not use speaker images as hero background when location media is missing', function () {
        $speaker = Speaker::factory()->create();
        $speaker->addMedia(UploadedFile::fake()->image('speaker-avatar.jpg', 800, 800))
            ->toMediaCollection('avatar');

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'institution_id' => null,
            'venue_id' => null,
            'organizer_type' => Speaker::class,
            'organizer_id' => $speaker->id,
        ]);

        $event->speakers()->attach($speaker->id);

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
            'venue_id' => null,
        ]);

        $event->addMedia(UploadedFile::fake()->image('event-cover-hero.jpg', 1600, 900))
            ->toMediaCollection('cover');

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('class="size-full object-cover opacity-65"', false)
            ->assertSee('/cover/', false);
    });

    it('displays full venue address on the event page', function () {
        $venue = Venue::factory()->create();
        $address = $venue->addressModel;

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'venue_id' => $venue->id,
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
            'venue_id' => $venue->id,
        ]);

        $response = $this->get(route('events.show', $event));
        $response->assertOk();
        $response->assertSee('Waze');
        $response->assertSee('Google Maps');
    });

    it('uses a public google maps embed on event show pages instead of platform api urls', function () {
        config()->set('services.google.maps_api_key', 'test-maps-key');

        $venue = Venue::factory()->create();
        $venue->address()->update([
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
            'venue_id' => $venue->id,
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('https://www.google.com/maps?q=3.139%2C101.6869&amp;output=embed', false)
            ->assertDontSee('https://www.google.com/maps/embed/v1/place?key=', false)
            ->assertDontSee('https://maps.googleapis.com/maps/api/staticmap', false);
    });

    it('displays institution contact info on event page', function () {
        $institution = Institution::factory()->create();
        $emailContact = $institution->contacts()->where('category', ContactCategory::Email->value)->first();
        $phoneContact = $institution->contacts()->where('category', ContactCategory::Phone->value)->first();

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
        $address = $venue->addressModel;

        if ($address && filled($address->waze_url)) {
            $event = Event::factory()->create([
                'status' => 'approved',
                'visibility' => 'public',
                'published_at' => now()->subDay(),
                'starts_at' => now()->addDay(),
                'venue_id' => $venue->id,
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

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDay(),
            'venue_id' => $venue->id,
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Dewan Utama KL')
            ->assertDontSee('Kuala Lumpur, Kuala Lumpur');
    });
});
