<?php

use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('resolves public directory detail endpoints by uuid', function (string $resource): void {
    [$routeName, $routeParameter, $payloadKey, $record] = match ($resource) {
        'institution' => [
            'api.client.institutions.show',
            'institutionKey',
            'institution',
            Institution::factory()->create(['status' => 'verified']),
        ],
        'person' => [
            'api.client.persons.show',
            'personKey',
            'person',
            Person::factory()->create(['status' => 'verified']),
        ],
        'reference' => [
            'api.client.references.show',
            'referenceKey',
            'reference',
            Reference::factory()->create(['status' => 'verified']),
        ],
    };

    $this->getJson(route($routeName, [$routeParameter => $record->getKey()]))
        ->assertOk()
        ->assertJsonPath("data.{$payloadKey}.id", (string) $record->getKey());
})->with([
    'institution',
    'person',
    'reference',
]);

it('accepts q as an alias for the unified public search query', function (): void {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Query Alias Search',
        'status' => 'verified',
    ]);

    $response = $this->getJson('/api/v1/search?q='.urlencode('Query Alias Search'))
        ->assertOk()
        ->assertJsonPath('meta.search', 'Query Alias Search');

    expect(collect($response->json('data.institutions.items'))->pluck('id')->all())
        ->toContain((string) $institution->id);
});

it('lists followed directory resources through the public listing following filter', function (): void {
    $user = User::factory()->create();
    $followedInstitution = Institution::factory()->create(['status' => 'verified']);
    $otherInstitution = Institution::factory()->create(['status' => 'verified']);
    $followedPerson = Person::factory()->create(['status' => 'verified']);
    $otherPerson = Person::factory()->create(['status' => 'verified']);
    $followedReference = Reference::factory()->create(['status' => 'verified']);
    $otherReference = Reference::factory()->create(['status' => 'verified']);

    $user->follow($followedInstitution);
    $user->follow($followedPerson);
    $user->follow($followedReference);

    Sanctum::actingAs($user);

    $institutionIds = collect($this->getJson('/api/v1/institutions?following=true')
        ->assertOk()
        ->json('data'))->pluck('id');
    $personIds = collect($this->getJson('/api/v1/persons?following=true')
        ->assertOk()
        ->json('data'))->pluck('id');
    $referenceIds = collect($this->getJson('/api/v1/references?following=true')
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($institutionIds->all())->toContain((string) $followedInstitution->id)
        ->not->toContain((string) $otherInstitution->id)
        ->and($personIds->all())->toContain((string) $followedPerson->id)
        ->not->toContain((string) $otherPerson->id)
        ->and($referenceIds->all())->toContain((string) $followedReference->id)
        ->not->toContain((string) $otherReference->id);
});

it('requires coordinates for the nearby institution alias', function (): void {
    $this->getJson('/api/v1/institutions/near')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('error.details.fields.near.0', 'Provide `near=lat,lng` or both `lat` and `lng` to use the nearby institution endpoint.');
});

it('bounds person detail participation queries regardless of participation count', function (): void {
    $person = null;

    withGlobalOwnerContext(function () use (&$person): void {
        $person = Person::factory()->create(['status' => 'verified']);

        foreach (range(1, 10) as $offset) {
            $upcoming = Event::factory()->create([
                'status' => 'approved',
                'visibility' => 'public',
                'published_at' => now(),
                'starts_at' => now()->addDays($offset),
                'ends_at' => now()->addDays($offset)->addHours(2),
            ]);
            EventKeyPerson::factory()->create([
                'event_id' => $upcoming->getKey(),
                'involveable_type' => 'person',
                'involveable_id' => $person->getKey(),
                'role_code' => 'moderator',
                'visibility' => 'public',
                'sort_order' => 1,
            ]);

            $past = Event::factory()->create([
                'status' => 'approved',
                'visibility' => 'public',
                'published_at' => now(),
                'starts_at' => now()->subDays($offset),
                'ends_at' => now()->subDays($offset)->addHours(2),
            ]);
            EventKeyPerson::factory()->create([
                'event_id' => $past->getKey(),
                'involveable_type' => 'person',
                'involveable_id' => $person->getKey(),
                'role_code' => 'moderator',
                'visibility' => 'public',
                'sort_order' => 1,
            ]);
        }
    });

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->getJson(route('api.client.persons.show', ['personKey' => $person->getKey()]))
        ->assertOk()
        ->assertJsonPath('data.other_role_upcoming_total', 10)
        ->assertJsonPath('data.other_role_past_total', 10)
        ->assertJsonCount(6, 'data.other_role_upcoming_participations')
        ->assertJsonCount(6, 'data.other_role_past_participations');

    $queryLog = collect(DB::getQueryLog());
    $involvementFetches = $queryLog
        ->filter(fn (array $query): bool => str_contains($query['query'], 'event_involvements')
            && ! str_contains($query['query'], 'exists'));
    $occurrenceQueries = $queryLog
        ->filter(fn (array $query): bool => str_contains($query['query'], 'event_occurrences'));

    expect($involvementFetches)->toHaveCount(4)
        ->and($occurrenceQueries->count())->toBeLessThanOrEqual(12);
});
