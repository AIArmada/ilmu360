<?php

use AIArmada\Events\Actions\CreateEventSessionAction;
use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventRegistration;
use AIArmada\Events\Models\EventSession;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Venue;
use App\Support\Events\PublicScheduleSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->venue = Venue::factory()->create(['name' => 'Dewan Al-Ikhlas Test']);

    $geo = createTestPackageGeography('Selangor', 'Petaling', 'Subang Jaya');
    syncPrimaryAddressForTest($this->venue, [
        ...$geo['address'],
        'city' => 'Subang Jaya',
        'state' => 'Selangor',
        'google_maps_url' => 'https://maps.google.com/?q=3.1,101.6',
        'latitude' => 3.1,
        'longitude' => 101.6,
    ]);

    $this->event = Event::factory()->create([
        'title' => 'Tadabbur Muamalat Test',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
        'default_venue_id' => $this->venue->getKey(),
    ]);

    $this->occurrence = $this->event->occurrences()->firstOrFail();
    $this->occurrence->update([
        'title' => 'Siri Khas Muamalat',
        'status' => 'published',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);

    EventLocation::query()->create([
        'event_id' => $this->event->getKey(),
        'location_role' => 'primary',
        'venue_id' => $this->venue->getKey(),
        'visibility' => 'public',
        'status' => 'published',
    ]);
});

it('renders the occurrence page with a bounded enriched query budget', function (): void {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get(route('events.occurrence', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ]))
        ->assertOk()
        ->assertSee('Siri Khas Muamalat')
        ->assertSee('Tadabbur Muamalat Test')
        ->assertSee('Dewan Al-Ikhlas Test')
        // Canonical structured address lines (city / district / state), the
        // same hierarchy formatter the parent event page uses.
        ->assertSee('Subang Jaya')
        ->assertSee('Petaling')
        ->assertSee('Selangor')
        ->assertSee('Buka Peta')
        ->assertSee('Tambah ke Kalendar');

    // Enriched child view: event identity + shared public info + selected leaf
    // commerce. Siblings must not widen the budget (see N+1 guard below).
    expect($queries)->toBeLessThan(55);
});

it('keeps the occurrence budget stable when sibling dates grow', function (): void {
    foreach (['Siri Kedua', 'Siri Ketiga', 'Siri Keempat', 'Siri Kelima'] as $index => $title) {
        // EventOccurrence is vendor-owned without mass-assignable sort_order;
        // staggered starts_at keeps sibling ordering deterministic.
        $extra = $this->event->occurrences()->create([
            'title' => $title,
            'slug' => 'siri-'.$index.'-'.Str::uuid(),
            'status' => 'published',
            'visibility' => 'public',
            'starts_at' => now()->addDays(3 + $index),
        ]);

        app(CreateEventSessionAction::class)->handle($extra, [
            'title' => 'Sesi '.$title,
            'slug' => 'sesi-'.$index.'-'.Str::uuid(),
            'starts_at' => now()->addDays(3 + $index)->addHour(),
            'status' => 'published',
            'visibility' => 'public',
        ]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get(route('events.occurrence', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ]))->assertOk();

    expect($queries)->toBeLessThan(55);
});

it('shows shared parent info on the occurrence page', function (): void {
    $speaker = Person::factory()->create(['name' => 'Ustaz Induk Kongsi', 'status' => 'verified']);
    $this->event->persons()->attach($speaker->getKey(), [
        'role_code' => 'speaker',
        'status' => 'active',
        'visibility' => 'public',
    ]);

    $reference = Reference::factory()->create(['title' => 'Kitab Rujukan Induk']);
    $this->event->references()->attach($reference->getKey(), ['visibility' => 'public']);

    $this->get(route('events.occurrence', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ]))
        ->assertOk()
        ->assertSee('Ustaz Induk Kongsi')
        ->assertSee('Kitab Rujukan Induk');
});

it('hides private parent records from the occurrence page', function (): void {
    $speaker = Person::factory()->create(['name' => 'Ustaz Sulit Peribadi', 'status' => 'verified']);
    $this->event->persons()->attach($speaker->getKey(), [
        'role_code' => 'speaker',
        'status' => 'active',
        'visibility' => 'private',
    ]);

    $reference = Reference::factory()->create(['title' => 'Kitab Sulit Peribadi']);
    $this->event->references()->attach($reference->getKey(), ['visibility' => 'private']);

    $this->get(route('events.occurrence', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ]))
        ->assertOk()
        ->assertDontSee('Ustaz Sulit Peribadi')
        ->assertDontSee('Kitab Sulit Peribadi');
});

it('shows classifications even without a parent description', function (): void {
    $this->event->update(['description' => null, 'summary' => null]);

    $term = submitEventTerm('domain');
    $term->update(['name' => 'Akidah Ujian Klasifikasi']);

    $this->event->classifications()->create([
        'event_taxonomy_id' => $term->event_taxonomy_id,
        'event_term_id' => $term->getKey(),
        'taxonomy_code' => 'domain',
    ]);

    $this->get(route('events.occurrence', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ]))
        ->assertOk()
        ->assertSee('Klasifikasi')
        ->assertSee('Akidah Ujian Klasifikasi');
});

it('shows the muslim-only audience marker on the occurrence page', function (): void {
    $this->event->is_muslim_only = true;
    $this->event->syncAudiences();

    $this->get(route('events.occurrence', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ]))
        ->assertOk()
        ->assertSee('Muslim sahaja');
});

it('shows shared key people beyond speakers on the occurrence page', function (): void {
    EventKeyPerson::query()->create([
        'event_id' => $this->event->getKey(),
        'role_code' => 'moderator',
        'display_name' => 'Moderator Jemputan Kongsi',
        'visibility' => 'public',
    ]);

    $this->get(route('events.occurrence', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ]))
        ->assertOk()
        ->assertSee('Peranan')
        ->assertSee('Moderator Jemputan Kongsi');
});

it('hides the onsite location section for online-only events', function (): void {
    $this->event->update(['delivery_mode' => 'online', 'default_venue_id' => null]);
    EventLocation::query()->where('event_id', $this->event->getKey())->delete();

    $this->get(route('events.occurrence', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ]))
        ->assertOk()
        ->assertDontSee('Buka Peta')
        ->assertDontSee('Dewan Al-Ikhlas Test');
});

it('returns 404 for an unknown occurrence slug', function (): void {
    $this->get(route('events.occurrence', [
        'event' => $this->event,
        'occurrenceSlug' => 'no-such-occurrence',
    ]))->assertNotFound();
});

it('returns 404 for a non-public occurrence', function (): void {
    $this->occurrence->update(['status' => 'draft']);

    $this->get(route('events.occurrence', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence->fresh()),
    ]))->assertNotFound();
});

it('renders the session page with a bounded enriched query budget', function (): void {
    $session = app(CreateEventSessionAction::class)->handle($this->occurrence, [
        'title' => 'Sesi Soal Jawab',
        'slug' => 'sesi-soal-jawab',
        'starts_at' => now()->addDays(2)->addHour(),
        'ends_at' => now()->addDays(2)->addHours(2),
        'status' => 'published',
        'visibility' => 'public',
    ]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get(route('events.session', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
        'sessionSlug' => PublicScheduleSlug::session($session),
    ]))
        ->assertOk()
        ->assertSee('Sesi Soal Jawab')
        ->assertSee('Tambah ke Kalendar');

    // Session leaf plus parent date and event commerce, bounded.
    expect($queries)->toBeLessThan(65);
});

it('keeps an explicitly open-ended session open-ended', function (): void {
    $session = app(CreateEventSessionAction::class)->handle($this->occurrence, [
        'title' => 'Sesi Halaqah Terbuka',
        'slug' => 'sesi-halaqah-terbuka',
        'starts_at' => now()->addDays(2)->addHour(),
        'ends_at' => null,
        'status' => 'published',
        'visibility' => 'public',
    ]);

    $this->get(route('events.session', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
        'sessionSlug' => PublicScheduleSlug::session($session),
    ]))
        ->assertOk()
        ->assertSee('Sesi Halaqah Terbuka')
        ->assertSee('Masa tamat tidak ditetapkan');
});

it('closes session booking when its parent occurrence is closed', function (string $status): void {
    $session = app(CreateEventSessionAction::class)->handle($this->occurrence, [
        'title' => 'Published Session Under Closed Date',
        'slug' => 'published-session-under-closed-date',
        'starts_at' => now()->addDays(2)->addMinutes(15),
        'ends_at' => now()->addDays(2)->addMinutes(45),
        'status' => 'published',
        'visibility' => 'public',
    ]);
    $ticket = $session->ticketTypes()->create([
        'name' => 'Closed Parent Session Ticket',
        'code' => 'CLOSED-PARENT-SESSION',
        'access_type' => 'general',
        'price' => 0,
        'currency' => 'MYR',
        'status' => 'active',
        'visibility' => 'public',
    ]);
    $this->occurrence->update(['status' => $status]);

    $this->get(route('events.session', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
        'sessionSlug' => PublicScheduleSlug::session($session),
    ]))
        ->assertOk()
        ->assertSee('Closed Parent Session Ticket')
        ->assertDontSee('href="'.route('events.checkout', [
            'event' => $this->event,
            'ticket' => $ticket->getKey(),
        ]).'"', false)
        ->assertSee(__('Pendaftaran ditutup kerana status program.'));
})->with(['cancelled', 'completed']);

it('renders canonical occurrence links when its stored slug is absent', function (?string $slug): void {
    $this->occurrence->update(['slug' => $slug]);

    $session = app(CreateEventSessionAction::class)->handle($this->occurrence, [
        'title' => 'Session With Fallback Parent Slug',
        'slug' => 'session-with-fallback-parent-slug',
        'starts_at' => now()->addDays(2)->addMinutes(15),
        'ends_at' => now()->addDays(2)->addMinutes(45),
        'status' => 'published',
        'visibility' => 'public',
    ]);

    $parameters = [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ];
    $occurrenceUrl = route('events.occurrence', $parameters);

    $response = $this->get(route('events.session', [
        ...$parameters,
        'sessionSlug' => PublicScheduleSlug::session($session),
    ]));

    $response
        ->assertOk()
        ->assertSee('Session With Fallback Parent Slug')
        ->assertSee('href="'.$occurrenceUrl.'"', false);

    expect(substr_count(
        (string) $response->getContent(),
        'href="'.$occurrenceUrl.'"',
    ))->toBe(3);

    $this->get($occurrenceUrl)->assertOk();
})->with([
    'null slug' => [null],
    'empty slug' => [''],
]);

it('preserves event admission capacity on schedule child pages', function (string $page): void {
    $this->event->update([
        'pricing_mode' => 'free',
        'registration_mode' => 'required',
    ]);
    $this->event->accessPolicy()->delete();
    $this->event->accessPolicy()->create([
        'registration_required' => true,
        'ticket_required' => true,
        'capacity' => 5,
    ]);

    $ticket = $this->event->ticketTypes()->create([
        'name' => 'Full Event Admission Ticket',
        'code' => 'FULL-EVENT-ADMISSION',
        'access_type' => 'general',
        'price' => 0,
        'currency' => 'MYR',
        'status' => 'active',
        'visibility' => 'public',
    ]);

    foreach ([
        ['status' => 'confirmed', 'total_participants' => 3],
        ['status' => 'pending', 'total_participants' => 2],
        ['status' => 'rejected', 'total_participants' => 9],
    ] as $registration) {
        EventRegistration::factory()->create([
            'event_id' => $this->event->getKey(),
            'event_occurrence_id' => null,
            'event_session_id' => null,
            ...$registration,
        ]);
    }

    $parameters = [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ];

    if ($page === 'session') {
        $session = app(CreateEventSessionAction::class)->handle($this->occurrence, [
            'title' => 'Session With Full Event Admission',
            'slug' => 'session-with-full-event-admission',
            'starts_at' => now()->addDays(2)->addMinutes(15),
            'ends_at' => now()->addDays(2)->addMinutes(45),
            'status' => 'published',
            'visibility' => 'public',
        ]);
        $parameters['sessionSlug'] = PublicScheduleSlug::session($session);
    }

    $this->get(route('events.'.$page, $parameters))
        ->assertOk()
        ->assertSee('Full Event Admission Ticket')
        ->assertSee('5 / 5')
        ->assertSee(__('Tempat penuh buat masa ini.'))
        ->assertDontSee('href="'.route('events.checkout', [
            'event' => $this->event,
            'ticket' => $ticket->getKey(),
        ]).'"', false);
})->with(['occurrence', 'session']);

it('keeps hidden session records out of public date fallbacks', function (
    string $page,
    string $hiddenStatus,
    string $hiddenVisibility,
): void {
    $selected = app(CreateEventSessionAction::class)->handle($this->occurrence, [
        'title' => 'Selected Public Session',
        'slug' => 'selected-public-session',
        'starts_at' => now()->addDays(2)->addMinutes(15),
        'ends_at' => now()->addDays(2)->addMinutes(45),
        'status' => 'published',
        'visibility' => 'public',
    ]);
    $hidden = app(CreateEventSessionAction::class)->handle($this->occurrence, [
        'title' => 'Hidden Session',
        'slug' => 'hidden-session',
        'starts_at' => now()->addDays(2)->addHour(),
        'ends_at' => now()->addDays(2)->addHours(2),
        'status' => $hiddenStatus,
        'visibility' => $hiddenVisibility,
    ]);

    $addRows = function (
        EventOccurrence|EventSession $scope,
        string $label,
        int $capacity,
    ): void {
        $attributes = [
            'event_id' => $this->event->getKey(),
            'event_occurrence_id' => $this->occurrence->getKey(),
            'event_session_id' => $scope instanceof EventSession
                ? $scope->getKey()
                : null,
        ];
        $scope->involvements()->create([
            ...$attributes,
            'display_name' => $label.' Person',
            'role_code' => 'speaker',
            'status' => 'active',
            'visibility' => 'public',
        ]);
        $scope->references()->create([
            ...$attributes,
            'referenceable_type' => null,
            'referenceable_id' => null,
            'reference_type' => 'book',
            'title' => $label.' Reference',
            'citation' => $label.' Citation',
            'visibility' => 'public',
        ]);
        $scope->links()->create([
            ...$attributes,
            'link_type' => 'external',
            'label' => $label.' Link',
            'url' => 'https://example.com/'.strtolower($label).'-link',
            'visibility' => 'public',
        ]);
        $scope->materials()->create([
            ...$attributes,
            'material_type' => 'document',
            'usage_type' => 'handout',
            'title' => $label.' Material',
            'url' => 'https://example.com/'.strtolower($label).'-material',
            'visibility' => 'public',
        ]);
        $scope->accessPolicies()->create([
            ...$attributes,
            'registration_required' => true,
            'capacity' => $capacity,
            'notes' => $label.' Policy',
        ]);
    };

    $addRows($hidden, 'Hidden', 0);
    $addRows($this->occurrence, 'Date', 20);
    $addRows($selected, 'Selected', 30);

    $hiddenVenue = Venue::factory()->create(['name' => 'Hidden Session Venue']);
    $hidden->locations()->create([
        'event_id' => $this->event->getKey(),
        'event_occurrence_id' => $this->occurrence->getKey(),
        'event_session_id' => $hidden->getKey(),
        'location_role' => 'primary',
        'venue_id' => $hiddenVenue->getKey(),
        'label' => 'Hidden Session Location',
        'status' => 'active',
        'visibility' => 'public',
    ]);

    $parameters = [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ];
    if ($page === 'session') {
        $parameters['sessionSlug'] = PublicScheduleSlug::session($selected);
    }

    $response = $this->get(route('events.'.$page, $parameters));
    $response
        ->assertOk()
        ->assertSee('Date Reference')
        ->assertSee('Date Link')
        ->assertSee('Date Material')
        ->assertSee('0 / 20')
        ->assertSee('Dewan Al-Ikhlas Test')
        ->assertDontSee('Hidden Person')
        ->assertDontSee('Hidden Reference')
        ->assertDontSee('Hidden Citation')
        ->assertDontSee('Hidden Link')
        ->assertDontSee('Hidden Material')
        ->assertDontSee('Hidden Policy')
        ->assertDontSee('Hidden Session Venue')
        ->assertDontSee('Hidden Session Location');

    if ($page === 'session') {
        $response
            ->assertSee('Selected Person')
            ->assertSee('Selected Reference')
            ->assertSee('Selected Link')
            ->assertSee('Selected Material')
            ->assertSee('Selected Policy');
    } else {
        $response->assertSee('Date Person')->assertSee('Date Policy');
    }

    $this->get(route('events.'.$page.'.calendar', $parameters))
        ->assertOk()
        ->assertDontSee('Hidden Session Venue')
        ->assertDontSee('Hidden Session Location');

    $this->get(route('events.show', $this->event))
        ->assertOk()
        ->assertDontSee('Hidden Link')
        ->assertDontSee('Hidden Material')
        ->assertDontSee('Hidden Policy')
        ->assertDontSee('Hidden Session Location');
})->with([
    'date, draft sibling' => ['occurrence', 'draft', 'public'],
    'date, private sibling' => ['occurrence', 'published', 'private'],
    'session, draft sibling' => ['session', 'draft', 'public'],
    'session, private sibling' => ['session', 'published', 'private'],
]);

it('does not substitute the parent address for an addressless scoped venue', function (
    string $page,
): void {
    $venue = Venue::factory()->create(['name' => 'Addressless Scoped Venue']);
    $venue->addresses()->detach();

    $scope = $this->occurrence;
    $parameters = [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ];

    if ($page === 'session') {
        $scope = app(CreateEventSessionAction::class)->handle($this->occurrence, [
            'title' => 'Addressless Venue Session',
            'slug' => 'addressless-venue-session',
            'starts_at' => now()->addDays(2)->addMinutes(15),
            'ends_at' => now()->addDays(2)->addMinutes(45),
            'status' => 'published',
            'visibility' => 'public',
        ]);
        $parameters['sessionSlug'] = PublicScheduleSlug::session($scope);
    }

    $scope->locations()->create([
        'event_id' => $this->event->getKey(),
        'event_occurrence_id' => $this->occurrence->getKey(),
        'event_session_id' => $scope instanceof EventSession
            ? $scope->getKey()
            : null,
        'location_role' => 'primary',
        'venue_id' => $venue->getKey(),
        'status' => 'active',
        'visibility' => 'public',
    ]);

    $this->get(route('events.'.$page, $parameters))
        ->assertOk()
        ->assertSee('Addressless Scoped Venue')
        ->assertSee('query=Addressless+Scoped+Venue', false)
        ->assertDontSee('Subang Jaya')
        ->assertDontSee('query=3.1%2C101.6', false);
})->with(['occurrence', 'session']);

it('keeps hidden session time expressions off the parent date display', function (): void {
    $hidden = app(CreateEventSessionAction::class)->handle($this->occurrence, [
        'title' => 'Hidden Prayer Session',
        'slug' => 'hidden-prayer-session',
        'starts_at' => now()->addDays(2)->addHour(),
        'ends_at' => now()->addDays(2)->addHours(2),
        'status' => 'draft',
        'visibility' => 'public',
    ]);
    $hidden->timeExpressions()->create([
        'event_id' => $this->event->getKey(),
        'event_occurrence_id' => $this->occurrence->getKey(),
        'time_mode' => 'prayer_relative',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'display_label' => 'Hidden Session Prayer Label',
    ]);

    $this->get(route('events.show', $this->event))
        ->assertOk()
        ->assertDontSee('Hidden Session Prayer Label');
});

it('hides placeholder sessions without title or time from the occurrence page', function (): void {
    app(CreateEventSessionAction::class)->handle($this->occurrence, [
        'title' => 'Sesi Lengkap Berjadual',
        'slug' => 'sesi-lengkap-berjadual',
        'starts_at' => now()->addDays(2)->addMinutes(15),
        'ends_at' => now()->addDays(2)->addMinutes(45),
        'status' => 'published',
        'visibility' => 'public',
    ]);
    EventSession::query()->create([
        'event_id' => $this->event->getKey(),
        'event_occurrence_id' => $this->occurrence->getKey(),
        'title' => '',
        'slug' => 'placeholder-untitled',
        'starts_at' => now()->addDays(2)->addHour(),
        'status' => 'published',
        'visibility' => 'public',
    ]);
    EventSession::query()->create([
        'event_id' => $this->event->getKey(),
        'event_occurrence_id' => $this->occurrence->getKey(),
        'title' => 'Sesi Tanpa Masa',
        'slug' => 'placeholder-timeless',
        'starts_at' => null,
        'status' => 'published',
        'visibility' => 'public',
    ]);

    $this->get(route('events.occurrence', [
        'event' => $this->event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
    ]))
        ->assertOk()
        ->assertSee('Sesi Lengkap Berjadual')
        ->assertSee('1 segmen')
        ->assertDontSee('2 segmen')
        ->assertDontSee('3 segmen')
        ->assertDontSee('Sesi Tanpa Masa');
});
