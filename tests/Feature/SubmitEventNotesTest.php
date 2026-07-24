<?php

use AIArmada\Events\Models\EventTerm;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Person;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
});

/**
 * @return array{domain_tag: EventTerm, discipline_tag: EventTerm, institution: Institution, speaker: Person}
 */
function submitEventNotesFixtures(): array
{
    return [
        'domain_tag' => submitEventTerm('domain'),
        'discipline_tag' => submitEventTerm('discipline'),
        'institution' => Institution::factory()->create(['status' => 'verified']),
        'speaker' => Person::factory()->create(['status' => 'verified']),
    ];
}

/**
 * @param  array{domain_tag: EventTerm, discipline_tag: EventTerm, institution: Institution, speaker: Person}  $fixtures
 * @return array<string, mixed>
 */
function submitEventNotesFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'title' => 'Submit Event Notes',
        'domain_tags' => [$fixtures['domain_tag']->id],
        'discipline_tags' => [$fixtures['discipline_tag']->id],
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_date' => now()->addDays(5)->toDateString(),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'description' => 'Test description',
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [101],
        'primary_organizer_id' => $fixtures['institution']->id,
        'speakers' => [$fixtures['speaker']->id],
        'submitter_name' => 'Test User',
        'submitter_email' => 'test@example.com',
    ], $overrides);
}

it('saves notes to event submission when provided', function () {
    $fixtures = submitEventNotesFixtures();
    $notes = 'This event requires special audio equipment and accessibility ramps.';

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventNotesFormData($fixtures, [
            'title' => 'Event With Notes',
            'notes' => $notes,
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Event With Notes')->firstOrFail();
    $submission = EventSubmission::where('event_id', $event->id)->firstOrFail();

    expect($submission->submission_data['notes'] ?? null)->toBe($notes);
});

it('allows submitting event without notes', function () {
    $fixtures = submitEventNotesFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventNotesFormData($fixtures, [
            'title' => 'Event Without Notes',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Event Without Notes')->firstOrFail();
    $submission = EventSubmission::where('event_id', $event->id)->firstOrFail();

    expect($submission->submission_data['notes'] ?? null)->toBeNull();
});
