<?php

use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SuggestUpdate;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\EntryPoints\Livewire\Pages\SubmitEvent\Create;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\Places\Models\Space;
use App\Features\Places\Models\Venue;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Models\User;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Filament\Forms\Components\Field;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(EventRoleSeeder::class);
});

/**
 * Flat field map keyed by absolute state path for one live entrypoint.
 *
 * @return array<string, Field>
 */
function eventParityFields($component): array
{
    return $component->instance()->getForm('form')->getFlatFields(withHidden: true);
}

/**
 * Map the update entrypoint's persisted projection keys onto the canonical
 * create-style names so both forms can be compared field for field.
 *
 * @param  array<string, Field>  $fields
 * @return array<string, Field>
 */
function eventParityComparableFields(array $fields): array
{
    $renames = [
        'language_ids' => 'languages',
        'reference_ids' => 'references',
        'person_ids' => 'persons',
    ];

    $mapped = [];

    foreach ($fields as $key => $field) {
        $mapped[$renames[$key] ?? $key] = $field;
    }

    return $mapped;
}

function eventParityEvent(): Event
{
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Parity',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
        'delivery_mode' => 'physical',
        'institution_id' => $institution->id,
        'starts_at' => now()->addDays(4)->setTime(20, 0),
        'ends_at' => now()->addDays(4)->setTime(22, 0),
        'timing_mode' => 'absolute',
    ]);
    $event->setPrimaryOrganizer($institution);

    return $event;
}

function eventParityUpdateComponent(User $user, Event $event): mixed
{
    return Livewire::actingAs($user)->test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ]);
}

it('keeps event fields, order and required rules aligned between create and update', function (): void {
    $user = User::factory()->create();
    $event = eventParityEvent();

    $create = Livewire::actingAs($user)->test(Create::class)
        ->set('data.event_category_ids', eventCategoryId('komuniti_kebajikan'))
        ->set('data.event_format', 'physical')
        ->set('data.primary_organizer_kind', 'institution')
        ->set('data.primary_organizer_institution_id', $event->institution_id)
        ->set('data.location_same_as_institution', true)
        ->set('data.prayer_time', EventPrayerTime::LainWaktu->value);
    $update = eventParityUpdateComponent($user, $event)->set('data.other_key_people', []);
    $createFields = eventParityFields($create);
    $updateFields = eventParityComparableFields(eventParityFields($update));

    // Documented entrypoint-only fields: create owns submission country and
    // timezone selection, occurrence selection, prayer preview, CAPTCHA and
    // guest contact; update owns the reviewer note and the persisted
    // free-form timezone choice (the event's country is already fixed).
    $createOnly = [
        'submission_country_id',
        'submission_timezone',
        'event_occurrence_id',
        'prayer_preview',
        'captcha_token',
        'submitter_name',
        'submitter_email',
        'submitter_phone',
        'notes',
    ];
    $updateOnly = ['timezone', 'proposer_note'];

    $sharedCreate = array_values(array_diff(array_keys($createFields), $createOnly));
    $sharedUpdate = array_values(array_diff(array_keys($updateFields), $updateOnly));

    expect($sharedUpdate)->toBe($sharedCreate)
        ->and(array_keys($createFields))->not->toContain('proposer_note')
        ->and(array_keys($updateFields))->toContain('proposer_note');

    foreach ($sharedCreate as $key) {
        expect($updateFields[$key]->isRequired())
            ->toBe($createFields[$key]->isRequired(), "Required rule drifted for [{$key}].");
    }
});

it('keeps the timezone difference an explicit, documented entrypoint exception', function (): void {
    $user = User::factory()->create();
    $event = eventParityEvent();

    $createFields = eventParityFields(Livewire::actingAs($user)->test(Create::class));
    $updateFields = eventParityFields(eventParityUpdateComponent($user, $event));

    // Create derives a country-linked choice and only requires it for
    // multi-timezone countries; update edits an already-persisted instant and
    // always requires the free-form timezone.
    expect($createFields)->toHaveKey('submission_timezone')
        ->and($updateFields)->toHaveKey('timezone')
        ->and($createFields)->not->toHaveKey('timezone')
        ->and($updateFields)->not->toHaveKey('submission_timezone')
        ->and($updateFields['timezone']->isRequired())->toBeTrue();
});

it('keeps dynamic required rules aligned for a speaker-required category', function (): void {
    $user = User::factory()->create();
    $event = eventParityEvent();
    $categoryId = eventCategoryId('kuliah_ceramah');

    $createFields = eventParityFields(
        Livewire::actingAs($user)->test(Create::class)->set('data.event_category_ids', $categoryId),
    );
    $updateFields = eventParityComparableFields(eventParityFields(
        eventParityUpdateComponent($user, $event)->set('data.event_category_ids', [$categoryId]),
    ));

    expect($createFields['persons']->isRequired())->toBeTrue()
        ->and($updateFields['persons']->isRequired())->toBe($createFields['persons']->isRequired());
});

it('keeps dynamic required rules aligned for the custom-time branch', function (): void {
    $user = User::factory()->create();
    $event = eventParityEvent();

    $createFields = eventParityFields(
        Livewire::actingAs($user)->test(Create::class)
            ->set('data.prayer_time', EventPrayerTime::LainWaktu->value),
    );
    $updateFields = eventParityComparableFields(eventParityFields(
        eventParityUpdateComponent($user, $event)
            ->set('data.prayer_time', EventPrayerTime::LainWaktu->value),
    ));

    expect($createFields['custom_time']->isRequired())->toBeTrue()
        ->and($updateFields['custom_time']->isRequired())->toBe($createFields['custom_time']->isRequired());
});

it('captures a description edit without proposing changes to linked participants or languages', function (): void {
    $user = User::factory()->create();
    $event = eventParityEvent();
    $speaker = Person::factory()->create(['status' => 'verified']);
    $moderator = Person::factory()->create(['status' => 'verified']);
    $languageId = (string) languageId('ms');
    $authoring = app(EventAuthoring::class);
    $authoring->syncEventLanguages((string) $event->getKey(), [$languageId]);
    $authoring->syncEventClassifications('event', (string) $event->getKey(), [
        'domain_tags' => [submitEventTerm('domain')->getKey()],
    ]);
    $authoring->syncEventKeyPeople((string) $event->getKey(), [(string) $speaker->getKey()], [[
        'role_code' => 'moderator',
        'involveable_id' => (string) $moderator->getKey(),
    ]]);

    eventParityUpdateComponent($user, $event->refresh())
        ->assertSet('data.language_ids', [$languageId])
        ->set('data.description', 'Updated event description')
        ->set('data.proposer_note', 'Perbaiki penerangan')
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()->where('entity_id', $event->getKey())->sole();

    expect(array_keys($request->proposed_data))->toBe(['description']);
});

it('applies an event contribution without replacing omitted participants or tag groups', function (bool $clearSpeakers): void {
    $event = eventParityEvent();
    $speaker = Person::factory()->create(['status' => 'verified']);
    $moderator = Person::factory()->create(['status' => 'verified']);
    $domainId = (string) submitEventTerm('domain')->getKey();
    $sourceId = (string) submitEventTerm('source')->getKey();
    $eventId = (string) $event->getKey();
    $authoring = app(EventAuthoring::class);
    $authoring->syncEventClassifications('event', $eventId, [
        'domain_tags' => [$domainId],
        'source_tags' => [$sourceId],
    ]);
    $authoring->syncEventKeyPeople($eventId, [(string) $speaker->getKey()], [[
        'role_code' => 'moderator',
        'involveable_id' => (string) $moderator->getKey(),
    ]]);
    $untouchedRole = $clearSpeakers ? 'moderator' : 'speaker';
    $originalParticipant = $event->keyPeople()->where('role_code', $untouchedRole)->sole();

    $authoring->applyContributionUpdate($eventId, [
        $clearSpeakers ? 'person_ids' : 'other_key_people' => [],
        'source_tags' => [],
    ]);

    expect($event->keyPeople()->sole()->getKey())->toBe($originalParticipant->getKey())
        ->and($event->classifications()->where('taxonomy_code', 'domain')->pluck('event_term_id')->all())->toBe([$domainId])
        ->and($event->classifications()->where('taxonomy_code', 'source')->exists())->toBeFalse();
})->with([
    'speaker removal' => [true],
    'other role removal' => [false],
]);

it('prefills venue spaces and accepts clearing them through an event contribution', function (): void {
    $user = User::factory()->create();
    $event = eventParityEvent();
    $venue = Venue::factory()->create(['status' => 'verified']);
    $spaceIds = Space::factory()->count(2)->create(['venue_id' => $venue->getKey()])
        ->map(fn (Space $space): string => (string) $space->getKey())
        ->all();
    $event->forceFill(['institution_id' => null, 'default_venue_id' => $venue->getKey()])->save();
    $event->syncLocation((string) $venue->getKey(), $spaceIds);
    $event->refresh();
    $locationIds = $event->locations()->orderBy('sort_order')->pluck('id')->all();

    $authoring = app(EventAuthoring::class);
    $authoring->applyContributionUpdate((string) $event->getKey(), ['description' => 'A new description']);

    expect($event->locations()->orderBy('sort_order')->pluck('id')->all())->toBe($locationIds);

    eventParityUpdateComponent($user, $event->refresh())->assertSet('data.space_ids', $spaceIds);

    Sanctum::actingAs($user);
    $this->postJson(route('api.client.contributions.suggest.store', [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subject' => $event->slug,
    ]), [
        'space_ids' => [],
        'proposer_note' => 'Keluarkan ruangan',
    ])->assertCreated();

    $request = ContributionRequest::query()->where('entity_id', $event->getKey())->sole();

    expect($request->original_data['space_ids'])->toBe($spaceIds)
        ->and($request->proposed_data)->toBe(['space_ids' => []]);

    $authoring->applyContributionUpdate((string) $event->getKey(), $request->proposed_data);

    $remainingLocation = $event->locations()->sole();
    expect($remainingLocation->venue_id)->toBe($venue->getKey())
        ->and($remainingLocation->venue_space_id)->toBeNull();
});
