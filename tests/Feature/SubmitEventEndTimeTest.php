<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Tag;
use App\Support\Location\PublicCountryPreference;
use App\Support\Location\PublicCountryRegistry;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
});

/**
 * @return array{domain_tag: Tag, discipline_tag: Tag, institution: Institution, speaker: Speaker}
 */
function submitEventEndTimeFixtures(): array
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
function submitEventEndTimeFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'title' => 'Submit Event End Time',
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

it('can submit event with optional end time', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
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
        Livewire::test('pages.submit-event.create'),
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
        Livewire::test('pages.submit-event.create'),
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

it('uses the selected public country timezone instead of the browser timezone when submitting', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::withCookie('user_timezone', 'America/Los_Angeles')
            ->withCookie('public_country', 'malaysia')
            ->test('pages.submit-event.create'),
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

it('defaults the submit-event form to the inferred preferred country when no explicit country is selected', function () {
    config()->set('public-countries.countries.singapore.enabled', true);

    $singaporeId = DB::table('countries')->insertGetId([
        'iso2' => 'SG',
        'name' => 'Singapore',
        'status' => 1,
        'phone_code' => '65',
        'iso3' => 'SGP',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ]);

    app()->forgetInstance(PublicCountryRegistry::class);
    app()->forgetInstance(PublicCountryPreference::class);

    Livewire::withQueryParams(['user_timezone' => 'Asia/Singapore'])
        ->test('pages.submit-event.create')
        ->assertSet('data.submission_country_id', $singaporeId);
});

it('rejects unsupported submission country ids in the public submit flow', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'Unsupported Submission Country Invalid',
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '10:00',
            'submission_country_id' => 999999,
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.submission_country_id']);

    expect(Event::where('title', 'Unsupported Submission Country Invalid')->exists())->toBeFalse();
});

it('rejects malformed submission country ids in the public submit flow', function (string $submissionCountryId, string $title) {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
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
        Livewire::test('pages.submit-event.create'),
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
        Livewire::test('pages.submit-event.create'),
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
        Livewire::test('pages.submit-event.create'),
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
        Livewire::test('pages.submit-event.create'),
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

    expect($event->starts_at->timezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:10');
    expect($event->starts_at->timezone('UTC')->format('H:i'))->toBe('11:10');
});

it('rejects sebelum maghrib outside ramadhan', function () {
    $fixtures = submitEventEndTimeFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
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
        Livewire::test('pages.submit-event.create'),
        submitEventEndTimeFormData($fixtures, [
            'title' => 'Community Online Invalid',
            'event_type' => [EventType::Iftar->value],
            'event_format' => EventFormat::Online->value,
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.event_format']);

    expect(Event::where('title', 'Community Online Invalid')->exists())->toBeFalse();
});
