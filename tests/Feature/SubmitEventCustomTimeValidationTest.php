<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Models\Event;
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
function submitEventTimingFixtures(): array
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
function submitEventTimingFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'title' => 'Submit Event Timing',
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

it('can submit event with custom prayer time (lain_waktu)', function () {
    $fixtures = submitEventTimingFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventTimingFormData($fixtures, [
            'title' => 'Custom Time Event',
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '10:00',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Custom Time Event')->firstOrFail();
    expect($event->timing_mode)->toBe(TimingMode::Absolute);
});

it('requires custom_time when prayer_time is lain_waktu', function () {
    $fixtures = submitEventTimingFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventTimingFormData($fixtures, [
            'title' => 'Custom Time Required Event',
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => null,
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.custom_time']);

    expect(Event::where('title', 'Custom Time Required Event')->exists())->toBeFalse();
});

it('saves timing mode as prayer_relative when using prayer time', function () {
    $fixtures = submitEventTimingFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventTimingFormData($fixtures, [
            'title' => 'Prayer Time Event',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Prayer Time Event')->firstOrFail();
    expect($event->timing_mode)->toBe(TimingMode::PrayerRelative);
    expect($event->prayer_reference)->toBe(PrayerReference::Maghrib);
});

it('can submit event for future dates', function () {
    $fixtures = submitEventTimingFixtures();
    $futureDate = now()->addWeek()->toDateString();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventTimingFormData($fixtures, [
            'title' => 'Future Event',
            'event_date' => $futureDate,
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    expect(Event::where('title', 'Future Event')->exists())->toBeTrue();
});
