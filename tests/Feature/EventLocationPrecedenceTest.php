<?php

use AIArmada\Events\Models\EventLocation;
use App\Data\Api\Frontend\Search\EventListData;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Venue;
use App\Support\Events\EventDetailPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function detachHardeningVenueAddresses(Venue $venue): void
{
    DB::table(config('addressing.tables.addressables', 'addressables'))
        ->where('addressable_type', $venue->getMorphClass())
        ->where('addressable_id', (string) $venue->getKey())
        ->delete();
}

it('resolves the institution location when no venue is selected', function (): void {
    $institution = Institution::factory()->create();
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
    ]);

    expect($institution->primaryAddress())->not->toBeNull()
        ->and($event->hasExplicitVenueSelection())->toBeFalse()
        ->and($event->resolvedLocationAddress()?->getKey())->toBe($institution->primaryAddress()?->getKey())
        ->and($event->resolvedLocationName())->toBe($institution->name);
});

it('prefers the explicit venue over the institution for offsite events', function (): void {
    $institution = Institution::factory()->create();
    $venue = Venue::factory()->create();
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'default_venue_id' => $venue->getKey(),
    ]);

    expect($event->hasExplicitVenueSelection())->toBeTrue()
        ->and($event->resolvedLocationAddress()?->getKey())->toBe($venue->primaryAddress()?->getKey())
        ->and($event->resolvedLocationName())->toBe($venue->name)
        ->and(EventListData::fromModel($event->fresh())->location)->toContain((string) $venue->name);
});

it('does not fall back to the institution when the selected venue has no address', function (): void {
    $institution = Institution::factory()->create();
    $venue = Venue::factory()->create();
    detachHardeningVenueAddresses($venue);

    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'default_venue_id' => $venue->getKey(),
    ]);

    expect($institution->primaryAddress())->not->toBeNull()
        ->and($event->hasExplicitVenueSelection())->toBeTrue()
        ->and($event->resolvedLocationAddress())->toBeNull();
});

it('uses the primary package location venue when no default venue is set', function (): void {
    $institution = Institution::factory()->create();
    $venue = Venue::factory()->create();
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
    ]);
    $event->syncLocation((string) $venue->getKey());

    $event = $event->fresh();

    expect($event->hasExplicitVenueSelection())->toBeTrue()
        ->and($event->resolvedLocationAddress()?->getKey())->toBe($venue->primaryAddress()?->getKey())
        ->and($event->resolvedLocationName())->toBe($venue->name);
});

it('resolves no location when neither institution nor venue is set', function (): void {
    $event = Event::factory()->create([
        'institution_id' => null,
        'default_venue_id' => null,
    ]);

    expect($event->hasExplicitVenueSelection())->toBeFalse()
        ->and($event->resolvedLocationAddress())->toBeNull()
        ->and($event->resolvedLocationName())->toBeNull();
});

it('selects the canonically ordered primary row when several primary rows exist', function (): void {
    $institution = Institution::factory()->create();
    $laterRow = Venue::factory()->create();
    $canonical = Venue::factory()->create();
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
    ]);

    EventLocation::create([
        'event_id' => (string) $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) $laterRow->getKey(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 1,
    ]);
    EventLocation::create([
        'event_id' => (string) $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) $canonical->getKey(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);

    $event = $event->fresh();

    expect($event)->toBeInstanceOf(Event::class);

    expect($event->primaryLocationVenueId())->toBe((string) $canonical->getKey())
        ->and($event->resolvedLocationAddress()?->getKey())->toBe($canonical->primaryAddress()?->getKey())
        ->and($event->resolvedLocationName())->toBe($canonical->name)
        ->and($event->hasExplicitVenueSelection())->toBeTrue();
});

it('selects the same event-level primary row for the loaded presenter and the canonical query', function (): void {
    $institution = Institution::factory()->create();
    $decoyVenue = Venue::factory()->create();
    $canonicalVenue = Venue::factory()->create();
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
    ]);

    // Occurrence-scoped primary inserted first: it must never stand in for
    // the event-level primary, no matter the collection order.
    EventLocation::create([
        'event_id' => (string) $event->getKey(),
        'event_occurrence_id' => (string) Str::uuid(),
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) $decoyVenue->getKey(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);
    EventLocation::create([
        'event_id' => (string) $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) $decoyVenue->getKey(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 5,
    ]);
    $canonicalRow = EventLocation::create([
        'event_id' => (string) $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) $canonicalVenue->getKey(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);

    $presenter = app(EventDetailPresenter::class);
    $loaded = Event::query()->with('locations')->whereKey($event->getKey())->firstOrFail();

    $selected = $presenter->primaryLocationFor($loaded);
    $canonical = Event::query()->whereKey($event->getKey())->firstOrFail()->primaryLocation;

    expect($selected)->toBeInstanceOf(EventLocation::class)
        ->and((string) $selected->getKey())->toBe((string) $canonicalRow->getKey())
        ->and($canonical)->toBeInstanceOf(EventLocation::class)
        ->and((string) $canonical->getKey())->toBe((string) $canonicalRow->getKey());

    // A loaded primaryLocation (including a loaded null) wins over the
    // locations collection: absence is never replaced by another row.
    $loaded->setRelation('primaryLocation', null);

    expect($presenter->primaryLocationFor($loaded))->toBeNull();

    $loaded->setRelation('primaryLocation', $canonicalRow);

    expect($presenter->primaryLocationFor($loaded))->toBe($canonicalRow);
});

it('resolves the primary location venue through the application model for eager and lazy callers', function (): void {
    $institution = Institution::factory()->create();
    $venue = Venue::factory()->create();
    $lowerPriorityVenue = Venue::factory()->create();
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
    ]);
    $event->syncLocation((string) $venue->getKey());

    EventLocation::create([
        'event_id' => (string) $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) $lowerPriorityVenue->getKey(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 99,
    ]);

    $lazy = Event::query()->whereKey($event->getKey())->firstOrFail();

    expect($lazy->primaryLocationVenue)->toBeInstanceOf(Venue::class)
        ->and((string) $lazy->primaryLocationVenue->getKey())->toBe((string) $venue->getKey())
        ->and($lazy->primaryLocationVenue->primaryAddress()?->getKey())->toBe($venue->primaryAddress()?->getKey());

    $eager = Event::query()->with('primaryLocationVenue.addresses')->whereKey($event->getKey())->firstOrFail();

    expect($eager->primaryLocationVenue)->toBeInstanceOf(Venue::class)
        ->and((string) $eager->primaryLocationVenue->getKey())->toBe((string) $venue->getKey())
        ->and($eager->resolvedLocationAddress()?->getKey())->toBe($venue->primaryAddress()?->getKey())
        ->and($eager->resolvedLocationName())->toBe($venue->name);
});

it('resolves null when the first primary row has no venue even with a later real primary', function (): void {
    $institution = Institution::factory()->create();
    $laterVenue = Venue::factory()->create();
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
    ]);

    EventLocation::create([
        'event_id' => (string) $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => null,
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);
    EventLocation::create([
        'event_id' => (string) $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) $laterVenue->getKey(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 1,
    ]);

    // The inner join must not skip the selected no-venue row: the direct
    // eager property itself agrees with the canonical null selection.
    $lazy = Event::query()->whereKey($event->getKey())->firstOrFail();

    expect($lazy->primaryLocationVenue)->toBeNull()
        ->and($lazy->primaryLocationVenueId())->toBeNull()
        ->and($lazy->hasExplicitVenueSelection())->toBeFalse()
        ->and($lazy->resolvedLocationAddress()?->getKey())->toBe($institution->primaryAddress()?->getKey())
        ->and($lazy->resolvedLocationName())->toBe($institution->name);

    $eager = Event::query()->with('primaryLocationVenue.addresses')->whereKey($event->getKey())->firstOrFail();

    expect($eager->primaryLocationVenue)->toBeNull()
        ->and($eager->primaryLocationVenueId())->toBeNull()
        ->and($eager->hasExplicitVenueSelection())->toBeFalse()
        ->and($eager->resolvedLocationAddress()?->getKey())->toBe($institution->primaryAddress()?->getKey())
        ->and($eager->resolvedLocationName())->toBe($institution->name);
});

it('never resolves a later venue when the first primary venue marker is missing', function (): void {
    $institution = Institution::factory()->create();
    $laterVenue = Venue::factory()->create();
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
    ]);

    EventLocation::create([
        'event_id' => (string) $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) Str::uuid(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);
    EventLocation::create([
        'event_id' => (string) $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) $laterVenue->getKey(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 1,
    ]);

    // An orphan first UUID pins the selection: it must never resolve the
    // later real primary venue, lazily or eagerly. Like an orphan default
    // venue marker, the explicit selection stands and never borrows the
    // institution place.
    $lazy = Event::query()->whereKey($event->getKey())->firstOrFail();

    expect($lazy->primaryLocationVenue)->toBeNull()
        ->and($lazy->primaryLocationVenueId())->not->toBeNull()
        ->and($lazy->hasExplicitVenueSelection())->toBeTrue()
        ->and($lazy->resolvedLocationAddress())->toBeNull()
        ->and($lazy->resolvedLocationName())->toBeNull();

    $eager = Event::query()->with('primaryLocationVenue.addresses')->whereKey($event->getKey())->firstOrFail();

    expect($eager->primaryLocationVenue)->toBeNull()
        ->and($eager->primaryLocationVenueId())->not->toBeNull()
        ->and($eager->hasExplicitVenueSelection())->toBeTrue()
        ->and($eager->resolvedLocationAddress())->toBeNull()
        ->and($eager->resolvedLocationName())->toBeNull();
});
