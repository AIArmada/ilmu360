<?php

use App\Enums\EventVisibility;
use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Models\Event;
use App\Models\Reference;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    config()->set('scout.driver', 'null');
});

function publicReferenceFamilyEvent(array $attributes = []): Event
{
    $startsAt = Carbon::now()->addDays(3);

    return Event::factory()->create([
        'title' => $attributes['title'] ?? 'Reference Family Event',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'is_active' => true,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        ...$attributes,
    ]);
}

function referenceFamilyFixtures(): array
{
    $root = Reference::factory()->create([
        'title' => 'Riyadhus Solihin',
        'slug' => 'riyadhus-solihin',
        'type' => ReferenceType::Book->value,
        'status' => 'verified',
        'is_active' => true,
    ]);

    $partTwo = Reference::factory()->create([
        'title' => 'Riyadhus Solihin',
        'slug' => 'riyadhus-solihin-jilid-2',
        'type' => ReferenceType::Book->value,
        'parent_reference_id' => $root->id,
        'part_type' => ReferencePartType::Jilid->value,
        'part_number' => '2',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $partThree = Reference::factory()->create([
        'title' => 'Riyadhus Solihin',
        'slug' => 'riyadhus-solihin-jilid-3',
        'type' => ReferenceType::Book->value,
        'parent_reference_id' => $root->id,
        'part_type' => ReferencePartType::Jilid->value,
        'part_number' => '3',
        'status' => 'verified',
        'is_active' => true,
    ]);

    return [$root, $partTwo, $partThree];
}

it('resolves reference family ids and part display titles', function () {
    [$root, $partTwo, $partThree] = referenceFamilyFixtures();

    expect($root->isRootReference())->toBeTrue()
        ->and($root->isPart())->toBeFalse()
        ->and($root->display_title)->toBe('Riyadhus Solihin')
        ->and($partTwo->isPart())->toBeTrue()
        ->and($partTwo->display_title)->toBe('Riyadhus Solihin — Jilid 2')
        ->and($root->familyReferenceIds())->toEqualCanonicalizing([
            (string) $root->id,
            (string) $partTwo->id,
            (string) $partThree->id,
        ])
        ->and($partTwo->defaultEventReferenceIds())->toBe([(string) $partTwo->id]);
});

it('shows root reference detail events from all child parts', function () {
    [$root, $partTwo] = referenceFamilyFixtures();

    $partEvent = publicReferenceFamilyEvent(['title' => 'Kuliah Jilid 2']);
    $unrelatedEvent = publicReferenceFamilyEvent(['title' => 'Unrelated Kuliah']);

    $partTwo->events()->attach($partEvent, ['order_column' => 1]);

    $response = $this->getJson(route('api.client.references.show', ['referenceKey' => $root->slug]));

    $response->assertOk();

    $upcomingEventIds = collect($response->json('data.upcoming_events'))->pluck('id')->all();

    expect($upcomingEventIds)
        ->toContain((string) $partEvent->id)
        ->not()->toContain((string) $unrelatedEvent->id);
});

it('shows child reference detail events exactly unless all parts are requested', function () {
    [$root, $partTwo, $partThree] = referenceFamilyFixtures();

    $partTwoEvent = publicReferenceFamilyEvent(['title' => 'Kuliah Jilid 2']);
    $partThreeEvent = publicReferenceFamilyEvent(['title' => 'Kuliah Jilid 3']);

    $partTwo->events()->attach($partTwoEvent, ['order_column' => 1]);
    $partThree->events()->attach($partThreeEvent, ['order_column' => 1]);

    $exactResponse = $this->getJson(route('api.client.references.show', ['referenceKey' => $partTwo->slug]));
    $exactResponse->assertOk();

    $exactEventIds = collect($exactResponse->json('data.upcoming_events'))->pluck('id')->all();

    expect($exactEventIds)
        ->toContain((string) $partTwoEvent->id)
        ->not()->toContain((string) $partThreeEvent->id);

    $familyResponse = $this->getJson(route('api.client.references.show', [
        'referenceKey' => $partTwo->slug,
        'include_all_parts' => true,
    ]));
    $familyResponse->assertOk();

    $familyEventIds = collect($familyResponse->json('data.upcoming_events'))->pluck('id')->all();

    expect($familyEventIds)
        ->toContain((string) $partTwoEvent->id)
        ->toContain((string) $partThreeEvent->id)
        ->and($familyResponse->json('data.reference.display_title'))->toBe('Riyadhus Solihin — Jilid 2')
        ->and($familyResponse->json('data.reference.is_part'))->toBeTrue();
});

it('expands root reference event filters while child filters stay exact', function () {
    [$root, $partTwo, $partThree] = referenceFamilyFixtures();

    $partTwoEvent = publicReferenceFamilyEvent(['title' => 'Event Linked To Part Two']);
    $partThreeEvent = publicReferenceFamilyEvent(['title' => 'Event Linked To Part Three']);
    $unrelatedEvent = publicReferenceFamilyEvent(['title' => 'Unrelated Event']);

    $partTwo->events()->attach($partTwoEvent, ['order_column' => 1]);
    $partThree->events()->attach($partThreeEvent, ['order_column' => 1]);

    $rootFilterResponse = $this->getJson('/api/v1/events?filter[reference_ids][]='.$root->id);
    $rootFilterResponse->assertOk();

    $rootFilterIds = collect($rootFilterResponse->json('data'))->pluck('id')->all();

    expect($rootFilterIds)
        ->toContain((string) $partTwoEvent->id)
        ->toContain((string) $partThreeEvent->id)
        ->not()->toContain((string) $unrelatedEvent->id);

    $childFilterResponse = $this->getJson('/api/v1/events?filter[reference_ids][]='.$partTwo->id);
    $childFilterResponse->assertOk();

    $childFilterIds = collect($childFilterResponse->json('data'))->pluck('id')->all();

    expect($childFilterIds)
        ->toContain((string) $partTwoEvent->id)
        ->not()->toContain((string) $partThreeEvent->id)
        ->not()->toContain((string) $unrelatedEvent->id);
});

it('hides child parts from default reference directory but finds them by search', function () {
    referenceFamilyFixtures();

    $this->get('/rujukan')
        ->assertOk()
        ->assertSee('Riyadhus Solihin')
        ->assertDontSee('Jilid 2');

    $this->get('/rujukan?search='.urlencode('Jilid 2'))
        ->assertOk()
        ->assertSee('Riyadhus Solihin')
        ->assertSee('Jilid 2');
});
