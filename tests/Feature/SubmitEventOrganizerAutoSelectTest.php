<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Person;
use App\Models\Venue;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();

    $this->seed(EventRoleSeeder::class);
});

/**
 * @return array{domain_tag: Tag, discipline_tag: Tag, person: Person, venue: Venue}
 */
function submitEventOrganizerFixtures(): array
{
    return [
        'person' => Person::factory()->create(['status' => 'verified']),
        'domain_tag' => submitEventTerm('domain'),
        'discipline_tag' => submitEventTerm('discipline'),
        'venue' => Venue::factory()->create(['status' => 'verified']),
    ];
}

/**
 * @param  array{domain_tag: Tag, discipline_tag: Tag, person: Person, venue: Venue}  $fixtures
 * @return array<string, mixed>
 */
function submitEventOrganizerFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'primary_organizer_id' => $fixtures['person']->id,
        'persons' => [$fixtures['person']->id],
        'title' => 'Auto Select Person Event',
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [languageId('ms')],
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

it('assigns the person as event person when person is the organizer', function () {
    $fixtures = submitEventOrganizerFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventOrganizerFormData($fixtures),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $occurrence = submittedOccurrenceByTitle('Auto Select Person Event');
    $personInvolvements = $occurrence->involvements()->where('role_code', 'speaker')->get();
    expect($personInvolvements)->toHaveCount(1);
    expect($personInvolvements->first()->involveable)->toBeInstanceOf(Person::class)
        ->and($personInvolvements->first()->involveable_id)->toBe((string) $fixtures['person']->id);

    $involvement = $occurrence->involvements()->where('role_code', 'organizer')->firstOrFail();
    expect($involvement->involveable)->toBeInstanceOf(Person::class)
        ->and($involvement->involveable_id)->toBe((string) $fixtures['person']->id);
});

it('shows formatted person names in submit event person selectors', function () {
    $person = Person::factory()->create([
        'name' => 'Aisyah binti Noor',
        'status' => 'verified',
    ]);

    Livewire::test(Create::class)
        ->assertSee($person->formatted_name);
});

it('uses the organizer person slug when no explicit persons are selected', function () {
    $fixtures = submitEventOrganizerFixtures();
    $eventDate = now()->addDay()->toDateString();
    $expectedSuffix = Carbon::parse($eventDate, 'Asia/Kuala_Lumpur')->format('j-n-y');

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventOrganizerFormData($fixtures, [
            'title' => 'Organizer Fallback Submit Event',
            'event_date' => $eventDate,
            'event_category_ids' => [eventCategoryId('lain_lain')],
            'persons' => [],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    expect(submittedOccurrenceByTitle('Organizer Fallback Submit Event')->slug)
        ->toBe(sprintf('organizer-fallback-submit-event-%s-%s', $fixtures['person']->slug, $expectedSuffix));
});
