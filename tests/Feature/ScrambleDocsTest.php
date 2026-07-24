<?php

use App\Support\ApiDocumentation\ApiDocumentationUrlResolver;
use App\Support\ApiDocumentation\ApiDocumentationVersionResolver;
use Dedoc\Scramble\Generator;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

// The generated OpenAPI document is intentionally large. The application-wide
// array cache is isolated per refreshed test application, which regenerates and
// retains the document for every test until PHPUnit reaches its memory limit.
// Use the normal local file cache for this suite so the artifact is reused while
// keeping the lock and stale-cache paths real.
beforeEach(function (): void {
    config()->set('cache.default', 'file');
});

it('serves scramble docs only on the api host', function () {
    $this->get('https://api.ilmu360.test/docs', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $this->get('https://ilmu360.test/docs', [
        'Host' => 'ilmu360.test',
    ])->assertNotFound();
});

it('keeps the docs landing page lightweight by loading docs json lazily', function () {
    mock(Generator::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    $this->get('https://api.ilmu360.test/docs', [
        'Host' => 'api.ilmu360.test',
    ])
        ->assertOk()
        ->assertSee('docs.apiDescriptionUrl =', false)
        ->assertSee('/docs.json', false)
        ->assertDontSee('apiDescriptionDocument =', false);
});

it('serves scramble docs publicly on the api host outside local environments', function () {
    $originalEnvironment = app()->environment();
    app()['env'] = 'production';

    try {
        $this->get('https://api.ilmu360.test/docs', [
            'Host' => 'api.ilmu360.test',
        ])->assertOk();

        $this->getJson('https://api.ilmu360.test/docs.json', [
            'Host' => 'api.ilmu360.test',
        ])->assertOk();
    } finally {
        app()['env'] = $originalEnvironment;
    }
});

it('publishes openapi json on the api host with the api v1 server url', function () {
    $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('servers.0.url', 'https://api.ilmu360.test/api/v1');
});

it('publishes a lightweight documentation index with focused specification links', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs/index.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    expect($response->json('openapi'))->toBe('3.1.0')
        ->and($response->json('complete_spec_url'))->toBe('https://api.ilmu360.test/docs.json')
        ->and($response->json('human_docs_url'))->toBe('https://api.ilmu360.test/docs')
        ->and($response->json('sections'))->toContainEqual([
            'key' => 'events',
            'title' => 'Events',
            'description' => 'Event discovery, event details, attendance, and event submissions.',
            'url' => 'https://api.ilmu360.test/docs/events.json',
        ]);
});

it('publishes focused openapi documents from the canonical cached specification', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs/events.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');

    expect($response->json('openapi'))->toBe('3.1.0')
        ->and($response->json('x-ilmu360-section'))->toBe('events')
        ->and($response->json('x-ilmu360-complete-spec'))->toBe('https://api.ilmu360.test/docs.json')
        ->and($paths)->not->toBeEmpty()
        ->and($paths['/events']['get'] ?? null)->not->toBeNull()
        ->and($paths['/admin/manifest'] ?? null)->toBeNull();
});

it('keeps documentation index and focused specifications on the api host', function () {
    $this->getJson('https://ilmu360.test/docs/index.json', [
        'Host' => 'ilmu360.test',
    ])->assertNotFound();

    $this->getJson('https://ilmu360.test/docs/events.json', [
        'Host' => 'ilmu360.test',
    ])->assertNotFound();
});

it('serves docs json with cache and etag headers for agent and cdn clients', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    expect((string) $response->headers->get('Cache-Control'))
        ->toContain('public')
        ->toContain('max-age=300')
        ->toContain('s-maxage=3600')
        ->toContain('stale-while-revalidate=86400')
        ->and((string) $response->headers->get('ETag'))->not->toBe('')
        ->and((string) $response->headers->get('Vary'))->toContain('Accept');
});

it('caches docs json generation between requests', function () {
    forgetDocsJsonCacheKeys('release-a');

    mock(ApiDocumentationVersionResolver::class, function (MockInterface $mock): void {
        $mock->shouldReceive('current')
            ->twice()
            ->andReturn('release-a');
    });

    mock(Generator::class, function (MockInterface $mock): void {
        $mock->shouldReceive('__invoke')
            ->once()
            ->andReturn([
                'openapi' => '3.1.0',
                'info' => ['title' => 'ilmu360° API'],
                'servers' => [['url' => 'https://api.ilmu360.test/api/v1']],
            ]);
    });

    try {
        $firstResponse = $this->getJson('https://api.ilmu360.test/docs.json', [
            'Host' => 'api.ilmu360.test',
        ])->assertOk();

        $secondResponse = $this->getJson('https://api.ilmu360.test/docs.json', [
            'Host' => 'api.ilmu360.test',
        ])->assertOk();

        expect($firstResponse->json())->toBe($secondResponse->json());
    } finally {
        forgetDocsJsonCacheKeys('release-a');
    }
});

it('serves stale docs json when lock acquisition times out', function () {
    forgetDocsJsonCacheKeys('lock-timeout-current', 'lock-timeout-previous');

    mock(ApiDocumentationVersionResolver::class, function (MockInterface $mock): void {
        $mock->shouldReceive('current')->once()->andReturn('lock-timeout-current');
    });

    mock(Generator::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    $currentCacheKey = docsJsonCacheKey('lock-timeout-current');
    $previousCacheKey = docsJsonCacheKey('lock-timeout-previous');
    $latestCacheKeyPointer = docsJsonLatestKeyPointer();

    Cache::forever($previousCacheKey, [
        'openapi' => '3.1.0',
        'info' => ['title' => 'ilmu360° API Stale'],
        'servers' => [['url' => 'https://api.ilmu360.test/api/v1']],
    ]);
    Cache::forever($latestCacheKeyPointer, $previousCacheKey);

    // A previous fatal test process can leave a file-store lock behind; clean
    // that test-owned lock before acquiring it so the timeout scenario is
    // deterministic on reruns.
    Cache::lock($currentCacheKey.':lock', 120)->forceRelease();
    $lock = Cache::lock($currentCacheKey.':lock', 120);
    expect($lock->get())->toBeTrue();

    try {
        $this->getJson('https://api.ilmu360.test/docs.json', [
            'Host' => 'api.ilmu360.test',
        ])
            ->assertOk()
            ->assertJsonPath('info.title', 'ilmu360° API Stale');

        expect(Cache::get($latestCacheKeyPointer))->toBe($previousCacheKey);
    } finally {
        $lock->forceRelease();
        Cache::clearResolvedInstances();
        forgetDocsJsonCacheKeys('lock-timeout-current', 'lock-timeout-previous');
    }

    expect(Cache::get($currentCacheKey))->toBeNull();
});

it('busts docs json cache automatically when the documentation fingerprint changes', function () {
    forgetDocsJsonCacheKeys('release-a', 'release-b');

    $releaseAKey = docsJsonCacheKey('release-a');
    $releaseBKey = docsJsonCacheKey('release-b');
    $latestCacheKeyPointer = docsJsonLatestKeyPointer();

    mock(ApiDocumentationVersionResolver::class, function (MockInterface $mock): void {
        $mock->shouldReceive('current')
            ->twice()
            ->andReturn('release-a', 'release-b');
    });

    mock(Generator::class, function (MockInterface $mock): void {
        $mock->shouldReceive('__invoke')
            ->twice()
            ->andReturn(
                [
                    'openapi' => '3.1.0',
                    'info' => ['title' => 'ilmu360° API Release A'],
                    'servers' => [['url' => 'https://api.ilmu360.test/api/v1']],
                ],
                [
                    'openapi' => '3.1.0',
                    'info' => ['title' => 'ilmu360° API Release B'],
                    'servers' => [['url' => 'https://api.ilmu360.test/api/v1']],
                ],
            );
    });

    try {
        $firstResponse = $this->getJson('https://api.ilmu360.test/docs.json', [
            'Host' => 'api.ilmu360.test',
        ])->assertOk();

        $secondResponse = $this->getJson('https://api.ilmu360.test/docs.json', [
            'Host' => 'api.ilmu360.test',
        ])->assertOk();

        expect($firstResponse->json('info.title'))->toBe('ilmu360° API Release A')
            ->and($secondResponse->json('info.title'))->toBe('ilmu360° API Release B')
            ->and(Cache::get($releaseAKey))->toBeNull()
            ->and(Cache::get($releaseBKey))->toBeArray()
            ->and(data_get(Cache::get($releaseBKey), 'info.title'))->toBe('ilmu360° API Release B')
            ->and(Cache::get($latestCacheKeyPointer))->toBe($releaseBKey);
    } finally {
        forgetDocsJsonCacheKeys('release-a', 'release-b');
    }
});

it('changes the documentation fingerprint when scramble runtime config changes', function () {
    $resolver = app(ApiDocumentationVersionResolver::class);
    $baselineFingerprint = $resolver->current();
    $originalVersion = config('scramble.info.version');

    try {
        config()->set('scramble.info.version', 'docs-config-test-version');

        expect($resolver->current())->not->toBe($baselineFingerprint);
    } finally {
        config()->set('scramble.info.version', $originalVersion);
    }
});

it('groups speaker endpoints under a dedicated speaker tag in scramble docs', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');

    expect($paths['/speakers']['get']['tags'] ?? null)->toContain('Person')
        ->and($paths['/persons/{personKey}']['get']['tags'] ?? null)->toContain('Person');
});

it('groups other public directory endpoints under dedicated entity tags in scramble docs', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');

    expect($paths['/institutions']['get']['tags'] ?? null)->toContain('Institution')
        ->and($paths['/institutions/{institutionKey}']['get']['tags'] ?? null)->toContain('Institution')
        ->and($paths['/references']['get']['tags'] ?? null)->toContain('Reference')
        ->and($paths['/venues/{venueKey}']['get']['tags'] ?? null)->toContain('Venue')
        ->and($paths['/references/{referenceKey}']['get']['tags'] ?? null)->toContain('Reference')
        ->and($paths['/series/{series}']['get']['tags'] ?? null)->toContain('Series');
});

it('publishes named speaker institution and reference schemas for the public directory endpoints', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');
    $schemas = $response->json('components.schemas');
    $institutionsNearParameters = collect(data_get($paths, '/institutions/near.get.parameters', []))->keyBy('name');

    $searchParameters = collect(data_get($paths, '/search.get.parameters', []))->pluck('name')->all();

    expect($schemas)->toHaveKeys([
        'Person',
        'PersonListItem',
        'PersonDirectoryItem',
        'Institution',
        'InstitutionListItem',
        'InstitutionDirectoryItem',
        'ReferenceListItem',
        'ReferenceDirectoryItem',
    ])
        ->and(data_get($schemas, 'Person.properties.gender'))->not->toBeNull()
        ->and(data_get($schemas, 'PersonListItem.properties.status.type'))->toBe('string')
        ->and(data_get($schemas, 'PersonListItem.properties.gender'))->not->toBeNull()
        ->and(data_get($schemas, 'PersonDirectoryItem.properties.status.type'))->toBe('string')
        ->and(data_get($schemas, 'PersonDirectoryItem.properties.gender'))->not->toBeNull()
        ->and(data_get($schemas, 'Institution.properties.type'))->not->toBeNull()
        ->and(data_get($schemas, 'InstitutionListItem.properties.distance_km'))->not->toBeNull()
        ->and(data_get($schemas, 'InstitutionListItem.properties.type'))->not->toBeNull()
        ->and(data_get($schemas, 'InstitutionDirectoryItem.properties.type'))->not->toBeNull()
        ->and(data_get($schemas, 'ReferenceListItem.properties.display_title.type'))->toBe('string')
        ->and(data_get($schemas, 'ReferenceListItem.properties.parent_reference_id'))->not->toBeNull()
        ->and(data_get($schemas, 'ReferenceListItem.properties.part_type'))->not->toBeNull()
        ->and(data_get($schemas, 'ReferenceListItem.properties.part_number'))->not->toBeNull()
        ->and(data_get($schemas, 'ReferenceListItem.properties.part_label'))->not->toBeNull()
        ->and(data_get($schemas, 'ReferenceListItem.properties.is_part.type'))->toBe('boolean')
        ->and(data_get($schemas, 'ReferenceListItem.properties.front_cover_url'))->not->toBeNull()
        ->and(data_get($schemas, 'ReferenceDirectoryItem.properties.display_title.type'))->toBe('string')
        ->and(data_get($schemas, 'ReferenceDirectoryItem.properties.publication_year'))->not->toBeNull()
        ->and(collect(data_get($paths, '/institutions.get.parameters', []))->pluck('name')->all())->toContain('lat', 'lng', 'near', 'radius_km', 'fields')
        ->and(collect(data_get($paths, '/institutions/near.get.parameters', []))->pluck('name')->all())->toContain('near', 'radius_km', 'fields')
        ->and(data_get($institutionsNearParameters->get('lat'), 'schema.type'))->toBe('number')
        ->and(data_get($institutionsNearParameters->get('lng'), 'schema.type'))->toBe('number')
        ->and(collect(data_get($paths, '/speakers.get.parameters', []))->pluck('name')->all())->toContain('fields')
        ->and(collect(data_get($paths, '/references.get.parameters', []))->pluck('name')->all())->toContain('fields', 'search', 'following', 'page', 'per_page')
        ->and(collect(data_get($paths, '/references/{referenceKey}.get.parameters', []))->pluck('name')->all())->toContain('include_all_parts')
        ->and(data_get($paths, '/speakers.get.responses.200.content.application/json.schema'))->not->toBeNull()
        ->and(data_get($paths, '/persons/{personKey}.get.responses.200.content.application/json.schema'))->not->toBeNull()
        ->and(data_get($paths, '/institutions.get.responses.200.content.application/json.schema'))->not->toBeNull()
        ->and(data_get($paths, '/institutions/near.get.responses.200.content.application/json.schema'))->not->toBeNull()
        ->and(data_get($paths, '/institutions/{institutionKey}.get.responses.200.content.application/json.schema'))->not->toBeNull()
        ->and(data_get($paths, '/references.get.responses.200.content.application/json.schema'))->not->toBeNull()
        ->and(data_get($paths, '/references/{referenceKey}.get.responses.200.content.application/json.schema'))->not->toBeNull()
        ->and(data_get($schemas, 'EventSummary.properties.institution.properties.type'))->not->toBeNull()
        ->and(data_get($schemas, 'EventSummary.properties.speakers.items.properties.gender'))->not->toBeNull();
});

it('documents share payload and tracking endpoints for client integrations', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');

    expect($paths['/share/payload']['get']['tags'] ?? null)->toContain('Share')
        ->and($paths['/share/track']['post']['tags'] ?? null)->toContain('Share')
        ->and($paths['/share/analytics']['get']['tags'] ?? null)->toContain('Share')
        ->and($paths['/share/analytics/links/{link}']['get']['tags'] ?? null)->toContain('Share')
        ->and(collect(data_get($paths, '/share/payload.get.parameters', []))->pluck('name')->all())->toContain('url', 'text', 'title', 'origin')
        ->and(collect(data_get($paths, '/share/analytics.get.parameters', []))->pluck('name')->all())->toContain('type', 'sort', 'status', 'outcome', 'page', 'per_page')
        ->and(collect(data_get($paths, '/share/analytics/links/{link}.get.parameters', []))->pluck('name')->all())->toContain('link')
        ->and(data_get($paths, '/share/track.post.requestBody.content.application/json.schema'))->not->toBeNull()
        ->and(data_get($paths, '/share/analytics.get.responses.200.content.application/json.schema'))->not->toBeNull()
        ->and(data_get($paths, '/share/analytics/links/{link}.get.responses.200.content.application/json.schema'))->not->toBeNull();
});

it('documents the native mobile telemetry contract and endpoint for real app clients', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');
    $schemas = $response->json('components.schemas');
    $tags = collect($response->json('tags'))->keyBy('name');

    expect($paths['/forms/mobile-telemetry']['get']['summary'] ?? null)->toBe('Get mobile-telemetry field contract')
        ->and($paths['/forms/mobile-telemetry']['get']['description'] ?? null)->toContain('not for mobile web browsing')
        ->and($paths['/mobile/telemetry/events']['post']['summary'] ?? null)->toBe('Record native mobile telemetry')
        ->and($paths['/mobile/telemetry/events']['post']['description'] ?? null)->toContain('X-Ilmu360-Client-Origin')
        ->and($paths['/mobile/telemetry/events']['post']['description'] ?? null)->toContain('not for mobile web browsing')
        ->and($paths['/mobile/telemetry/events']['post']['tags'] ?? null)->toContain('Telemetry')
        ->and(data_get($paths, '/mobile/telemetry/events.post.requestBody.content.application/json.schema'))->not->toBeNull()
        ->and(data_get($paths, '/mobile/telemetry/events.post.responses.202.content.application/json.schema.$ref'))->toBe('#/components/schemas/MobileTelemetryAcceptedResponse')
        ->and(data_get($paths, '/forms/mobile-telemetry.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/MobileTelemetryFormResponse')
        ->and(data_get($paths, '/mobile/telemetry/events.post.requestBody.content.application/json.example.events.0.event_name'))->toBe('screen.viewed')
        ->and(data_get($schemas, 'MobileTelemetryFormResponse.properties.data.properties.required_headers.type'))->toBe('array')
        ->and(data_get($schemas, 'MobileTelemetryFormResponse.properties.data.properties.event_fields.type'))->toBe('array')
        ->and(data_get($schemas, 'MobileTelemetryAcceptedResponse.properties.data.properties.recorded_events.type'))->toBe('integer')
        ->and($tags->get('Telemetry')['description'] ?? null)->toContain('not for mobile web browsing');
});

it('documents sparse event list fields for public event index clients', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');
    $schemas = $response->json('components.schemas');

    expect(collect(data_get($paths, '/events.get.parameters', []))->pluck('name')->all())
        ->toContain('fields')
        ->and(data_get($paths, '/events.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/EventIndexResponse')
        ->and(data_get($schemas, 'EventListItem.properties.card_image_url'))->not->toBeNull()
        ->and(data_get($schemas, 'EventSummary.properties.starts_at_local'))->not->toBeNull()
        ->and(data_get($schemas, 'EventSummary.properties.starts_on_local_date'))->not->toBeNull()
        ->and(data_get($schemas, 'EventSummary.properties.ends_at_local'))->not->toBeNull()
        ->and(data_get($schemas, 'EventSummary.required'))->toContain('title', 'starts_at_local', 'starts_on_local_date', 'ends_at_local')
        ->and(data_get($schemas, 'EventIndexResponse.properties.meta.properties.pagination.properties.has_more'))->not->toBeNull()
        ->and(data_get($schemas, 'EventIndexResponse.properties.meta.properties.pagination.properties.next_page'))->not->toBeNull();
});

it('exposes the admin api foundation in scramble docs under dedicated admin tags', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');

    expect($paths['/admin/manifest']['get']['tags'] ?? null)->toContain('Admin Manifest')
        ->and($paths['/admin/catalogs/countries']['get']['tags'] ?? null)->toContain('Admin Catalog')
        ->and($paths['/admin/catalogs/states']['get']['tags'] ?? null)->toContain('Admin Catalog')
        ->and($paths['/admin/catalogs/admin-area-level-1']['get']['tags'] ?? null)->toContain('Admin Catalog')
        ->and($paths['/admin/catalogs/admin-area-level-2']['get']['tags'] ?? null)->toContain('Admin Catalog')
        ->and($paths['/admin/{resourceKey}']['get']['tags'] ?? null)->toContain('Admin Resource')
        ->and($paths['/admin/{resourceKey}']['post']['tags'] ?? null)->toContain('Admin Resource')
        ->and($paths['/admin/{resourceKey}/meta']['get']['tags'] ?? null)->toContain('Admin Resource')
        ->and($paths['/admin/{resourceKey}/schema']['get']['tags'] ?? null)->toContain('Admin Resource')
        ->and($paths['/admin/{resourceKey}/{recordKey}']['get']['tags'] ?? null)->toContain('Admin Resource')
        ->and($paths['/admin/{resourceKey}/{recordKey}']['put']['tags'] ?? null)->toContain('Admin Resource');
});

it('documents how clients obtain bearer tokens for authenticated api access', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');

    expect((string) $response->json('info.description'))
        ->toContain('POST /auth/login')
        ->toContain('Authorization: Bearer {token}')
        ->toContain('Admin > Authz > User > API Access')
        ->not->toContain('Account Settings > API Access')
        ->and($paths['/auth/login']['post']['tags'] ?? null)->toContain('Authentication')
        ->and($paths['/auth/register']['post']['tags'] ?? null)->toContain('Authentication')
        ->and($paths['/auth/logout']['post']['tags'] ?? null)->toContain('Authentication');
});

it('documents public and admin mutation capability boundaries in the api overview', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    expect((string) $response->json('info.description'))
        ->toContain("Canonical API documentation for ilmu360° client and platform integrations.\n\nGet an access token")
        ->toContain("\n\n---\n\nAI QUICKSTART:\n")
        ->toContain('https://api.ilmu360.test/docs')
        ->toContain('https://api.ilmu360.test/docs.json')
        ->toContain("\n\nROUTING SURFACES:\n")
        ->toContain("\n\nTIMEZONE:\n")
        ->toContain('Resource manifests now expose explicit `mcp_tools` for collection, meta, schema, store, and update call surfaces; use those tool names and argument templates instead of guessing URLs.')
        ->toContain('Event discovery supports `filter[starts_on_local_date]=YYYY-MM-DD` and returns `starts_at_local` / `starts_on_local_date` in event payloads.')
        ->toContain('Enum filters and write fields use enum backing values such as `kuliah_ceramah`, `all_ages`, and `prayer_relative`, not display labels such as `Kuliah / Ceramah`.')
        ->toContain('Event collections expose explicit filters such as filter[status], filter[visibility], filter[event_format], filter[event_category_ids], filter[timing_mode], and filter[prayer_reference]. Person collections expose filter[status], filter[is_active], and filter[has_events]. Date-aware admin resources also accept starts_after, starts_before, and starts_on_local_date.')
        ->toContain('use the admin record `route_key` returned by admin collection or detail payloads')
        ->toContain('If you only have a public UUID-backed payload and route_key is unavailable, use the UUID id directly as recordKey.')
        ->toContain('Collection endpoints clamp per_page to server-supported maxima')
        ->toContain('Get the update schema using the route_key returned by the record detail payload')
        ->toContain('PUT /api/v1/admin/persons/ahmad-fauzi-my')
        ->toContain('Public create flows currently exist for events, institutions, and speakers.')
        ->toContain('must include an explicit country selection')
        ->toContain('Public update flows currently exist for events, institutions, speakers, and references')
        ->toContain('does not currently include creating references, venues, or series')
        ->toContain('GET /forms/*')
        ->toContain('GET /admin/manifest')
        ->toContain('GET /admin/{resourceKey}/schema?operation=create')
        ->toContain('GET /admin/catalogs/*')
        ->toContain('GET /catalogs/spaces returns only global spaces when institution_id is omitted')
        ->toContain('GET /institution-workspace auto-selects the first accessible institution when institution_id is omitted')
        ->not->toContain('The recordKey parameter must be the UUID primary key')
        ->not->toContain('Get the update schema using the id (UUID primary key, not the slug)')
        ->toContain('Current admin write support includes events, institutions, speakers, references, venues, and subdistricts.');
});

it('documents utc transport fields and request-timezone helper behavior clearly', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    expect((string) $response->json('info.description'))
        ->toContain('Raw API timestamp fields are stored and returned in UTC')
        ->toContain('Viewer-facing helper fields such as event timing_display and end_time_display are localized only when the request provides timezone context')
        ->toContain('Without timezone context, bare API requests fall back to UTC.')
        ->toContain('Public event date-only filters use `filter[starts_after]`, `filter[starts_before]`, and `filter[starts_on_local_date]`. Admin resource date-only filters use top-level `starts_after`, `starts_before`, and `starts_on_local_date`.')
        ->toContain('send X-Timezone: Asia/Kuala_Lumpur with either public-event values such as filter[starts_after]=2026-04-12&filter[starts_before]=2026-04-12 or admin-resource values such as starts_after=2026-04-12&starts_before=2026-04-12')
        ->toContain('If you omit timezone context, the same filter values are interpreted in UTC instead.')
        ->not->toContain('The server timezone is UTC; the default display timezone is Asia/Kuala_Lumpur (MYT, UTC+8).')
        ->not->toContain('All date/time filter values must be expressed in UTC.');
});

it('keeps live api routes and generated scramble operations aligned', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');

    expect($paths)->toBeArray();

    $httpMethods = ['get', 'post', 'put', 'patch', 'delete', 'options'];
    $documentedOperations = [];

    foreach ($paths as $path => $operations) {
        if (! is_array($operations)) {
            continue;
        }

        foreach ($operations as $method => $operation) {
            $normalizedMethod = strtolower((string) $method);

            if (! in_array($normalizedMethod, $httpMethods, true)) {
                continue;
            }

            $documentedOperations[$normalizedMethod.' '.$path] = is_array($operation) ? $operation : [];
        }
    }

    $liveOperations = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! Str::startsWith($uri, 'api/v1')) {
            continue;
        }

        $normalizedPath = '/'.ltrim((string) Str::of($uri)->after('api/v1'), '/');
        $normalizedPath = preg_replace('/\{([^}:]+):[^}]+\}/', '{$1}', $normalizedPath);
        $normalizedPath = is_string($normalizedPath) ? $normalizedPath : '/';

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $normalizedMethod = strtolower((string) $method);
            $key = $normalizedMethod.' '.$normalizedPath;

            $liveOperations[$key] = [
                'path' => $normalizedPath,
                'method' => $normalizedMethod,
                'name' => $route->getName(),
                'requires_auth' => collect($route->gatherMiddleware())->contains(
                    static fn (mixed $middleware): bool => is_string($middleware)
                        && ($middleware === 'auth'
                            || Str::startsWith($middleware, 'auth:')
                            || $middleware === Authenticate::class
                            || Str::startsWith($middleware, Authenticate::class.':')),
                ),
            ];
        }
    }

    $findDocumentedOperation = static function (string $method, string $path) use ($documentedOperations): ?array {
        $key = $method.' '.$path;

        if (array_key_exists($key, $documentedOperations)) {
            return ['key' => $key, 'operation' => $documentedOperations[$key]];
        }

        if ($method === 'patch' && array_key_exists('put '.$path, $documentedOperations)) {
            return ['key' => 'put '.$path, 'operation' => $documentedOperations['put '.$path]];
        }

        if ($method === 'put' && array_key_exists('patch '.$path, $documentedOperations)) {
            return ['key' => 'patch '.$path, 'operation' => $documentedOperations['patch '.$path]];
        }

        return null;
    };

    $missingFromDocs = [];
    $authMismatches = [];
    $summaryGaps = [];
    $responseGaps = [];
    $coveredDocumentationKeys = [];

    foreach ($liveOperations as $key => $operation) {
        $documentedOperationMatch = $findDocumentedOperation($operation['method'], $operation['path']);

        if ($documentedOperationMatch === null) {
            $missingFromDocs[] = $key.' ['.($operation['name'] ?? 'unnamed').']';

            continue;
        }

        $coveredDocumentationKeys[] = $documentedOperationMatch['key'];

        $documentedOperation = $documentedOperationMatch['operation'];

        $hasSecurity = is_array($documentedOperation['security'] ?? null) && ($documentedOperation['security'] ?? []) !== [];

        if ($operation['requires_auth'] !== $hasSecurity) {
            $authMismatches[] = $key.' auth='.($operation['requires_auth'] ? 'required' : 'public');
        }

        if (blank($documentedOperation['summary'] ?? null)) {
            $summaryGaps[] = $key;
        }

        $responses = is_array($documentedOperation['responses'] ?? null) ? $documentedOperation['responses'] : [];
        $hasSuccessResponse = collect(array_keys($responses))
            ->contains(static fn (mixed $status): bool => Str::startsWith((string) $status, '2'));

        if (! $hasSuccessResponse) {
            $responseGaps[] = $key;
        }
    }

    $coveredDocumentationKeys = array_unique($coveredDocumentationKeys);
    $extraDocs = [];

    foreach (array_keys($documentedOperations) as $documentedKey) {
        if (in_array($documentedKey, $coveredDocumentationKeys, true)) {
            continue;
        }

        [$method, $path] = explode(' ', $documentedKey, 2);

        if ($method === 'put' && array_key_exists('patch '.$path, $liveOperations)) {
            continue;
        }

        if ($method === 'patch' && array_key_exists('put '.$path, $liveOperations)) {
            continue;
        }

        $extraDocs[] = $documentedKey;
    }

    expect($missingFromDocs)->toBe([])
        ->and($extraDocs)->toBe([])
        ->and($authMismatches)->toBe([])
        ->and($summaryGaps)->toBe([])
        ->and($responseGaps)->toBe([])
        ->and(array_key_exists('/saved-searches/{savedSearch}', $paths))->toBeTrue()
        ->and(array_key_exists('/saved-searches/{saved_search}', $paths))->toBeFalse();
});

it('does not leak local-only docs urls into the published api description', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    expect((string) $response->json('info.description'))
        ->not->toContain('https://ilmu360.test/docs')
        ->not->toContain('https://ilmu360.test/docs.json')
        ->toContain('published at `/docs.json`');
});

it('adds workflow summaries to public contract and mutation endpoints', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');

    expect($paths['/manifest']['get']['summary'] ?? null)->toBe('Discover public client flows')
        ->and($paths['/forms/mobile-telemetry']['get']['summary'] ?? null)->toBe('Get mobile-telemetry field contract')
        ->and($paths['/forms/submit-event']['get']['summary'] ?? null)->toBe('Get submit-event field contract')
        ->and($paths['/submit-event']['post']['summary'] ?? null)->toBe('Submit a public event')
        ->and($paths['/submit-event']['post']['description'] ?? null)->toContain('This route is create-only')
        ->and($paths['/submit-event']['post']['description'] ?? null)->toContain('submission_country_id')
        ->and($paths['/submit-event']['post']['description'] ?? null)->not->toContain('submission_country_code')
        ->and($paths['/mobile/telemetry/events']['post']['summary'] ?? null)->toBe('Record native mobile telemetry')
        ->and($paths['/mobile/telemetry/events']['post']['description'] ?? null)->toContain('anonymous_id or session_identifier')
        ->and($paths['/forms/contributions/institutions']['get']['description'] ?? null)->toContain('canonical Google Maps URL')
        ->and($paths['/contributions/institutions']['post']['summary'] ?? null)->toBe('Create an institution contribution')
        ->and($paths['/contributions/institutions']['post']['description'] ?? null)->toContain('address.country_id')
        ->and($paths['/contributions/institutions']['post']['description'] ?? null)->toContain('canonical Google Maps URL')
        ->and($paths['/contributions/institutions']['post']['description'] ?? null)->not->toContain('address.country_code')
        ->and($paths['/contributions/speakers']['post']['summary'] ?? null)->toBe('Create a speaker contribution')
        ->and($paths['/contributions/speakers']['post']['description'] ?? null)->toContain('address.country_id')
        ->and($paths['/contributions/speakers']['post']['description'] ?? null)->not->toContain('address.country_code')
        ->and($paths['/forms/contributions/{subjectType}/{subject}/suggest']['get']['summary'] ?? null)->toBe('Get editable contribution context')
        ->and($paths['/forms/contributions/{subjectType}/{subject}/suggest']['get']['description'] ?? null)->toContain('event `cover`/`poster`/`gallery`')
        ->and($paths['/forms/institution-workspace']['get']['description'] ?? null)->toContain('workspace endpoint')
        ->and($paths['/contributions/{subjectType}/{subject}/suggest']['post']['summary'] ?? null)->toBe('Submit a contribution update')
        ->and($paths['/contributions/{subjectType}/{subject}/suggest']['post']['description'] ?? null)->toContain('direct_edit')
        ->and($paths['/contributions/{subjectType}/{subject}/suggest']['post']['description'] ?? null)->toContain('review');
});

it('documents admin schema-driven writes and dynamic payload discovery', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');
    $adminListParameters = collect(data_get($paths, '/admin/{resourceKey}.get.parameters', []))->pluck('name')->all();

    expect($paths['/admin/manifest']['get']['summary'] ?? null)->toBe('List admin resources and write support')
        ->and($paths['/admin/{resourceKey}/schema']['get']['summary'] ?? null)->toBe('Get admin write schema')
        ->and($paths['/admin/{resourceKey}/schema']['get']['description'] ?? null)->toContain('mutation payloads are resource-specific')
        ->and($paths['/admin/{resourceKey}']['post']['summary'] ?? null)->toBe('Create an admin resource record')
        ->and($paths['/admin/{resourceKey}']['post']['description'] ?? null)->toContain('fetch `GET /admin/{resourceKey}/schema?operation=create` first')
        ->and($adminListParameters)->toContain('filter[visibility]', 'filter[event_format]', 'filter[event_category_ids]', 'filter[timing_mode]', 'filter[prayer_reference]')
        ->and($paths['/admin/{resourceKey}/{recordKey}/relations/{relation}']['get']['summary'] ?? null)->toBe('List admin related records')
        ->and($paths['/admin/{resourceKey}/{recordKey}/relations/{relation}']['get']['description'] ?? null)->toContain('Use the relation keys from `GET /admin/{resourceKey}/meta`')
        ->and($paths['/admin/{resourceKey}/{recordKey}']['put']['summary'] ?? null)->toBe('Update an admin resource record')
        ->and($paths['/admin/{resourceKey}/{recordKey}']['put']['description'] ?? null)->toContain('schema?operation=update&recordKey={recordKey}');
});

it('includes the mobile api reference in the docs description for ai and mobile consumers', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    expect((string) $response->json('info.description'))
        ->toContain('ilmu360° Mobile API Reference')
        ->toContain('If you are building an AI client, use this read order:')
        ->toContain('Android, iOS application developers, and AI agents');
});

it('publishes explicit auth response schemas for ai clients', function () {
    $script = <<<'PHP'
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create(
    'https://api.ilmu360.test/docs.json',
    'GET',
    [],
    [],
    [],
    [
        'HTTP_HOST' => 'api.ilmu360.test',
        'HTTP_ACCEPT' => 'application/json',
        'HTTPS' => 'on',
    ],
);

$response = $kernel->handle($request);

if ($response->getStatusCode() !== 200) {
    fwrite(STDERR, 'Unexpected status: '.$response->getStatusCode());
    exit(1);
}

echo $response->getContent();

$kernel->terminate($request, $response);
PHP;

    $process = new Process(
        [PHP_BINARY, '-r', $script],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'LARAVEL_PARALLEL_TESTING' => false,
            'PARATEST' => false,
            'TEST_TOKEN' => false,
            'UNIQUE_TEST_TOKEN' => false,
        ],
    );
    $process->mustRun();

    $spec = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $paths = $spec['paths'] ?? [];

    expect($paths['/auth/register']['post']['requestBody']['content']['application/json']['schema'] ?? null)->not->toBeNull()
        ->and($paths['/auth/register']['post']['summary'] ?? null)->toBe('Register and issue a bearer token')
        ->and($paths['/auth/register']['post']['description'] ?? null)->toContain('Supply either `email` or `phone`')
        ->and($paths['/auth/register']['post']['responses']['201']['description'] ?? null)->toBe('Successful registration response.')
        ->and($paths['/auth/register']['post']['responses']['201']['content']['application/json']['schema'] ?? null)->not->toBeNull()
        ->and($paths['/auth/login']['post']['summary'] ?? null)->toBe('Log in and issue a bearer token')
        ->and($paths['/auth/login']['post']['description'] ?? null)->toContain('Admin > Authz > User > API Access')
        ->and($paths['/auth/login']['post']['responses']['200']['description'] ?? null)->toBe('Successful login response.')
        ->and($paths['/auth/login']['post']['responses']['200']['content']['application/json']['schema'] ?? null)->not->toBeNull()
        ->and($paths['/auth/logout']['post']['summary'] ?? null)->toBe('Revoke the current bearer token')
        ->and($paths['/auth/logout']['post']['responses']['200']['description'] ?? null)->toBe('Successful logout response.')
        ->and($paths['/auth/logout']['post']['responses']['200']['content']['application/json']['schema'] ?? null)->not->toBeNull();
});

it('publishes sanctum bearer security metadata for authenticated api operations', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $securitySchemes = $response->json('components.securitySchemes');
    $paths = $response->json('paths');

    expect($securitySchemes)->toHaveKey('sanctumBearer')
        ->and($securitySchemes['sanctumBearer']['type'] ?? null)->toBe('http')
        ->and($securitySchemes['sanctumBearer']['scheme'] ?? null)->toBe('bearer')
        ->and((string) ($securitySchemes['sanctumBearer']['description'] ?? ''))->toContain('Authorization: Bearer {token}')
        ->and($paths['/auth/logout']['post']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/user']['get']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/forms/account-settings']['get']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/forms/github-issue-report']['get']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/github-issues']['post']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/events/{event}']['get']['security'] ?? null)->toBeNull()
        ->and($paths['/events/{event}/registrations']['post']['security'] ?? null)->toBeNull()
        ->and($paths['/events/{event}/me']['get']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/events/{event}/check-ins']['post']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/me/events/saved']['get']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/me/events/going']['get']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/notification-settings/catalog']['get']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/notifications']['get']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/notifications/read-all']['post']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/notification-destinations/push']['post']['security'][0]['sanctumBearer'] ?? null)->toBe([])
        ->and($paths['/auth/login']['post']['security'] ?? null)->toBeNull();
});

it('publishes explicit schemas for search manifest and public form contracts', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');
    $schemas = $response->json('components.schemas');
    $searchParameters = collect(data_get($paths, '/search.get.parameters', []))->pluck('name')->all();

    expect($schemas)->toHaveKeys([
        'SearchIndexResponse',
        'PublicManifestResponse',
        'PublicFormFieldContract',
        'PublicConditionalRule',
        'MobileTelemetryAcceptedResponse',
        'MobileTelemetryFormResponse',
        'SubmitEventFormResponse',
        'InstitutionContributionFormResponse',
        'SpeakerContributionFormResponse',
        'ReportFormResponse',
        'GitHubIssueReportFormResponse',
        'AccountSettingsFormResponse',
        'AdvancedEventFormResponse',
        'InstitutionWorkspaceFormResponse',
        'MembershipApplicationFormResponse',
        'ContributionSuggestContextResponse',
    ])
        ->and($searchParameters)->toContain('search', 'q')
        ->and(data_get($paths, '/search.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/SearchIndexResponse')
        ->and(data_get($paths, '/manifest.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/PublicManifestResponse')
        ->and(data_get($paths, '/forms/mobile-telemetry.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/MobileTelemetryFormResponse')
        ->and(data_get($paths, '/forms/submit-event.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/SubmitEventFormResponse')
        ->and(data_get($paths, '/forms/contributions/institutions.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/InstitutionContributionFormResponse')
        ->and(data_get($paths, '/forms/contributions/speakers.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/SpeakerContributionFormResponse')
        ->and(data_get($paths, '/forms/report.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/ReportFormResponse')
        ->and(data_get($paths, '/forms/github-issue-report.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/GitHubIssueReportFormResponse')
        ->and(data_get($paths, '/forms/account-settings.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/AccountSettingsFormResponse')
        ->and(data_get($paths, '/forms/advanced-events.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/AdvancedEventFormResponse')
        ->and(data_get($paths, '/forms/institution-workspace.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/InstitutionWorkspaceFormResponse')
        ->and(data_get($paths, '/forms/membership-applications/{subjectType}.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/MembershipApplicationFormResponse')
        ->and(data_get($paths, '/forms/contributions/{subjectType}/{subject}/suggest.get.responses.200.content.application/json.schema.$ref'))->toBe('#/components/schemas/ContributionSuggestContextResponse')
        ->and(data_get($schemas, 'AccountSettingsFormResponse.properties.data.properties.mcp_tokens_endpoint.type'))->toBe('string')
        ->and(data_get($schemas, 'AccountSettingsFormResponse.properties.data.properties.mcp_token_fields.type'))->toBe('array')
        ->and(data_get($schemas, 'SearchIndexResponse.properties.data.properties.speakers.properties.items.type'))->toBe('array')
        ->and(data_get($schemas, 'PublicFormFieldContract.properties.name.type'))->toBe('string')
        ->and(data_get($schemas, 'PublicConditionalRule.properties.field.type'))->toBe('string');
});

it('adds summaries and descriptions to catalog and authenticated workflow endpoints', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');
    $tags = collect($response->json('tags'))->keyBy('name');

    expect($paths['/catalogs/countries']['get']['summary'] ?? null)->toBe('List public countries catalog')
        ->and($paths['/catalogs/membership-application-subjects/{subjectType}']['get']['summary'] ?? null)->toBe('List membership-claim subjects')
        ->and($paths['/catalogs/spaces']['get']['description'] ?? null)->toContain('global space options when no `institution_id` is selected')
        ->and($paths['/me/events/going']['get']['summary'] ?? null)->toBe('List going events')
        ->and($paths['/me/events/saved']['get']['summary'] ?? null)->toBe('List saved events')
        ->and($paths['/events/{event}/me']['get']['summary'] ?? null)->toBe('Get current user event state')
        ->and($paths['/events/{event}/check-ins']['post']['summary'] ?? null)->toBe('Record an event check-in')
        ->and($paths['/account-settings']['get']['summary'] ?? null)->toBe('Get account settings')
        ->and($paths['/forms/github-issue-report']['get']['summary'] ?? null)->toBe('Get GitHub issue-report field contract')
        ->and($paths['/github-issues']['post']['summary'] ?? null)->toBe('Create a GitHub issue report')
        ->and($paths['/github-issues']['post']['description'] ?? null)->toContain('Non-admin users create a plain issue')
        ->and($paths['/institution-workspace']['get']['summary'] ?? null)->toBe('Get institution workspace')
        ->and($paths['/institution-workspace']['get']['description'] ?? null)->toContain('first accessible institution is selected automatically')
        ->and($paths['/membership-applications/{subjectType}/{subject}']['post']['summary'] ?? null)->toBe('Submit a membership application')
        ->and($paths['/reports']['post']['summary'] ?? null)->toBe('Submit a report')
        ->and($paths['/events/{event}/registrations/export']['get']['summary'] ?? null)->toBe('Export registrations as CSV')
        ->and($paths['/institution-workspace/{institutionId}/members/{memberId}']['delete']['summary'] ?? null)->toBe('Remove an institution member')
        ->and($paths['/follows/{type}/{subject}']['get']['description'] ?? null)->toContain('current authenticated user is following')
        ->and($paths['/follows/{type}/{subject}']['get']['description'] ?? null)->toContain('The `{subject}` path segment is required')
        ->and($paths['/follows/{type}/{subject}']['get']['description'] ?? null)->toContain('instead of plural `/follows/...` routes')
        ->and($paths['/follows/{type}/{subject}']['post']['description'] ?? null)->toContain('Creates a follow relationship')
        ->and($paths['/follows/{type}/{subject}']['delete']['description'] ?? null)->toContain('Removes the follow relationship')
        ->and($paths['/events/{event}']['get']['summary'] ?? null)->toBe('Get an event detail payload')
        ->and($paths['/events/{event}']['get']['description'] ?? null)->toContain('event detail payload by slug or UUID')
        ->and($tags->get('Catalog')['description'] ?? null)->toBe('Public lookup catalogs for geography, tags, languages, references, venues, and write-flow selectors.')
        ->and($tags->get('InstitutionWorkspace')['description'] ?? null)->toContain('member management')
        ->and($tags->get('RegistrationExport')['description'] ?? null)->toContain('CSV export');
});

it('publishes high-value request body examples for agentic write endpoints', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');

    expect(data_get($paths, '/auth/login.post.requestBody.content.application/json.example.login'))->toBe('superadmin@ilmu360.com')
        ->and(data_get($paths, '/auth/register.post.requestBody.content.application/json.example.device_name'))->toBe('OpenClaw iPhone')
        ->and(data_get($paths, '/account-settings.put.requestBody.content.application/json.example.timezone'))->toBe('Asia/Kuala_Lumpur')
        ->and(data_get($paths, '/notification-settings.put.requestBody.content.application/json.example.settings.preferred_channels.0'))->toBe('in_app')
        ->and(data_get($paths, '/events/{event}/registrations.post.requestBody.content.application/json.example.email'))->toBe('guest@example.com')
        ->and(data_get($paths, '/saved-searches.post.requestBody.content.application/json.example.notify'))->toBe('daily')
        ->and(data_get($paths, '/saved-searches/{savedSearch}.put.requestBody.content.application/json.example.notify'))->toBe('instant')
        ->and(data_get($paths, '/reports.post.requestBody.content.multipart/form-data.example.category'))->toBe('wrong_info')
        ->and(data_get($paths, '/reports.post.requestBody.content.multipart/form-data.example.evidence.0'))->toBe('poster-screenshot.jpg')
        ->and(data_get($paths, '/mobile/telemetry/events.post.requestBody.content.application/json.example.anonymous_id'))->toBe('ios-installation-123')
        ->and(data_get($paths, '/mobile/telemetry/events.post.requestBody.content.application/json.example.events.1.component'))->toBe('register_button')
        ->and(data_get($paths, '/github-issues.post.requestBody.content.application/json.example.category'))->toBe('docs_mismatch')
        ->and(data_get($paths, '/github-issues.post.requestBody.content.application/json.example.client_version'))->toBe('GPT-5.4');
});

it('publishes follow-up request examples for authenticated workflow mutations', function () {
    $response = $this->getJson('https://api.ilmu360.test/docs.json', [
        'Host' => 'api.ilmu360.test',
    ])->assertOk();

    $paths = $response->json('paths');

    expect(data_get($paths, '/notification-destinations/push.post.requestBody.content.application/json.example.installation_id'))->toBe('ios-installation-123')
        ->and(data_get($paths, '/notification-destinations/push/{installation}.put.requestBody.content.application/json.example.fcm_token'))->toBe('fcm-token-updated-xyz789')
        ->and(data_get($paths, '/membership-applications/{subjectType}/{subject}.post.requestBody.content.multipart/form-data.example.justification'))->toContain('mosque committee')
        ->and(data_get($paths, '/institution-workspace/{institutionId}/members.post.requestBody.content.application/json.example.email'))->toBe('member@example.com')
        ->and(data_get($paths, '/institution-workspace/{institutionId}/members/{memberId}.put.requestBody.content.application/json.example.role_id'))->toBe('institution_editor')
        ->and(data_get($paths, '/follows/{type}/{subject}.post.requestBody'))->toBeNull()
        ->and(data_get($paths, '/follows/{type}/{subject}.post.parameters.0.example'))->toBe('institution')
        ->and(data_get($paths, '/follows/{type}/{subject}.post.parameters.0.description'))->toContain('Use singular values only')
        ->and(data_get($paths, '/follows/{type}/{subject}.post.parameters.0.description'))->toContain('plural list paths such as `/follows/speakers` are not valid');
});

function docsJsonCacheScope(): string
{
    return sha1(implode('|', [
        app()->environment(),
        app(ApiDocumentationUrlResolver::class)->apiBaseUrl(),
        (string) config('scramble.api_domain', ''),
        (string) config('scramble.api_path', 'api/v1'),
    ]));
}

function docsJsonCacheKey(string $version): string
{
    return 'api-documentation:openapi-json:'.docsJsonCacheScope().':'.$version;
}

function docsJsonLatestKeyPointer(): string
{
    return 'api-documentation:openapi-json:latest-key:'.docsJsonCacheScope();
}

function forgetDocsJsonCacheKeys(string ...$versions): void
{
    foreach ($versions as $version) {
        Cache::forget(docsJsonCacheKey($version));
    }

    Cache::forget(docsJsonLatestKeyPointer());
}
