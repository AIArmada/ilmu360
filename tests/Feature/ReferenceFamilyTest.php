<?php

use App\Enums\EventVisibility;
use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Models\Event;
use App\Models\Reference;
use App\Support\Api\Frontend\FrontendCatalogService;
use Database\Seeders\LanguageSeeder;
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
    ]);

    $partTwo = Reference::factory()->create([
        'title' => 'Riyadhus Solihin',
        'slug' => 'riyadhus-solihin-jilid-2',
        'type' => ReferenceType::Book->value,
        'record_kind' => 'part',
        'parent_id' => $root->id,
        'part_type' => ReferencePartType::Jilid->value,
        'part_number' => '2',
        'status' => 'verified',
    ]);

    $partThree = Reference::factory()->create([
        'title' => 'Riyadhus Solihin',
        'slug' => 'riyadhus-solihin-jilid-3',
        'type' => ReferenceType::Book->value,
        'record_kind' => 'part',
        'parent_id' => $root->id,
        'part_type' => ReferencePartType::Jilid->value,
        'part_number' => '3',
        'status' => 'verified',
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

    $partTwo->events()->attach($partEvent, ['sort_order' => 1]);

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

    $partTwo->events()->attach($partTwoEvent, ['sort_order' => 1]);
    $partThree->events()->attach($partThreeEvent, ['sort_order' => 1]);

    $exactResponse = $this->getJson(route('api.client.references.show', ['referenceKey' => $partTwo->slug]));
    $exactResponse->assertOk();

    $exactEventIds = collect($exactResponse->json('data.upcoming_events'))->pluck('id')->all();

    expect($exactEventIds)
        ->toContain((string) $partTwoEvent->id)
        ->not()->toContain((string) $partThreeEvent->id);

    $familyResponse = $this->getJson(route('api.client.references.show', [
        'referenceKey' => $partTwo->slug,
        'include_family' => true,
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

    $partTwo->events()->attach($partTwoEvent, ['sort_order' => 1]);
    $partThree->events()->attach($partThreeEvent, ['sort_order' => 1]);

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

it('excludes unpublished references from public family expansion and event results', function () {
    [$root, $partTwo, $partThree] = referenceFamilyFixtures();
    $hiddenPart = Reference::factory()->part()->pending()->unpublished()->create([
        'title' => 'Hidden Jilid 4',
        'record_kind' => 'part',
        'parent_id' => $root->getKey(),
        'part_type' => ReferencePartType::Jilid->value,
        'part_number' => '4',
    ]);
    $hiddenEvent = publicReferenceFamilyEvent(['title' => 'Kuliah Jilid 4']);
    $hiddenPart->events()->attach($hiddenEvent, ['sort_order' => 1]);

    expect($root->fresh()->familyReferenceIds())
        ->toEqualCanonicalizing([(string) $root->id, (string) $partTwo->id, (string) $partThree->id])
        ->and(Reference::expandReferenceIdsForFiltering([(string) $root->id, (string) $hiddenPart->id]))
        ->toEqualCanonicalizing([(string) $root->id, (string) $partTwo->id, (string) $partThree->id]);

    $this->getJson(route('api.client.references.show', ['referenceKey' => $root->slug]))
        ->assertOk()
        ->assertJsonMissing(['title' => 'Kuliah Jilid 4'])
        ->assertJsonMissing(['reference_study_subtitle' => 'Hidden Jilid 4']);
});

it('scopes edition detail and filters to its subtree while work includes every edition and unknown edition part', function () {
    $this->seed(LanguageSeeder::class);

    [$work, $directPart] = referenceFamilyFixtures();
    $edition = Reference::factory()->edition()->create([
        'title' => $work->title,
        'record_kind' => 'edition',
        'parent_id' => $work->id,
        'edition_number' => 3,
        'edition_label' => 'Cetakan Ketiga',
        'publisher' => 'Dar al-Kutub',
        'year' => 2022,
        'isbn' => '9780306406157',
        'language' => 'ar',
        'url' => 'https://example.com/edition',
        'status' => 'verified',
    ]);
    $editionPart = Reference::factory()->part()->create([
        'title' => $work->title,
        'record_kind' => 'part',
        'parent_id' => $edition->id,
        'part_type' => ReferencePartType::Jilid->value,
        'part_number' => '2',
        'status' => 'verified',
    ]);
    $editionEvent = publicReferenceFamilyEvent(['title' => 'Edition Jilid Event']);
    $directEvent = publicReferenceFamilyEvent(['title' => 'Unknown Edition Event']);
    $editionPart->events()->attach($editionEvent, ['sort_order' => 1]);
    $directPart->events()->attach($directEvent, ['sort_order' => 1]);

    $editionResponse = $this->getJson(route('api.client.references.show', ['referenceKey' => $edition->slug]))
        ->assertOk()
        ->assertJsonPath('data.reference.parent_id', (string) $work->id)
        ->assertJsonPath('data.reference.record_kind', 'edition')
        ->assertJsonPath('data.reference.edition_number', 3)
        ->assertJsonPath('data.reference.edition_label', 'Cetakan Ketiga')
        ->assertJsonPath('data.reference.isbn', '9780306406157')
        ->assertJsonPath('data.reference.language', 'ar')
        ->assertJsonPath('data.reference.url', 'https://example.com/edition');
    expect(collect($editionResponse->json('data.upcoming_events'))->pluck('id')->all())
        ->toContain((string) $editionEvent->id)
        ->not->toContain((string) $directEvent->id);

    $workResponse = $this->getJson(route('api.client.references.show', ['referenceKey' => $work->slug]))->assertOk();
    expect(collect($workResponse->json('data.upcoming_events'))->pluck('id')->all())
        ->toContain((string) $editionEvent->id, (string) $directEvent->id);

    $directory = $this->getJson('/api/v1/references?fields=id,record_kind,edition_number,isbn,language,url,events_count')->assertOk();
    $directoryRecords = collect($directory->json('data'))->keyBy('id');
    expect($directoryRecords->get((string) $work->id)['events_count'])->toBe(2)
        ->and($directoryRecords->get((string) $edition->id)['events_count'])->toBe(1)
        ->and($directoryRecords->get((string) $editionPart->id)['events_count'])->toBe(1)
        ->and($directoryRecords->get((string) $edition->id)['isbn'])->toBe('9780306406157');

    $filterResponse = $this->getJson('/api/v1/events?filter[reference_ids][]='.$edition->id)->assertOk();
    expect(collect($filterResponse->json('data'))->pluck('id')->all())
        ->toContain((string) $editionEvent->id)
        ->not->toContain((string) $directEvent->id);

    $catalog = app(FrontendCatalogService::class)->references('Cetakan Ketiga');
    expect(collect($catalog)->keyBy('id')->get((string) $edition->id)['label'])
        ->toContain('Riyadhus Solihin', 'Cetakan Ketiga', 'Dar al-Kutub');
});
