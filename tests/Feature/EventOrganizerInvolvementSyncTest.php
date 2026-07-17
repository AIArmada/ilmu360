<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Models\Venue;

beforeEach(function () {
    fakePrayerTimesApi();
});

it('creates a primary organizer involvement via setPrimaryOrganizer', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create();

    $event->setPrimaryOrganizer($institution);

    $involvement = $event->fresh()->primaryOrganizerInvolvement;

    expect($involvement)->not->toBeNull()
        ->and($involvement->involveable_type)->toBe(Institution::class)
        ->and($involvement->involveable_id)->toBe((string) $institution->getKey())
        ->and($involvement->role_code)->toBe('organizer')
        ->and($involvement->is_primary)->toBeTrue()
        ->and($involvement->status)->toBe('confirmed')
        ->and($involvement->visibility)->toBe('public');
});

it('reads the organizer model from the primary involvement', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create();

    $event->setPrimaryOrganizer($institution);

    $fresh = $event->fresh();

    expect($fresh->organizer)->toBeInstanceOf(Institution::class)
        ->and($fresh->organizer->getKey())->toBe($institution->getKey());
});

it('reads the organizer model via eager-loaded primaryOrganizerInvolvement', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create();

    $event->setPrimaryOrganizer($institution);

    $eager = Event::with('primaryOrganizerInvolvement.involveable')->find($event->getKey());

    expect($eager->organizer)->toBeInstanceOf(Institution::class)
        ->and($eager->organizer->getKey())->toBe($institution->getKey());
});

it('returns null for organizer when no primary involvement exists', function () {
    $event = Event::factory()->create();

    expect($event->primaryOrganizerInvolvement)->toBeNull()
        ->and($event->organizer)->toBeNull();
});

it('clears the primary organizer via setPrimaryOrganizer(null)', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create();

    $event->setPrimaryOrganizer($institution);
    expect($event->fresh()->organizer)->toBeInstanceOf(Institution::class);

    $event->refresh();
    $event->setPrimaryOrganizer(null);
    expect($event->fresh()->primaryOrganizerInvolvement)->toBeNull()
        ->and($event->fresh()->organizer)->toBeNull();
});

it('updates the existing primary involvement when organizer changes', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $speaker = Speaker::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create();

    $event->setPrimaryOrganizer($institution);
    $event = $event->fresh();
    $firstId = $event->primaryOrganizerInvolvement->getKey();

    $event->setPrimaryOrganizer($speaker);
    $involvement = $event->fresh()->primaryOrganizerInvolvement;

    expect($involvement->getKey())->toBe($firstId)
        ->and($involvement->involveable_type)->toBe(Speaker::class)
        ->and($involvement->involveable_id)->toBe((string) $speaker->getKey());
});

it('ignores legacy organizer metadata when no primary involvement exists', function () {
    $event = Event::factory()->create();

    $event->metadata = array_merge(
        (array) $event->metadata,
        ['organizer_type' => Speaker::class, 'organizer_id' => 'some-uuid'],
    );
    $event->save();

    $fresh = $event->fresh();
    expect($fresh->primaryOrganizerInvolvement)->toBeNull()
        ->and($fresh->organizer)->toBeNull();
});

it('creates organizer involvement via submit-event flow with a speaker organizer', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create(['status' => 'verified']);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $domainTag = submitEventTerm('domain');
    $disciplineTag = submitEventTerm('discipline');

    setSubmitEventFormState(
        Livewire::actingAs($user)->test('pages.submit-event.create'),
        ['title' => 'Submit Event Organizer Involvement', 'description' => 'Test description.', 'event_date' => now()->addDays(7)->format('Y-m-d'), 'prayer_time' => 'selepas_maghrib', 'event_category_ids' => [eventCategoryId('kuliah_ceramah')], 'event_format' => EventFormat::Physical->value, 'visibility' => EventVisibility::Public->value, 'gender' => EventGenderRestriction::All->value, 'age_group' => [EventAgeGroup::AllAges->value], 'languages' => [101], 'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(), 'primary_organizer_id' => $speaker->getKey(), 'speakers' => [$speaker->getKey()], 'location_type' => 'venue', 'location_venue_id' => $venue->getKey(), 'domain_tags' => [$domainTag->getKey()], 'discipline_tags' => [$disciplineTag->getKey()]],
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $event = Event::query()->where('title', 'Submit Event Organizer Involvement')->sole();
    $involvement = $event->fresh()->primaryOrganizerInvolvement;

    expect($involvement)->not->toBeNull()
        ->and($involvement->involveable_type)->toBe(Speaker::class)
        ->and($involvement->involveable_id)->toBe((string) $speaker->getKey())
        ->and($event->organizer)->toBeInstanceOf(Speaker::class);
});
