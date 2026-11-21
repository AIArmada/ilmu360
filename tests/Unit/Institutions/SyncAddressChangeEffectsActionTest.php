<?php

declare(strict_types=1);

use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Institutions\Actions\SyncAddressChangeEffectsAction;
use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Places\Api\VenueWorkflow;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('preserves institution, person and venue address effects and invalidation counts', function (): void {
    $events = Mockery::mock(EventAuthoring::class);
    $institutions = Mockery::mock(InstitutionWorkflow::class);
    $persons = Mockery::mock(PersonWorkflow::class);
    $venues = Mockery::mock(VenueWorkflow::class);
    $institutionVersions = Mockery::mock(InstitutionListingCacheVersion::class);
    $personVersions = Mockery::mock(PersonListingCacheVersion::class);
    $listings = Mockery::mock(PublicListingsCacheInvalidator::class);

    $institutions->shouldReceive('syncSlugsForName')->once()->with('Masjid A')->andReturn(true);
    $institutions->shouldReceive('syncSearchProjection')->once()->with('institution-id');
    $persons->shouldReceive('syncSlugsForName')->once()->with('Person B')->andReturn(true);
    $persons->shouldReceive('syncSearchProjection')->once()->with('person-id');
    $venues->shouldReceive('syncSlugsForName')->once()->with('Venue C')->andReturn(true);
    $events->shouldReceive('syncSearchableEventsByIds')->once()->with(['event-1']);
    $events->shouldReceive('syncSearchableEventsByIds')->once()->with(['event-2']);
    $events->shouldReceive('syncEventSlugsForPersonName')->once()->with('Person B')->andReturn(true);
    $listings->shouldReceive('bustMajlisListing')->times(3);
    $institutionVersions->shouldReceive('bump')->once();
    $personVersions->shouldReceive('bump')->once();

    $action = new SyncAddressChangeEffectsAction(
        $events,
        $institutions,
        $persons,
        $venues,
        $institutionVersions,
        $personVersions,
        $listings,
    );

    $action->apply([
        ['morph_type' => 'institution', 'id' => 'institution-id', 'name' => 'Masjid A', 'event_ids' => ['event-1']],
        ['morph_type' => 'person', 'id' => 'person-id', 'name' => 'Person B', 'event_ids' => []],
        ['morph_type' => 'venue', 'id' => 'venue-id', 'name' => 'Venue C', 'event_ids' => ['event-2']],
    ], ['institution', 'person', 'venue']);
});

it('invalidates persisted subject-type versions even when the relation cannot hydrate', function (): void {
    $events = Mockery::mock(EventAuthoring::class);
    $institutions = Mockery::mock(InstitutionWorkflow::class);
    $persons = Mockery::mock(PersonWorkflow::class);
    $venues = Mockery::mock(VenueWorkflow::class);
    $institutionVersions = Mockery::mock(InstitutionListingCacheVersion::class);
    $personVersions = Mockery::mock(PersonListingCacheVersion::class);
    $listings = Mockery::mock(PublicListingsCacheInvalidator::class);

    $events->shouldReceive('syncSearchableEventsByIds')->never();
    $institutions->shouldReceive('syncSearchProjection')->never();
    $persons->shouldReceive('syncSearchProjection')->never();
    $venues->shouldReceive('syncSlugsForName')->never();
    $listings->shouldReceive('bustMajlisListing')->never();
    $institutionVersions->shouldReceive('bump')->never();
    $personVersions->shouldReceive('bump')->once();

    $action = new SyncAddressChangeEffectsAction(
        $events,
        $institutions,
        $persons,
        $venues,
        $institutionVersions,
        $personVersions,
        $listings,
    );

    $action->apply([], ['person']);
});
