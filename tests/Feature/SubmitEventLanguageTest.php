<?php

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
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
});

/**
 * @return array{domain_tag: EventTerm, discipline_tag: EventTerm, institution: Institution, speaker: Person}
 */
function submitEventLanguageFixtures(): array
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
function submitEventLanguageFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'title' => 'Submit Event Language',
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

it('can submit event with single language', function () {
    $fixtures = submitEventLanguageFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
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
        Livewire::test(Create::class),
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
        Livewire::test(Create::class),
        submitEventLanguageFormData($fixtures, [
            'title' => 'No Language Event',
            'languages' => [],
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.languages']);
});
