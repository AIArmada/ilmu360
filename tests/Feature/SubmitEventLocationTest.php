<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventVisibility;
use App\Livewire\Pages\Events\Index;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Models\Venue;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
    $this->user = User::factory()->create();

    $this->domainTag = submitEventTerm('domain');
    $this->disciplineTag = submitEventTerm('discipline');
});

/**
 * @return array<string, mixed>
 */
function submitEventLocationFormData(array $overrides = []): array
{
    return array_merge([
        'title' => 'Submit Event Location',
        'description' => 'Test description.',
        'event_date' => now()->addDays(7)->format('Y-m-d'),
        'prayer_time' => 'selepas_maghrib',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [101],
    ], $overrides);
}

it('can submit an event as a speaker with an institution location', function () {
    $person = Person::factory()->create(['status' => 'verified']);
    $institution = Institution::factory()->create(['status' => 'verified']);

    setSubmitEventFormState(
        Livewire::actingAs($this->user)->test(Create::class),
        submitEventLocationFormData([
            'title' => 'Person at Institution',
            'primary_organizer_id' => $person->id,
            'persons' => [$person->id],
            'location_type' => 'institution',
            'location_institution_id' => $institution->id,
            'domain_tags' => [$this->domainTag->id],
            'discipline_tags' => [$this->disciplineTag->id],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $event = Event::query()->where('title', 'Person at Institution')->sole();

    expect($event->institution_id)->toBe($institution->id)
        ->and($event->default_venue_id)->toBeNull();
});

it('can submit an event as a speaker with a venue location', function () {
    $person = Person::factory()->create(['status' => 'verified']);
    $venue = Venue::factory()->create(['status' => 'verified']);

    setSubmitEventFormState(
        Livewire::actingAs($this->user)->test(Create::class),
        submitEventLocationFormData([
            'title' => 'Person at Venue',
            'primary_organizer_id' => $person->id,
            'persons' => [$person->id],
            'location_type' => 'venue',
            'location_venue_id' => $venue->id,
            'domain_tags' => [$this->domainTag->id],
            'discipline_tags' => [$this->disciplineTag->id],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $event = Event::query()->where('title', 'Person at Venue')->sole();

    expect($event->institution_id)->toBeNull()
        ->and($event->default_venue_id)->toBe($venue->id);
});

it('automatically sets location to institution when organizer is an institution', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    setSubmitEventFormState(
        Livewire::actingAs($this->user)->test(Create::class),
        submitEventLocationFormData([
            'title' => 'Institution Event',
            'primary_organizer_id' => $institution->id,
            'persons' => [$person->id],
            'domain_tags' => [$this->domainTag->id],
            'discipline_tags' => [$this->disciplineTag->id],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $event = Event::query()->where('title', 'Institution Event')->sole();

    expect($event->institution_id)->toBe($institution->id)
        ->and($event->default_venue_id)->toBeNull();
});

it('requires location type when organizer is speaker', function () {
    $person = Person::factory()->create(['status' => 'verified']);

    Livewire::actingAs($this->user)
        ->test(Create::class)
        ->set('data.primary_organizer_kind', 'speaker')
        ->set('data.primary_organizer_id', $person->id)
        ->set('data.primary_organizer_speaker_id', $person->id)
        ->set('data.location_type')
        ->set('data.visibility', EventVisibility::Public->value)
        ->call('submit')
        ->assertHasErrors(['data.location_type' => 'required']);
});

it('allows institution organizer to choose a different location', function () {
    $organizerInstitution = Institution::factory()->create(['status' => 'verified']);
    $otherVenue = Venue::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create(['status' => 'verified']);

    setSubmitEventFormState(
        Livewire::actingAs($this->user)->test(Create::class),
        submitEventLocationFormData([
            'title' => 'Institution at Other Venue',
            'primary_organizer_id' => $organizerInstitution->id,
            'location_same_as_institution' => false,
            'location_type' => 'venue',
            'location_venue_id' => $otherVenue->id,
            'persons' => [$person->id],
            'domain_tags' => [$this->domainTag->id],
            'discipline_tags' => [$this->disciplineTag->id],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $event = Event::query()->where('title', 'Institution at Other Venue')->sole();

    expect($event->institution_id)->toBeNull()
        ->and($event->default_venue_id)->toBe($otherVenue->id);
});

it('includes institution nicknames in submit-event option labels', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'nickname' => 'Masjid Biru',
        'status' => 'verified',
    ]);

    $component = Livewire::test(Create::class);

    /** @var array<string, string> $options */
    $options = (fn (): array => $this->availableInstitutionOptions())->call($component->instance());

    expect($options)->toHaveKey($institution->id, $institution->display_name);
});

it('matches institution nicknames in event filter search options', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'nickname' => 'Masjid Biru',
        'status' => 'verified',
    ]);

    $component = Livewire::test(Index::class);

    /** @var array<string, string> $results */
    $results = (fn (): array => $this->searchInstitutionOptions(
        countryId: null,
        adminArea1Id: null,
        adminArea2Id: null,
        search: 'Masjid Biru',
    ))->call($component->instance());

    expect($results)->toHaveKey($institution->id, $institution->display_name);
});
