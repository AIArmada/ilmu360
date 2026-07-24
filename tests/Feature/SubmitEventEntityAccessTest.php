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
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();

    $this->domainTag = submitEventTerm('domain');
    $this->disciplineTag = submitEventTerm('discipline');
});

/**
 * @return array<string, mixed>
 */
function submitEventEntityAccessPayload(EventTerm $domainTag, EventTerm $disciplineTag, array $overrides = []): array
{
    return array_merge([
        'title' => 'Entity Access Submission',
        'description' => 'Entity access enforcement test.',
        'event_date' => now()->addDays(5)->format('Y-m-d'),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [101],
        'domain_tags' => [$domainTag->id],
        'discipline_tags' => [$disciplineTag->id],
        'submitter_name' => 'Guest Submitter',
        'submitter_email' => 'guest@example.com',
    ], $overrides);
}

it('rejects guest submission when organizer institution is locked to members', function () {
    $lockedInstitution = Institution::factory()->create([
        'allow_public_event_submission' => false,
        'status' => 'verified',
    ]);

    $publicPerson = Person::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEntityAccessPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $lockedInstitution->id,
            'persons' => [$publicPerson->id],
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.primary_organizer_id']);
});

it('rejects guest submission when selected persons include locked person', function () {
    $publicInstitution = Institution::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);

    $lockedPerson = Person::factory()->create([
        'allow_public_event_submission' => false,
        'status' => 'verified',
    ]);

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEntityAccessPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $publicInstitution->id,
            'persons' => [$lockedPerson->id],
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.persons']);
});

it('allows authenticated members to submit locked institution and person entities', function () {
    $user = User::factory()->create();

    $lockedInstitution = Institution::factory()->create([
        'allow_public_event_submission' => false,
        'status' => 'verified',
    ]);

    $lockedPerson = Person::factory()->create([
        'allow_public_event_submission' => false,
        'status' => 'verified',
    ]);

    $lockedInstitution->members()->syncWithoutDetaching([$user->id]);
    $lockedPerson->members()->syncWithoutDetaching([$user->id]);

    setSubmitEventFormState(
        Livewire::actingAs($user)->test(Create::class),
        submitEventEntityAccessPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Member Locked Access Event',
            'primary_organizer_id' => $lockedPerson->id,
            'persons' => [$lockedPerson->id],
            'location_type' => 'institution',
            'location_institution_id' => $lockedInstitution->id,
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Member Locked Access Event')->first();

    expect($event)->not->toBeNull();
    $organizerInvolvement = $event?->primaryOrganizerInvolvement;
    expect($organizerInvolvement?->involveable_id)->toBe((string) $lockedPerson->getKey());
    expect($event?->institution_id)->toBe($lockedInstitution->id);
});

it('forbids the institution-scoped dashboard submit flow for non-members', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'allow_public_event_submission' => false,
        'status' => 'verified',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard.institutions.submit-event', ['institution' => $institution->id]))
        ->assertForbidden();
});

it('forbids the institution-scoped dashboard submit flow for non-members even when public submission is enabled', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard.institutions.submit-event', ['institution' => $institution->id]))
        ->assertForbidden();
});

it('forbids the institution-scoped dashboard submit flow for members of inactive institutions', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'allow_public_event_submission' => false,
        'status' => 'inactive',
    ]);

    $institution->members()->syncWithoutDetaching([$user->id]);

    $this->actingAs($user)
        ->get(route('dashboard.institutions.submit-event', ['institution' => $institution->id]))
        ->assertForbidden();
});

it('auto-approves institution-scoped dashboard submissions and locks the organizer institution', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'allow_public_event_submission' => false,
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);

    $institution->members()->syncWithoutDetaching([$user->id]);

    setSubmitEventFormState(
        Livewire::withQueryParams(['institution' => $institution->id])->actingAs($user)->test(Create::class),
        submitEventEntityAccessPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Institution Dashboard Published Event',
            'location_same_as_institution' => true,
            'persons' => [$person->id],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Institution Dashboard Published Event')->first();

    expect($event)->not->toBeNull()
        ->and((string) $event?->status)->toBe('approved');

    $organizerInvolvement = $event?->primaryOrganizerInvolvement;
    expect($organizerInvolvement?->involveable_type)->toBe(Institution::class)
        ->and($organizerInvolvement?->involveable_id)->toBe((string) $institution->getKey());

    expect($event?->institution_id)->toBe($institution->id)
        ->and($event?->published_at)->not->toBeNull();

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertDontSee('Registration Required')
        ->assertDontSee('Register for this Event');
});
