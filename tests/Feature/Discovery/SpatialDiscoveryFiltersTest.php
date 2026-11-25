<?php

declare(strict_types=1);

use App\Features\Discovery\Services\EventSearchFilterNormalizer;
use App\Features\Discovery\Services\EventSearchFilterRules;
use App\Features\Events\EntryPoints\Mcp\McpEventSearchService;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Places\Models\Venue;
use App\Features\Places\Queries\VenueDirectoryQuery;
use Illuminate\Support\Facades\Validator;

function spatialTestVenue(string $name, float $latitude, float $longitude): Venue
{
    $venue = Venue::factory()->create(['name' => $name, 'status' => 'verified']);

    syncPrimaryAddressForTest($venue, [
        'latitude' => $latitude,
        'longitude' => $longitude,
    ]);

    return $venue;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function spatialTestPublicEvent(Venue $venue, string $title, array $attributes = []): Event
{
    return Event::factory()->for($venue)->create(array_merge([
        'title' => $title,
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ], $attributes));
}

it('filters the public institution directory to the requested bounding box', function (): void {
    $inside = Institution::factory()->create(['name' => 'Masjid Dalam Kotak', 'status' => 'verified']);
    syncPrimaryAddressForTest($inside, ['latitude' => 3.15, 'longitude' => 101.65]);

    $outside = Institution::factory()->create(['name' => 'Masjid Luar Kotak', 'status' => 'verified']);
    syncPrimaryAddressForTest($outside, ['latitude' => 4.5, 'longitude' => 103.0]);

    $response = $this->getJson('/api/v1/institutions?bbox=101.5,3.0,101.8,3.3');

    $response->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', (string) $inside->id);

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([(string) $inside->id]);
});

it('ignores malformed bounding boxes instead of constraining the institution directory', function (): void {
    Institution::factory()->create(['name' => 'Masjid Satu', 'status' => 'verified']);
    Institution::factory()->create(['name' => 'Masjid Dua', 'status' => 'verified']);

    $this->getJson('/api/v1/institutions?bbox=120,90,110,-90')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 2);
});

it('applies bounding box and nearby distance scope in the venue directory query', function (): void {
    $inside = spatialTestVenue('Dewan Dalam Kotak', 3.15, 101.65);
    spatialTestVenue('Dewan Luar Kotak', 4.5, 103.0);

    $directory = app(VenueDirectoryQuery::class);
    $paginationMeta = ['path' => '/tempat', 'query' => []];

    $bounded = $directory->paginate(
        search: null,
        countryId: null,
        stateId: null,
        page: 1,
        paginationMeta: $paginationMeta,
        perPage: 12,
        bounds: ['west' => 101.5, 'south' => 3.0, 'east' => 101.8, 'north' => 3.3],
    );

    $nearby = $directory->paginate(
        search: null,
        countryId: null,
        stateId: null,
        page: 1,
        paginationMeta: $paginationMeta,
        perPage: 12,
        latitude: 3.15,
        longitude: 101.65,
        radiusKm: 5,
    );

    expect($bounded->total())->toBe(1)
        ->and(collect($bounded->items())->pluck('id')->all())->toBe([(string) $inside->id])
        ->and($nearby->total())->toBe(1)
        ->and(collect($nearby->items())->pluck('id')->all())->toBe([(string) $inside->id])
        ->and((float) $nearby->items()[0]->distance_km)->toBe(0.0);
});

it('filters public search events by bounding box', function (): void {
    config()->set('scout.driver', 'database');

    $insideVenue = spatialTestVenue('Dewan Dalam Pencarian', 3.15, 101.65);
    spatialTestPublicEvent($insideVenue, 'Majlis Dalam Kotak');

    $outsideVenue = spatialTestVenue('Dewan Luar Pencarian', 4.5, 103.0);
    spatialTestPublicEvent($outsideVenue, 'Majlis Luar Kotak');

    $response = $this->getJson('/api/v1/search?bbox=101.5,3.0,101.8,3.3');

    $response->assertOk();

    $titles = collect($response->json('data.events.items'))->pluck('title')->all();

    expect($titles)->toContain('Majlis Dalam Kotak')
        ->not->toContain('Majlis Luar Kotak');
});

it('filters MCP event search by bounding box and reports the normalized filter', function (): void {
    config()->set('scout.driver', 'database');

    $insideVenue = spatialTestVenue('Dewan Dalam MCP', 3.15, 101.65);
    spatialTestPublicEvent($insideVenue, 'Majlis MCP Dalam Kotak');

    $outsideVenue = spatialTestVenue('Dewan Luar MCP', 4.5, 103.0);
    spatialTestPublicEvent($outsideVenue, 'Majlis MCP Luar Kotak');

    $result = app(McpEventSearchService::class)->search(['bbox' => '101.5,3.0,101.8,3.3']);

    expect(collect($result['data'])->pluck('title')->all())
        ->toContain('Majlis MCP Dalam Kotak')
        ->not->toContain('Majlis MCP Luar Kotak')
        ->and($result['meta']['search']['filters']['bbox'])->toBe([
            'west' => 101.5,
            'south' => 3.0,
            'east' => 101.8,
            'north' => 3.3,
        ]);
});

it('validates bbox and polygon arguments for MCP event search', function (): void {
    $rules = array_intersect_key(
        app(McpEventSearchService::class)->validationRules(),
        array_flip(['bbox', 'polygon']),
    );

    $valid = Validator::make([
        'bbox' => '101.5,3.0,101.8,3.3',
        'polygon' => 'POLYGON((101.5 3.0, 101.8 3.0, 101.8 3.3, 101.5 3.3, 101.5 3.0))',
    ], $rules);

    $invalid = Validator::make([
        'bbox' => '101.8,3.0,101.5,3.3',
        'polygon' => 'POINT(101.5 3.0)',
    ], $rules);

    expect($valid->passes())->toBeTrue()
        ->and($invalid->fails())->toBeTrue()
        ->and($invalid->errors()->keys())->toBe(['bbox', 'polygon']);
});

it('normalizes stored spatial filters and rejects invalid values through the filter rules', function (): void {
    $normalizer = app(EventSearchFilterNormalizer::class);
    $polygon = 'POLYGON((101.5 3.0, 101.8 3.0, 101.8 3.3, 101.5 3.3, 101.5 3.0))';

    expect($normalizer->normalizeForStorage([
        'bbox' => ['west' => '101.5', 'south' => 3.0, 'east' => 101.8, 'north' => 3.3],
        'polygon' => '  '.$polygon.'  ',
    ]))->toBe([
        'bbox' => ['west' => 101.5, 'south' => 3.0, 'east' => 101.8, 'north' => 3.3],
        'polygon' => $polygon,
    ])
        ->and($normalizer->normalizeForStorage([
            'bbox' => '120,90,110,-90',
            'polygon' => 'not-a-polygon',
        ]))->toBeNull();

    $rules = array_intersect_key(
        app(EventSearchFilterRules::class)->rules([]),
        array_flip(['filters.bbox', 'filters.polygon']),
    );

    expect(Validator::make([
        'filters' => ['bbox' => '101.5,3.0,101.8,3.3', 'polygon' => $polygon],
    ], $rules)->passes())->toBeTrue()
        ->and(Validator::make([
            'filters' => ['bbox' => 'nope', 'polygon' => 'POINT(101.5 3.0)'],
        ], $rules)->fails())->toBeTrue();
});
