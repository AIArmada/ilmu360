<?php

declare(strict_types=1);

use App\Features\Events\Models\Event;
use App\Features\Events\Models\Series;
use App\Features\Places\Models\Venue;
use App\Features\References\Models\Reference;
use App\Models\User;
use App\Platform\ApiDocumentation\ApiDocumentationDocumentResolver;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

/**
 * Couples the published OpenAPI schema to actual HTTP payloads for the
 * operations normalized by PublishedContractSchemasContributor. The document
 * snapshot prevents silent schema drift; the HTTP assertions prevent the
 * patcher from describing a payload the API no longer returns.
 */
beforeEach(function (): void {
    Cache::flush();
    $this->published = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/OpenApi/published-contract.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
});

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $response
 */
function assertPublishedTopLevelKeys(array $payload, array $response): void
{
    $schema = $response['content']['application/json']['schema'];

    expect(array_keys($payload))->toEqualCanonicalizing(array_keys($schema['properties'] ?? []));

    if (isset($schema['properties']['meta']['properties'])) {
        expect(array_keys($payload['meta'] ?? []))
            ->toEqualCanonicalizing(array_keys($schema['properties']['meta']['properties']));
    }
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $response
 */
function assertPublishedDataKeys(array $payload, array $response): void
{
    $schema = $response['content']['application/json']['schema'];

    expect(array_keys($payload['data'] ?? []))
        ->toEqualCanonicalizing(array_keys($schema['properties']['data']['properties'] ?? []));
}

/**
 * Check actual nested response values against the frozen contract's primitive
 * types, including nullable pagination fields. Generic payloads remain generic.
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $response
 * @param list<string> $path
 */
function assertPublishedSchemaValueType(array $payload, array $response, array $path): void
{
    $schema = $response['content']['application/json']['schema'];
    $value = $payload;

    foreach ($path as $part) {
        expect($value)->toBeArray()->toHaveKey($part);
        $value = $value[$part];

        $schema = $schema['properties'][$part] ?? null;
        expect($schema)->toBeArray();
    }

    $types = (array) ($schema['type'] ?? []);

    expect($types)->not->toBeEmpty();

    $matches = array_any($types, static fn (string $type): bool => match ($type) {
        'null' => $value === null,
        'string' => is_string($value),
        'integer' => is_int($value),
        'number' => is_int($value) || is_float($value),
        'boolean' => is_bool($value),
        'array' => is_array($value) && array_is_list($value),
        'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
        default => false,
    });

    expect($matches)->toBeTrue('Unexpected response type at '.implode('.', $path));
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $response
 */
function assertPublishedDetailTypes(array $payload, array $response, string $subject): void
{
    foreach ([$subject, 'upcoming_events', 'past_events', 'upcoming_total', 'past_total'] as $key) {
        assertPublishedSchemaValueType($payload, $response, ['data', $key]);
    }
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $response
 */
function assertPublishedPaginationTypes(array $payload, array $response): void
{
    foreach (['page', 'per_page', 'has_more', 'next_page'] as $key) {
        assertPublishedSchemaValueType($payload, $response, ['meta', 'pagination', $key]);
    }
}

it('publishes the frozen response contracts', function (): void {
    $document = app(ApiDocumentationDocumentResolver::class)->resolve();

    foreach ($this->published as $operation => $response) {
        [$method, $path, $status] = explode(' ', $operation);

        $actual = json_decode(
            (string) json_encode($document['paths'][$path][strtolower($method)]['responses'][$status] ?? null),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($actual)->toEqual($response, $operation);
    }
});

it('returns the published keys for public directory and detail payloads', function (): void {
    $user = User::factory()->create();
    $reference = Reference::factory()->create([
        'status' => 'verified',
        'published_at' => now(),
    ]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $series = Series::factory()->create(['visibility' => 'public']);

    $this->getJson(route('api.client.references.show', ['referenceKey' => $reference->slug]))
        ->assertOk()
        ->tap(function ($response): void {
            assertPublishedDataKeys($response->json(), $this->published['GET /references/{referenceKey} 200']);
            assertPublishedDetailTypes($response->json(), $this->published['GET /references/{referenceKey} 200'], 'reference');
        });

    $this->getJson(route('api.client.venues.show', ['venueKey' => $venue->slug]))
        ->assertOk()
        ->tap(function ($response): void {
            assertPublishedDataKeys($response->json(), $this->published['GET /venues/{venueKey} 200']);
            assertPublishedDetailTypes($response->json(), $this->published['GET /venues/{venueKey} 200'], 'venue');
        });

    $this->getJson(route('api.client.series.show', ['series' => $series->slug]))
        ->assertOk()
        ->tap(function ($response): void {
            assertPublishedDataKeys($response->json(), $this->published['GET /series/{series} 200']);
            assertPublishedDetailTypes($response->json(), $this->published['GET /series/{series} 200'], 'series');
        });

});

it('returns the published keys for account event lists and event state', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $user->respond($event, 'going');

    Sanctum::actingAs($user);

    $this->getJson(route('api.events.going.index'))
        ->assertOk()
        ->tap(function ($response): void {
            assertPublishedTopLevelKeys($response->json(), $this->published['GET /me/events/going 200']);
            assertPublishedPaginationTypes($response->json(), $this->published['GET /me/events/going 200']);
        });

    $this->getJson(route('api.events.saved.index'))
        ->assertOk()
        ->tap(function ($response): void {
            assertPublishedTopLevelKeys($response->json(), $this->published['GET /me/events/saved 200']);
            assertPublishedPaginationTypes($response->json(), $this->published['GET /me/events/saved 200']);
        });

    $this->getJson(route('api.events.me.show', $event))
        ->assertOk()
        ->tap(function ($response): void {
            assertPublishedTopLevelKeys($response->json(), $this->published['GET /events/{event}/me 200']);
            assertPublishedSchemaValueType($response->json(), $this->published['GET /events/{event}/me 200'], ['meta', 'request_id']);
        });
});
