<?php

use AIArmada\Events\Models\EventOccurrence;
use App\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Person;
use App\Support\Mcp\McpEventSearchService;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** @return Collection<int, Event> */
function batchProjectionEvents(): Collection
{
    $institution = Institution::factory()->create(['status' => 'verified']);

    return Event::factory()->count(5)->for($institution)->create([
        'title' => 'Batch projection event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'timing_mode' => 'absolute',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
    ]);
}

/** @param list<string> $queries */
function recordBatchOccurrenceMediaQueries(array &$queries): void
{
    $occurrenceType = (new EventOccurrence)->getMorphClass();

    DB::listen(function (QueryExecuted $query) use (&$queries, $occurrenceType): void {
        if (str_contains($query->sql, 'from "media"') && in_array($occurrenceType, $query->bindings, true)) {
            $queries[] = $query->sql;
        }
    });
}

it('batches occurrence media when serializing search results', function (string $surface): void {
    $this->freezeTime();
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    config()->set('scout.driver', 'database');
    $events = batchProjectionEvents();
    $event = $events->firstOrFail();
    $cover = $event->occurrences()->firstOrFail()
        ->addMedia(fakeGeneratedImageUpload('date-cover.jpg', 1600, 900))
        ->toMediaCollection('cover');
    $queries = [];
    recordBatchOccurrenceMediaQueries($queries);

    $items = match ($surface) {
        'frontend' => $this->getJson('/api/v1/search')->assertOk()->json('data.events.items'),
        'mcp' => app(McpEventSearchService::class)->search(['per_page' => 5])['data'],
    };

    expect($items)->toHaveCount(5)
        ->and(collect($items)->pluck('id')->all())->toEqualCanonicalizing($events->modelKeys())
        ->and(collect($items)->firstWhere('id', $event->id)['card_image_url'])->toBe($cover->refresh()->getAvailableUrl(['banner', 'thumb']))
        ->and($queries)->toHaveCount(1);
})->with(['frontend', 'mcp']);

it('batches occurrence media for a persons other event roles', function (): void {
    $this->freezeTime();
    $this->seed(EventRoleSeeder::class);
    $person = Person::factory()->create(['status' => 'verified']);
    $events = batchProjectionEvents();

    foreach ($events as $event) {
        EventKeyPerson::factory()->create([
            'event_id' => $event->id,
            'involveable_type' => 'person',
            'involveable_id' => $person->id,
            'role_code' => EventKeyPersonRole::Moderator->value,
            'visibility' => 'public',
        ]);
    }

    $queries = [];
    recordBatchOccurrenceMediaQueries($queries);

    $response = $this->getJson(route('api.client.persons.show', ['personKey' => $person->slug]))
        ->assertOk();

    expect($response->json('data.other_role_upcoming_total'))->toBe(5)
        ->and(collect($response->json('data.other_role_upcoming_participations'))->pluck('event.id')->all())->toEqualCanonicalizing($events->modelKeys())
        ->and($response->json('data.upcoming_events'))->toHaveCount(5)
        ->and($response->json('data.other_role_past_participations'))->toBeEmpty()
        ->and($queries)->toHaveCount(2);
});
