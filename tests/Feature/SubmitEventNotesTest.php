<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Tag;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
});

/**
 * @return array{domain_tag: Tag, discipline_tag: Tag, institution: Institution, speaker: Speaker}
 */
function submitEventNotesFixtures(): array
{
    return [
        'domain_tag' => Tag::factory()->domain()->create(),
        'discipline_tag' => Tag::factory()->discipline()->create(),
        'institution' => Institution::factory()->create(['status' => 'verified']),
        'speaker' => Speaker::factory()->create(['status' => 'verified']),
    ];
}

/**
 * @param  array{domain_tag: Tag, discipline_tag: Tag, institution: Institution, speaker: Speaker}  $fixtures
 * @return array<string, mixed>
 */
function submitEventNotesFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'title' => 'Submit Event Notes',
        'domain_tags' => [$fixtures['domain_tag']->id],
        'discipline_tags' => [$fixtures['discipline_tag']->id],
        'event_type' => [EventType::KuliahCeramah->value],
        'event_date' => now()->addDays(5)->toDateString(),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'description' => 'Test description',
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [101],
        'organizer_type' => 'institution',
        'organizer_institution_id' => $fixtures['institution']->id,
        'speakers' => [$fixtures['speaker']->id],
        'submitter_name' => 'Test User',
        'submitter_email' => 'test@example.com',
    ], $overrides);
}

it('saves notes to event submission when provided', function () {
    $fixtures = submitEventNotesFixtures();
    $notes = 'This event requires special audio equipment and accessibility ramps.';

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
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

    expect($submission->notes)->toBe($notes);
});

it('allows submitting event without notes', function () {
    $fixtures = submitEventNotesFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventNotesFormData($fixtures, [
            'title' => 'Event Without Notes',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Event Without Notes')->firstOrFail();
    $submission = EventSubmission::where('event_id', $event->id)->firstOrFail();

    expect($submission->notes)->toBeNull();
});
