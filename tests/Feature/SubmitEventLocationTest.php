<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventVisibility;
use App\Enums\InstitutionNameType;
use App\Forms\SharedFormSchema;
use App\Livewire\Pages\Events\Index;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Models\Venue;
use App\Support\Submission\SubmitEventOptionsProvider;
use App\Support\Submission\SubmitEventPrefill;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
    $this->user = User::factory()->create();

    $this->seed(EventRoleSeeder::class);

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
        'languages' => [languageId('ms')],
    ], $overrides);
}

it('prefills the existing venue when an institution organizer adds a session', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create([
        'status' => 'draft',
        'created_by_type' => $this->user->getMorphClass(),
        'created_by_id' => $this->user->getKey(),
        'default_venue_id' => $venue->getKey(),
        'delivery_mode' => EventFormat::Physical,
    ]);
    $event->setPrimaryOrganizer($institution);

    Livewire::actingAs($this->user)
        ->withQueryParams(['event' => $event->getKey()])
        ->test(Create::class)
        ->assertSet('data.primary_organizer_institution_id', $institution->getKey())
        ->assertSet('data.location_same_as_institution', false)
        ->assertSet('data.location_type', 'venue')
        ->assertSet('data.location_venue_id', $venue->getKey());
});

it('preserves a separate location institution when prefilling a session', function (): void {
    $organizer = Institution::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $location = Institution::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $event = Event::factory()->create([
        'institution_id' => $location->getKey(),
        'default_venue_id' => null,
        'delivery_mode' => EventFormat::Physical,
    ]);
    $event->setPrimaryOrganizer($organizer);

    $defaults = SubmitEventPrefill::containerDefaults($event->fresh(), $this->user, (string) ensureTestMalaysiaCountry()->getKey());

    expect($defaults)->toMatchArray([
        'primary_organizer_institution_id' => $organizer->getKey(),
        'location_same_as_institution' => false,
        'location_type' => 'institution',
        'location_institution_id' => $location->getKey(),
    ]);
});

it('flashes actual private parent visibility after submitting a public session', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $event = Event::factory()->create([
        'status' => 'draft',
        'visibility' => EventVisibility::Private,
        'created_by_type' => $this->user->getMorphClass(),
        'created_by_id' => $this->user->getKey(),
    ]);
    $event->setPrimaryOrganizer($institution);
    $event->primaryOccurrence->update(['visibility' => 'private']);

    setSubmitEventFormState(
        Livewire::actingAs($this->user)->withQueryParams(['event' => $event->getKey()])->test(Create::class),
        submitEventLocationFormData([
            'title' => 'Private Parent Session Confirmation',
            'primary_organizer_id' => $institution->getKey(),
            'location_same_as_institution' => true,
            'persons' => [$person->getKey()],
            'domain_tags' => [$this->domainTag->getKey()],
            'discipline_tags' => [$this->disciplineTag->getKey()],
            'submitter_name' => $this->user->name,
            'submitter_email' => $this->user->email,
        ]),
    )->call('submit')->assertHasNoErrors()->assertRedirect(route('submit-event.success'));

    expect(session('event_parent_visibility'))->toBe('private')
        ->and(session('event_occurrence_visibility'))->toBe('private')
        ->and(session('event_visibility'))->toBe('public');
});

it('can submit an event as a person with an institution location', function () {
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

it('can submit an event as a person with a venue location', function () {
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

it('requires location type when organizer is person', function () {
    $person = Person::factory()->create(['status' => 'verified']);

    Livewire::actingAs($this->user)
        ->test(Create::class)
        ->set('data.primary_organizer_kind', 'person')
        ->set('data.primary_organizer_id', $person->id)
        ->set('data.primary_organizer_person_id', $person->id)
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

it('includes institution alternative names in submit-event option labels', function () {
    $institution = Institution::factory()
        ->create([
            'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
            'status' => 'verified',
        ]);

    $institution->names()->create([
        'name_type' => InstitutionNameType::Nickname,
        'full_name' => 'Masjid Biru',
        'language_code' => 'ms',
        'is_primary' => true,
    ]);

    /** @var array<string, string> $options */
    $options = app(SubmitEventOptionsProvider::class)->institutionOptions(null, null, null);

    expect($options)->toHaveKey($institution->id, $institution->display_name);
});

it('matches institution alternative names in event filter search options', function () {
    $institution = Institution::factory()
        ->create([
            'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
            'status' => 'verified',
        ]);

    $institution->names()->create([
        'name_type' => InstitutionNameType::Nickname,
        'full_name' => 'Masjid Biru',
        'language_code' => 'ms',
        'is_primary' => true,
    ]);

    $component = Livewire::test(Index::class);

    /** @var array<string, string> $results */
    $results = (fn (): array => $this->searchInstitutionOptions(
        countryId: null,
        stateId: null,
        areaAssignments: [],
        search: 'Masjid Biru',
    ))->call($component->instance());

    expect($results)->toHaveKey($institution->id, $institution->display_name);
});

it('keeps picker area assignment keys present for nested event locations', function () {
    $country = ensureTestAddressCountry(
        iso2: 'MY',
        name: 'Malaysia',
        iso3: 'MYS',
        timezones: ['Asia/Kuala_Lumpur'],
        phoneCode: '60',
    );

    Livewire::actingAs($this->user)
        ->test(Create::class)
        ->set('data.address.country_id', (string) $country->getKey())
        ->call('applyLocationPickerSelection', 'data.address', [
            'placeId' => 'event_place_no_areas',
            'googleMapsURI' => 'https://www.google.com/maps/place/?q=place_id:event_place_no_areas',
            'location' => [
                'lat' => 3.139,
                'lng' => 101.6869,
            ],
            'addressComponents' => [
                ['longText' => 'Jalan Event', 'shortText' => 'Jalan Event', 'types' => ['route']],
            ],
        ])
        ->assertSet('data.address.area_assignments', array_fill_keys(SharedFormSchema::entryAreaRoles(), null));
});
