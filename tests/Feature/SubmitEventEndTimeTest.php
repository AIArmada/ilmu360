<?php

use AIArmada\Addressing\Models\AddressCountry;
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
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
});

/**
 * @return array{domain_tag: EventTerm, discipline_tag: EventTerm, institution: Institution, person: Person}
 */
function submitEventEndTimeFixtures(): array
{
    return [
        'domain_tag' => submitEventTerm('domain'),
        'discipline_tag' => submitEventTerm('discipline'),
        'institution' => Institution::factory()->create(['status' => 'verified']),
        'person' => Person::factory()->create(['status' => 'verified']),
    ];
}

function submitEventAddressCountry(string $iso2 = 'MY', string $name = 'Malaysia', array $timezones = ['Asia/Kuala_Lumpur']): AddressCountry
{
    return AddressCountry::query()->firstOrCreate(
        ['iso2' => $iso2],
        [
            'name' => $name,
            'iso3' => Str::upper($iso2).'S',
            'entity_type' => 'country',
            'timezones' => $timezones,
        ],
    );
}

/**
 * @param  array{domain_tag: EventTerm, discipline_tag: EventTerm, institution: Institution, person: Person}  $fixtures
 * @return array<string, mixed>
 */
function submitEventEndTimeFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'title' => 'Submit Event End Time',
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
        'languages' => [languageId('ms')],
        'primary_organizer_id' => $fixtures['institution']->id,
        'persons' => [$fixtures['person']->id],
        'submitter_name' => 'Test User',
        'submitter_email' => 'test@example.com',
        'submission_country_id' => (string) submitEventAddressCountry()->getKey(),
    ], $overrides);
}

it('can submit event with optional end time', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'Event With End Time',
            'end_time' => '21:30',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Event With End Time')->firstOrFail();
    expect($event->ends_at)->not->toBeNull();
    expect($event->timezone)->toBe('Asia/Kuala_Lumpur');
    expect($event->ends_at->timezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('21:30');
    expect($event->ends_at->timezone('UTC')->format('H:i'))->toBe('13:30');
    // Verify ends_at has same date as starts_at
    expect($event->ends_at->toDateString())->toBe($event->starts_at->toDateString());
});

it('can submit event without end time (optional)', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'Event Without End Time',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Event Without End Time')->firstOrFail();
    expect($event->ends_at)->toBeNull();
});

it('can submit event with custom time and end time', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'Custom Time With End Time',
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '10:00',
            'end_time' => '12:00',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Custom Time With End Time')->firstOrFail();
    expect($event->timezone)->toBe('Asia/Kuala_Lumpur');
    expect($event->starts_at->timezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('10:00');
    expect($event->ends_at->timezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('12:00');
    expect($event->starts_at->timezone('UTC')->format('H:i'))->toBe('02:00');
    expect($event->ends_at->timezone('UTC')->format('H:i'))->toBe('04:00');
    expect($event->ends_at->toDateString())->toBe($event->starts_at->toDateString());
});

it('uses the selected submission country timezone instead of the browser timezone when submitting', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::withCookie('user_timezone', 'America/Los_Angeles')
            ->test(Create::class),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'Selected Country Timezone Wins',
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '00:30',
            'end_time' => '02:00',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Selected Country Timezone Wins')->firstOrFail();

    expect($event->timezone)->toBe('Asia/Kuala_Lumpur')
        ->and($event->starts_at->timezone('Asia/Kuala_Lumpur')->toDateString())->toBe(now()->addDays(5)->toDateString())
        ->and($event->starts_at->timezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('00:30')
        ->and($event->ends_at?->timezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('02:00');
});

it('rejects unsupported submission country ids in the public submit flow', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'Unsupported Submission Country Invalid',
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '10:00',
            'submission_country_id' => (string) Str::uuid(),
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.submission_country_id']);

    expect(Event::where('title', 'Unsupported Submission Country Invalid')->exists())->toBeFalse();
});

it('rejects malformed submission country ids in the public submit flow', function (string $submissionCountryId, string $title) {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEndTimeFormData($fixtures, [
            'title' => $title,
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '10:00',
            'submission_country_id' => $submissionCountryId,
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.submission_country_id']);

    expect(Event::where('title', $title)->exists())->toBeFalse();
})->with([
    'letters' => ['abc', 'Malformed Submission Country Letters'],
    'decimal' => ['132.5', 'Malformed Submission Country Decimal'],
]);

it('rejects end time that is earlier than estimated prayer start time', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'Prayer Time Invalid End Time',
            'prayer_time' => EventPrayerTime::SelepasIsyak->value,
            'end_time' => '21:00',
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.end_time']);

    expect(Event::where('title', 'Prayer Time Invalid End Time')->exists())->toBeFalse();
});

it('rejects end time that is equal to start time', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'Equal End Time Invalid',
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '10:00',
            'end_time' => '10:00',
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.end_time']);

    expect(Event::where('title', 'Equal End Time Invalid')->exists())->toBeFalse();
});

it('stores 08:00PM local as 12:00 UTC in database', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'KL 8PM UTC 12 Test',
            'prayer_time' => EventPrayerTime::SelepasAsar->value,
            'end_time' => '20:00',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'KL 8PM UTC 12 Test')->firstOrFail();

    expect($event->timezone)->toBe('Asia/Kuala_Lumpur');
    expect($event->ends_at)->not->toBeNull();
    expect($event->ends_at->timezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('20:00');
    expect($event->ends_at->timezone('UTC')->format('H:i'))->toBe('12:00');
});

it('allows sebelum maghrib during ramadhan', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'Ramadhan Sebelum Maghrib Valid',
            'event_date' => '2027-02-10',
            'prayer_time' => EventPrayerTime::SebelumMaghrib->value,
            'end_time' => '20:00',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Ramadhan Sebelum Maghrib Valid')->firstOrFail();

    expect($event->starts_at->timezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:45');
    expect($event->starts_at->timezone('UTC')->format('H:i'))->toBe('11:45');
});

it('rejects sebelum maghrib outside ramadhan', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'Non Ramadhan Sebelum Maghrib Invalid',
            'event_date' => '2027-03-20',
            'prayer_time' => EventPrayerTime::SebelumMaghrib->value,
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.prayer_time']);

    expect(Event::where('title', 'Non Ramadhan Sebelum Maghrib Invalid')->exists())->toBeFalse();
});

it('rejects non-physical format for community event types', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'Community Online Invalid',
            'event_category_ids' => [eventCategoryId('iftar')],
            'event_format' => EventFormat::Online->value,
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.event_format']);

    expect(Event::where('title', 'Community Online Invalid')->exists())->toBeFalse();
});
