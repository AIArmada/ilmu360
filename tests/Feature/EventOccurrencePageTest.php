<?php

use AIArmada\Events\Actions\CreateEventSessionAction;
use AIArmada\Events\Models\EventLocation;
use App\Models\Event;
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

it('renders the occurrence page with a lean query budget', function (): void {
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
        ->assertSee('Subang Jaya, Selangor')
        ->assertSee('Open Maps');

    // Lean detail relations: event + schedule graph + location address only.
    // Card-only relations (classifications, references, persons, languages,
    // announcements, primaryOccurrence duplicates) must stay unloaded.
    expect($queries)->toBeLessThan(22);
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

it('renders the session page with a lean query budget', function (): void {
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
        ->assertSee('Sesi Soal Jawab');

    expect($queries)->toBeLessThan(25);
});
