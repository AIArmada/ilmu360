<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\Venue;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
});

/**
 * @return array{domain_tag: Tag, discipline_tag: Tag, speaker: Speaker, venue: Venue}
 */
function submitEventOrganizerFixtures(): array
{
    return [
        'speaker' => Speaker::factory()->create(['status' => 'verified']),
        'domain_tag' => Tag::factory()->domain()->create(),
        'discipline_tag' => Tag::factory()->discipline()->create(),
        'venue' => Venue::factory()->create(['status' => 'verified']),
    ];
}

/**
 * @param  array{domain_tag: Tag, discipline_tag: Tag, speaker: Speaker, venue: Venue}  $fixtures
 * @return array<string, mixed>
 */
function submitEventOrganizerFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'organizer_type' => 'speaker',
        'organizer_speaker_id' => $fixtures['speaker']->id,
        'speakers' => [$fixtures['speaker']->id],
        'title' => 'Auto Select Speaker Event',
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_type' => [EventType::KuliahCeramah->value],
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
        Livewire::test('pages.submit-event.create'),
        submitEventOrganizerFormData($fixtures),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Auto Select Speaker Event')->firstOrFail();
    expect($event->speakers)->toHaveCount(1);
    expect($event->speakers->first()->id)->toBe($fixtures['speaker']->id);
    expect($event->organizer_type)->toBe(Speaker::class);
    expect($event->organizer_id)->toBe($fixtures['speaker']->id);
});

it('shows formatted speaker names in submit event speaker selectors', function () {
    $speaker = Speaker::factory()->create([
        'name' => 'Aisyah binti Noor',
        'status' => 'verified',
        'is_active' => true,
        'honorific' => ['toh_puan'],
        'pre_nominal' => ['dr'],
    ]);

    Livewire::test('pages.submit-event.create')
        ->assertSee($speaker->formatted_name);
});

it('uses the organizer speaker slug when no explicit speakers are selected', function () {
    $fixtures = submitEventOrganizerFixtures();
    $eventDate = now()->addDay()->toDateString();
    $expectedSuffix = Carbon::parse($eventDate, 'Asia/Kuala_Lumpur')->format('j-n-y');

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventOrganizerFormData($fixtures, [
            'title' => 'Organizer Fallback Submit Event',
            'event_date' => $eventDate,
            'event_type' => [EventType::Other->value],
            'speakers' => [],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    expect(Event::where('title', 'Organizer Fallback Submit Event')->firstOrFail()->slug)
        ->toBe(sprintf('organizer-fallback-submit-event-%s-%s', $fixtures['speaker']->slug, $expectedSuffix));
});
