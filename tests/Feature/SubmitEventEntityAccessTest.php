<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\TagType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();

    $this->domainTag = Tag::factory()->create(['type' => TagType::Domain->value]);
    $this->disciplineTag = Tag::factory()->create(['type' => TagType::Discipline->value]);
});

/**
 * @return array<string, mixed>
 */
function submitEventEntityAccessPayload(Tag $domainTag, Tag $disciplineTag, array $overrides = []): array
{
    return array_merge([
        'title' => 'Entity Access Submission',
        'description' => 'Entity access enforcement test.',
        'event_date' => now()->addDays(5)->format('Y-m-d'),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_type' => [EventType::KuliahCeramah->value],
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
        'is_active' => true,
    ]);

    $publicSpeaker = Speaker::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
        'is_active' => true,
    ]);

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventEntityAccessPayload($this->domainTag, $this->disciplineTag, [
            'organizer_type' => 'institution',
            'organizer_institution_id' => $lockedInstitution->id,
            'speakers' => [$publicSpeaker->id],
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.organizer_institution_id']);
});

it('rejects guest submission when selected speakers include locked speaker', function () {
    $publicInstitution = Institution::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
        'is_active' => true,
    ]);

    $lockedSpeaker = Speaker::factory()->create([
        'allow_public_event_submission' => false,
        'status' => 'verified',
        'is_active' => true,
    ]);

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventEntityAccessPayload($this->domainTag, $this->disciplineTag, [
            'organizer_type' => 'institution',
            'organizer_institution_id' => $publicInstitution->id,
            'speakers' => [$lockedSpeaker->id],
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.speakers']);
});

it('allows authenticated members to submit locked institution and speaker entities', function () {
    $user = User::factory()->create();

    $lockedInstitution = Institution::factory()->create([
        'allow_public_event_submission' => false,
        'status' => 'verified',
        'is_active' => true,
    ]);

    $lockedSpeaker = Speaker::factory()->create([
        'allow_public_event_submission' => false,
        'status' => 'verified',
        'is_active' => true,
    ]);

    $lockedInstitution->members()->syncWithoutDetaching([$user->id]);
    $lockedSpeaker->members()->syncWithoutDetaching([$user->id]);

    setSubmitEventFormState(
        Livewire::actingAs($user)->test('pages.submit-event.create'),
        submitEventEntityAccessPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Member Locked Access Event',
            'organizer_type' => 'speaker',
            'organizer_speaker_id' => $lockedSpeaker->id,
            'speakers' => [$lockedSpeaker->id],
            'location_type' => 'institution',
            'location_institution_id' => $lockedInstitution->id,
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Member Locked Access Event')->first();

    expect($event)->not->toBeNull();
    expect($event?->organizer_id)->toBe($lockedSpeaker->id);
    expect($event?->institution_id)->toBe($lockedInstitution->id);
});

it('forbids the institution-scoped dashboard submit flow for non-members', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'allow_public_event_submission' => false,
        'status' => 'verified',
        'is_active' => true,
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
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard.institutions.submit-event', ['institution' => $institution->id]))
        ->assertForbidden();
});

it('forbids the institution-scoped dashboard submit flow for members of inactive institutions', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'allow_public_event_submission' => false,
        'status' => 'verified',
        'is_active' => false,
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
        'is_active' => true,
    ]);
    $speaker = Speaker::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
        'is_active' => true,
    ]);

    $institution->members()->syncWithoutDetaching([$user->id]);

    setSubmitEventFormState(
        Livewire::withQueryParams(['institution' => $institution->id])->actingAs($user)->test('pages.submit-event.create'),
        submitEventEntityAccessPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Institution Dashboard Published Event',
            'location_same_as_institution' => true,
            'speakers' => [$speaker->id],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Institution Dashboard Published Event')->first();

    expect($event)->not->toBeNull()
        ->and((string) $event?->status)->toBe('approved')
        ->and($event?->organizer_type)->toBe(Institution::class)
        ->and($event?->organizer_id)->toBe($institution->id)
        ->and($event?->institution_id)->toBe($institution->id)
        ->and($event?->settings)->not->toBeNull()
        ->and($event?->settings?->registration_required)->toBeFalse()
        ->and($event?->settings?->registration_mode?->value)->toBe('event')
        ->and($event?->published_at)->not->toBeNull()
        ->and($event?->submissions()->count())->toBe(1)
        ->and($event?->moderationReviews()->where('decision', 'approved')->count())->toBe(1);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertDontSee('Registration Required')
        ->assertDontSee('Register for this Event');
});
