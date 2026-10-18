<?php

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTimeExpression;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Enums\PrayerReference;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();

    $this->seed(EventRoleSeeder::class);
});

/**
 * @return array{domain_tag: Tag, discipline_tag: Tag, institution: Institution, person: Person}
 */
function submitEventTimingFixtures(): array
{
    return [
        'domain_tag' => submitEventTerm('domain'),
        'discipline_tag' => submitEventTerm('discipline'),
        'institution' => Institution::factory()->create(['status' => 'verified']),
        'person' => Person::factory()->create(['status' => 'verified']),
    ];
}

/**
 * @param  array{domain_tag: Tag, discipline_tag: Tag, institution: Institution, person: Person}  $fixtures
 * @return array<string, mixed>
 */
function submitEventTimingFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'title' => 'Submit Event Timing',
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
    ], $overrides);
}

it('can submit event with custom prayer time (lain_waktu)', function () {
    $fixtures = submitEventTimingFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventTimingFormData($fixtures, [
            'title' => 'Custom Time Event',
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '10:00',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $occurrence = submittedOccurrenceByTitle('Custom Time Event');
    expect($occurrence->starts_at->timezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('10:00')
        ->and($occurrence->timeExpressions()->count())->toBe(0);
});

it('requires custom_time when prayer_time is lain_waktu', function () {
    $fixtures = submitEventTimingFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
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
        Livewire::test(Create::class),
        submitEventTimingFormData($fixtures, [
            'title' => 'Prayer Time Event',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $occurrence = submittedOccurrenceByTitle('Prayer Time Event');
    $expression = EventTimeExpression::query()
        ->where('event_occurrence_id', $occurrence->getKey())
        ->sole();

    expect($expression->time_mode)->toBe('prayer_relative')
        ->and($expression->anchor_code)->toBe(PrayerReference::Maghrib->value);
});

it('can submit event for future dates', function () {
    $fixtures = submitEventTimingFixtures();
    $futureDate = now()->addWeek()->toDateString();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventTimingFormData($fixtures, [
            'title' => 'Future Event',
            'event_date' => $futureDate,
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    expect(EventOccurrence::query()->where('title', 'Future Event')->exists())->toBeTrue();
});
