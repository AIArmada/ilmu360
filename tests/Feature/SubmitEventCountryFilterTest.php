<?php

use AIArmada\Events\Models\EventTerm;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Venue;
use App\Support\Submission\EntitySubmissionAccess;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
    Cache::flush();

    $this->domainTag = submitEventTerm('domain');
    $this->disciplineTag = submitEventTerm('discipline');
    $this->malaysiaId = testMalaysiaCountryId();
    $this->singaporeId = (string) ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65')->getKey();
});

/**
 * @return array<string, mixed>
 */
function submitEventCountryFilterPayload(EventTerm $domainTag, EventTerm $disciplineTag, array $overrides = []): array
{
    return array_merge([
        'title' => 'Country Filter Submission',
        'description' => 'Country filter enforcement test.',
        'event_date' => now()->addDays(5)->format('Y-m-d'),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [languageId('ms')],
        'domain_tags' => [$domainTag->id],
        'discipline_tags' => [$disciplineTag->id],
        'submitter_name' => 'Guest Submitter',
        'submitter_email' => 'guest@example.com',
    ], $overrides);
}

function submitEventCountryFilterInstitution(string $countryId, array $overrides = []): Institution
{
    $institution = Institution::factory()->create(array_merge([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ], $overrides));

    syncPrimaryAddressForTest($institution, ['country_id' => $countryId]);

    return $institution->refresh();
}

function submitEventCountryFilterVenue(string $countryId): Venue
{
    $venue = Venue::factory()->create(['status' => 'verified']);

    syncPrimaryAddressForTest($venue, ['country_id' => $countryId]);

    return $venue->refresh();
}

it('filters submit institution and venue queries by country', function () {
    $malaysiaInstitution = submitEventCountryFilterInstitution($this->malaysiaId);
    $singaporeInstitution = submitEventCountryFilterInstitution($this->singaporeId);
    $malaysiaVenue = submitEventCountryFilterVenue($this->malaysiaId);
    $singaporeVenue = submitEventCountryFilterVenue($this->singaporeId);

    $access = app(EntitySubmissionAccess::class);

    expect($access->institutionQueryForSubmitter(null, $this->malaysiaId)->pluck('id')->all())
        ->toContain((string) $malaysiaInstitution->getKey())
        ->not->toContain((string) $singaporeInstitution->getKey())
        ->and($access->institutionQueryForSubmitter(null, $this->singaporeId)->pluck('id')->all())
        ->toContain((string) $singaporeInstitution->getKey())
        ->not->toContain((string) $malaysiaInstitution->getKey())
        ->and($access->venueQuery($this->malaysiaId)->pluck('id')->all())
        ->toContain((string) $malaysiaVenue->getKey())
        ->not->toContain((string) $singaporeVenue->getKey())
        ->and($access->venueQuery($this->singaporeId)->pluck('id')->all())
        ->toContain((string) $singaporeVenue->getKey())
        ->not->toContain((string) $malaysiaVenue->getKey())
        ->and($access->institutionBelongsToCountry((string) $singaporeInstitution->getKey(), $this->singaporeId))->toBeTrue()
        ->and($access->institutionBelongsToCountry((string) $singaporeInstitution->getKey(), $this->malaysiaId))->toBeFalse()
        ->and($access->venueBelongsToCountry((string) $singaporeVenue->getKey(), $this->singaporeId))->toBeTrue()
        ->and($access->venueBelongsToCountry((string) $singaporeVenue->getKey(), $this->malaysiaId))->toBeFalse();
});

it('limits submit-event institution and venue options to the selected country', function () {
    $malaysiaInstitution = submitEventCountryFilterInstitution($this->malaysiaId);
    $singaporeInstitution = submitEventCountryFilterInstitution($this->singaporeId);
    $malaysiaVenue = submitEventCountryFilterVenue($this->malaysiaId);
    $singaporeVenue = submitEventCountryFilterVenue($this->singaporeId);

    $component = Livewire::test(Create::class);
    $malaysiaId = $this->malaysiaId;

    /** @var array<string, string> $institutionOptions */
    $institutionOptions = (fn (): array => $this->availableInstitutionOptions($malaysiaId))->call($component->instance());

    /** @var array<string, string> $venueOptions */
    $venueOptions = (fn (): array => $this->cachedSubmitVenueOptions($malaysiaId))->call($component->instance());

    expect($institutionOptions)
        ->toHaveKey((string) $malaysiaInstitution->getKey())
        ->not->toHaveKey((string) $singaporeInstitution->getKey())
        ->and($venueOptions)
        ->toHaveKey((string) $malaysiaVenue->getKey())
        ->not->toHaveKey((string) $singaporeVenue->getKey());
});

it('rejects an organizer institution from another country', function () {
    $singaporeInstitution = submitEventCountryFilterInstitution($this->singaporeId);
    $person = Person::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventCountryFilterPayload($this->domainTag, $this->disciplineTag, [
            'submission_country_id' => $this->malaysiaId,
            'primary_organizer_id' => $singaporeInstitution->id,
            'persons' => [$person->id],
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.primary_organizer_id']);
});

it('rejects a location institution from another country', function () {
    $organizer = submitEventCountryFilterInstitution($this->malaysiaId);
    $singaporeLocation = submitEventCountryFilterInstitution($this->singaporeId);
    $person = Person::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventCountryFilterPayload($this->domainTag, $this->disciplineTag, [
            'submission_country_id' => $this->malaysiaId,
            'primary_organizer_id' => $organizer->id,
            'location_same_as_institution' => false,
            'location_type' => 'institution',
            'location_institution_id' => $singaporeLocation->id,
            'persons' => [$person->id],
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.location_institution_id']);
});

it('rejects a location venue from another country', function () {
    $person = Person::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);
    $singaporeVenue = submitEventCountryFilterVenue($this->singaporeId);

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventCountryFilterPayload($this->domainTag, $this->disciplineTag, [
            'submission_country_id' => $this->malaysiaId,
            'primary_organizer_id' => $person->id,
            'location_same_as_institution' => false,
            'location_type' => 'venue',
            'location_venue_id' => $singaporeVenue->id,
            'persons' => [$person->id],
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.location_venue_id']);
});

it('accepts matching-country organizer and location institutions', function () {
    $organizer = submitEventCountryFilterInstitution($this->singaporeId);
    $location = submitEventCountryFilterInstitution($this->singaporeId);
    $person = Person::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventCountryFilterPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Singapore Institution Event',
            'submission_country_id' => $this->singaporeId,
            'primary_organizer_id' => $organizer->id,
            'location_same_as_institution' => false,
            'location_type' => 'institution',
            'location_institution_id' => $location->id,
            'persons' => [$person->id],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Singapore Institution Event')->sole();

    expect($event->institution_id)->toBe($location->id)
        ->and($event->default_venue_id)->toBeNull();
});

it('accepts a matching-country location venue', function () {
    $person = Person::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);
    $singaporeVenue = submitEventCountryFilterVenue($this->singaporeId);

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventCountryFilterPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Singapore Venue Event',
            'submission_country_id' => $this->singaporeId,
            'primary_organizer_id' => $person->id,
            'location_same_as_institution' => false,
            'location_type' => 'venue',
            'location_venue_id' => $singaporeVenue->id,
            'persons' => [$person->id],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Singapore Venue Event')->sole();

    expect($event->default_venue_id)->toBe($singaporeVenue->id);
});

it('clears institution and venue selections that no longer match the country', function () {
    $malaysiaInstitution = submitEventCountryFilterInstitution($this->malaysiaId);
    $malaysiaVenue = submitEventCountryFilterVenue($this->malaysiaId);

    $component = Livewire::test(Create::class);

    setSubmitEventFormState($component, [
        'submission_country_id' => $this->malaysiaId,
        'primary_organizer_kind' => 'institution',
        'primary_organizer_institution_id' => $malaysiaInstitution->id,
        'primary_organizer_id' => $malaysiaInstitution->id,
        'location_same_as_institution' => false,
        'location_type' => 'venue',
        'location_venue_id' => $malaysiaVenue->id,
    ]);

    $component
        ->set('data.submission_country_id', $this->singaporeId)
        ->assertSet('data.primary_organizer_institution_id', null)
        ->assertSet('data.primary_organizer_id', null)
        ->assertSet('data.location_venue_id', null);
});

it('keeps institution selections that match the new country', function () {
    $singaporeInstitution = submitEventCountryFilterInstitution($this->singaporeId);

    $component = Livewire::test(Create::class);

    setSubmitEventFormState($component, [
        'submission_country_id' => $this->malaysiaId,
        'primary_organizer_kind' => 'institution',
        'primary_organizer_institution_id' => $singaporeInstitution->id,
        'primary_organizer_id' => $singaporeInstitution->id,
    ]);

    $component
        ->set('data.submission_country_id', $this->singaporeId)
        ->assertSet('data.primary_organizer_institution_id', (string) $singaporeInstitution->getKey())
        ->assertSet('data.primary_organizer_id', (string) $singaporeInstitution->getKey());
});
