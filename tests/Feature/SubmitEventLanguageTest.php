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
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
});

/**
 * @return array{domain_tag: Tag, discipline_tag: Tag, institution: Institution, speaker: Speaker}
 */
function submitEventLanguageFixtures(): array
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
function submitEventLanguageFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'title' => 'Submit Event Language',
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

it('can submit event with single language', function () {
    $fixtures = submitEventLanguageFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventLanguageFormData($fixtures, [
            'title' => 'Single Language Event',
            'languages' => [101],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Single Language Event')->firstOrFail();
    expect($event->languages)->toHaveCount(1);
    expect($event->languages->first()->code)->toBe('ms');
});

it('can submit event with multiple languages', function () {
    $fixtures = submitEventLanguageFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventLanguageFormData($fixtures, [
            'title' => 'Multi Language Event',
            'languages' => [101, 7, 40],
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Multi Language Event')->firstOrFail();
    expect($event->languages)->toHaveCount(3);
    $languageCodes = $event->languages->pluck('code')->toArray();
    expect($languageCodes)->toContain('ms', 'ar', 'en');
});

it('requires at least one language', function () {
    $fixtures = submitEventLanguageFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventLanguageFormData($fixtures, [
            'title' => 'No Language Event',
            'languages' => [],
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.languages']);
});
