<?php

use AIArmada\Events\Models\EventLocation;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Places\Models\Space;
use App\Features\Places\Models\Venue;

it('keeps venue-change replace semantics for event-level spaces', function (): void {
    $institution = Institution::factory()->create();
    $oldVenue = Venue::factory()->create();
    $newVenue = Venue::factory()->create();
    $space = Space::factory()->create();

    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'default_venue_id' => $oldVenue->getKey(),
    ]);

    $event->syncLocation((string) $oldVenue->getKey(), [(string) $space->getKey()]);

    $event->update(['default_venue_id' => $newVenue->getKey()]);

    $locations = EventLocation::query()
        ->where('event_id', $event->getKey())
        ->whereNull('event_occurrence_id')
        ->whereNull('event_session_id')
        ->get();

    expect($locations->pluck('venue_space_id')->filter()->all())->toBeEmpty()
        ->and($locations->firstWhere('location_role', 'primary')?->venue_id)->toBe((string) $newVenue->getKey());
});
