<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\Person;
use App\Models\Venue;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
});

/**
 * @return array{domain_tag: Tag, discipline_tag: Tag, speaker: Person, venue: Venue}
 */
function submitEventOrganizerFixtures(): array
{
    return [
        'speaker' => Person::factory()->create(['status' => 'verified']),
        'domain_tag' => submitEventTerm('domain'),
        'discipline_tag' => submitEventTerm('discipline'),
        'venue' => Venue::factory()->create(['status' => 'verified']),
    ];
}

/**
 * @param  array{domain_tag: Tag, discipline_tag: Tag, speaker: Person, venue: Venue}  $fixtures
 * @return array<string, mixed>
 */
function submitEventOrganizerFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'primary_organizer_id' => $fixtures['speaker']->id,
        'speakers' => [$fixtures['speaker']->id],
        'title' => 'Auto Select Person Event',
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [101],
        'description' => 'Test description',
        'domain_tags' => [$fixtures['domain_tag']->id],
        'discipline_tags' => [$fixtures['discipline_tag']->id],
        'submitter_name' => 'Test User',
        'submitter_email' => 'test@example.com',
        'location_type' => 'venue',
        'location_venue_id' => $fixtures['venue']->id,
        'visibility' => EventVisibility::Public->value,
    ], $overrides);
}

it('assigns the speaker as event speaker when speaker is the organizer', function () {
    $fixtures = submitEventOrganizerFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventOrganizerFormData($fixtures),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Auto Select Person Event')->firstOrFail();
    expect($event->speakers)->toHaveCount(1);
    expect($event->speakers->first()->id)->toBe($fixtures['speaker']->id);

    $involvement = $event->primaryOrganizerInvolvement;
    expect($involvement->involveable_type)->toBe(Person::class);
    expect($involvement->involveable_id)->toBe((string) $fixtures['speaker']->id);
});

it('shows formatted speaker names in submit event speaker selectors', function () {
    $person = Person::factory()->create([
        'name' => 'Aisyah binti Noor',
        'status' => 'verified',
        'honorific' => ['toh_puan'],
        'pre_nominal' => ['dr'],
    ]);

    Livewire::test(Create::class)
        ->assertSee($person->formatted_name);
});

it('uses the organizer speaker slug when no explicit speakers are selected', function () {
    $fixtures = submitEventOrganizerFixtures();
    $eventDate = now()->addDay()->toDateString();
    $expectedSuffix = Carbon::parse($eventDate, 'Asia/Kuala_Lumpur')->format('j-n-y');

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventOrganizerFormData($fixtures, [
            'title' => 'Organizer Fallback Submit Event',
            'event_date' => $eventDate,
            'event_category_ids' => [eventCategoryId('other')],
            'speakers' => [],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    expect(Event::where('title', 'Organizer Fallback Submit Event')->firstOrFail()->slug)
        ->toBe(sprintf('organizer-fallback-submit-event-%s-%s', $fixtures['speaker']->slug, $expectedSuffix));
});
