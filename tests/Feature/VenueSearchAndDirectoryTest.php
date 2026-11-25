<?php

declare(strict_types=1);

use App\Features\Places\Api\Enums\VenueType;
use App\Features\Places\Models\Venue;
use App\Features\Places\Queries\VenueDirectoryQuery;
use App\Features\Places\Services\VenueSearchService;
use App\Shared\Cache\DiscoveryCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;

beforeEach(function (): void {
    Cache::flush();
});

it('caches public venue search and fuzzy search ids and busts when venues change', function (): void {
    $venue = Venue::factory()->create([
        'name' => 'Dewan Al-Ghazali',
        'status' => 'verified',
    ]);

    $service = app(VenueSearchService::class);

    expect($service->publicSearchIds('Ghazali'))->toContain((string) $venue->id)
        ->and($service->publicFuzzySearchIds('Ghzali'))->toContain((string) $venue->id);

    $cache = app(DiscoveryCache::class);
    $versionBefore = $cache->version((string) config('discovery.cache.versions.venues_search'));

    $newVenue = Venue::factory()->create([
        'name' => 'Dewan Al-Ghazali Baru',
        'status' => 'verified',
    ]);

    $versionAfter = $cache->version((string) config('discovery.cache.versions.venues_search'));

    expect($versionAfter)->not->toBe($versionBefore)
        ->and($service->publicSearchIds('Ghazali'))->toContain((string) $newVenue->id);

    $newVenue->delete();

    expect($cache->version((string) config('discovery.cache.versions.venues_search')))->not->toBe($versionAfter)
        ->and($service->publicSearchIds('Ghazali'))->not->toContain((string) $newVenue->id);
});

it('paginates venues with window-function totals and scopes media eager loading to main and cover', function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $venue = Venue::factory()->create([
        'name' => 'Dewan Media Terhad',
        'venue_type' => VenueType::Dewan,
        'status' => 'verified',
    ]);

    $venue->addMedia(UploadedFile::fake()->image('cover.jpg'))->toMediaCollection('cover');
    $venue->addMedia(UploadedFile::fake()->image('gallery.jpg'))->toMediaCollection('gallery');

    Venue::factory()->create([
        'name' => 'Auditorium Media Lain',
        'venue_type' => VenueType::Auditorium,
        'status' => 'verified',
    ]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $paginator = app(VenueDirectoryQuery::class)->paginate(
        search: null,
        countryId: null,
        stateId: null,
        areas: [],
        venueType: VenueType::Dewan->value,
        page: 1,
        paginationMeta: ['path' => '/tempat', 'query' => []],
    );

    $queryLog = collect(DB::getQueryLog());
    $queries = $queryLog->pluck('query');
    DB::disableQueryLog();

    $mediaEntries = $queryLog->filter(static fn (array $entry): bool => str_contains((string) $entry['query'], '"media"'));
    /** @var Venue|null $hydratedVenue */
    $hydratedVenue = $paginator->items()[0] ?? null;

    expect($paginator->total())->toBe(1)
        ->and(collect($paginator->items())->pluck('id')->all())->toBe([(string) $venue->id])
        ->and($queries->filter(static fn (string $sql): bool => str_contains($sql, 'count(*) over ()')))->toHaveCount(1)
        ->and($queries->filter(static fn (string $sql): bool => str_contains($sql, 'count(*) as "aggregate"')))->toBeEmpty()
        ->and($mediaEntries)->not->toBeEmpty()
        ->and($mediaEntries->every(
            static fn (array $entry): bool => str_contains((string) $entry['query'], '"collection_name" in')
                && in_array('main', $entry['bindings'] ?? [], true)
                && in_array('cover', $entry['bindings'] ?? [], true),
        ))->toBeTrue()
        ->and($hydratedVenue?->media->pluck('collection_name')->all())->toBe(['cover']);
});

it('supports fuzzy venue search in the public directory', function (): void {
    Venue::factory()->create([
        'name' => 'Dewan Riyadhus Solihin',
        'status' => 'verified',
    ]);

    get('/tempat?search=Riyadhsu')
        ->assertSuccessful()
        ->assertSee('Dewan Riyadhus Solihin');
});
