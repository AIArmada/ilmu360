<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\TagType;
use App\Livewire\Pages\Events\Index;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
    $this->user = User::factory()->create();

    $this->domainTag = Tag::factory()->create(['type' => TagType::Domain->value]);
    $this->disciplineTag = Tag::factory()->create(['type' => TagType::Discipline->value]);
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
        'event_type' => [EventType::KuliahCeramah->value],
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [101],
    ], $overrides);
}

it('can submit an event as a speaker with an institution location', function () {
    $speaker = Speaker::factory()->create(['status' => 'verified']);
    $institution = Institution::factory()->create(['status' => 'verified']);

    setSubmitEventFormState(
        Livewire::actingAs($this->user)->test('pages.submit-event.create'),
        submitEventLocationFormData([
            'title' => 'Speaker at Institution',
            'organizer_type' => 'speaker',
            'organizer_speaker_id' => $speaker->id,
            'speakers' => [$speaker->id],
            'location_type' => 'institution',
            'location_institution_id' => $institution->id,
            'domain_tags' => [$this->domainTag->id],
            'discipline_tags' => [$this->disciplineTag->id],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Event::class, [
        'title' => 'Speaker at Institution',
        'institution_id' => $institution->id,
        'venue_id' => null,
    ]);
});

it('can submit an event as a speaker with a venue location', function () {
    $speaker = Speaker::factory()->create(['status' => 'verified']);
    $venue = Venue::factory()->create(['status' => 'verified']);

    setSubmitEventFormState(
        Livewire::actingAs($this->user)->test('pages.submit-event.create'),
        submitEventLocationFormData([
            'title' => 'Speaker at Venue',
            'organizer_type' => 'speaker',
            'organizer_speaker_id' => $speaker->id,
            'speakers' => [$speaker->id],
            'location_type' => 'venue',
            'location_venue_id' => $venue->id,
            'domain_tags' => [$this->domainTag->id],
            'discipline_tags' => [$this->disciplineTag->id],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Event::class, [
        'title' => 'Speaker at Venue',
        'institution_id' => null,
        'venue_id' => $venue->id,
    ]);
});

it('automatically sets location to institution when organizer is an institution', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    setSubmitEventFormState(
        Livewire::actingAs($this->user)->test('pages.submit-event.create'),
        submitEventLocationFormData([
            'title' => 'Institution Event',
            'organizer_type' => 'institution',
            'organizer_institution_id' => $institution->id,
            'speakers' => [$speaker->id],
            'domain_tags' => [$this->domainTag->id],
            'discipline_tags' => [$this->disciplineTag->id],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Event::class, [
        'title' => 'Institution Event',
        'institution_id' => $institution->id,
        'venue_id' => null,
    ]);
});

it('requires location type when organizer is speaker', function () {
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    Livewire::actingAs($this->user)
        ->test('pages.submit-event.create')
        ->set('data.organizer_type', 'speaker')
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
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    setSubmitEventFormState(
        Livewire::actingAs($this->user)->test('pages.submit-event.create'),
        submitEventLocationFormData([
            'title' => 'Institution at Other Venue',
            'organizer_type' => 'institution',
            'organizer_institution_id' => $organizerInstitution->id,
            'location_same_as_institution' => false,
            'location_type' => 'venue',
            'location_venue_id' => $otherVenue->id,
            'speakers' => [$speaker->id],
            'domain_tags' => [$this->domainTag->id],
            'discipline_tags' => [$this->disciplineTag->id],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Event::class, [
        'title' => 'Institution at Other Venue',
        'institution_id' => null, // Since it's a venue
        'venue_id' => $otherVenue->id,
    ]);
});

it('includes institution nicknames in submit-event option labels', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'nickname' => 'Masjid Biru',
        'status' => 'verified',
    ]);

    $component = Livewire::test('pages.submit-event.create');

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
        stateId: null,
        districtId: null,
        subdistrictId: null,
        search: 'Masjid Biru',
    ))->call($component->instance());

    expect($results)->toHaveKey($institution->id, $institution->display_name);
});
