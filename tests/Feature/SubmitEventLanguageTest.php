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
use App\Models\Language;
use App\Models\Person;
use App\Support\Cache\SelectionCatalogCache;
use App\Support\Language\MalaysiaLanguageCatalog;
use Database\Seeders\LanguageSeeder;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
});

/**
 * @return array{domain_tag: EventTerm, discipline_tag: EventTerm, institution: Institution, person: Person}
 */
function submitEventLanguageFixtures(): array
{
    return [
        'domain_tag' => submitEventTerm('domain'),
        'discipline_tag' => submitEventTerm('discipline'),
        'institution' => Institution::factory()->create(['status' => 'verified']),
        'person' => Person::factory()->create(['status' => 'verified']),
    ];
}

/**
 * @param  array{domain_tag: EventTerm, discipline_tag: EventTerm, institution: Institution, person: Person}  $fixtures
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
        'languages' => [languageId('ms')],
        'primary_organizer_id' => $fixtures['institution']->id,
        'persons' => [$fixtures['person']->id],
        'submitter_name' => 'Test User',
        'submitter_email' => 'test@example.com',
    ], $overrides);
}

it('offers Malaysia-relevant languages in the submit form', function (): void {
    app(LanguageSeeder::class)->run();
    app(SelectionCatalogCache::class)->bustLanguages();

    $languageIds = Language::query()
        ->whereIn('code', MalaysiaLanguageCatalog::codes())
        ->pluck('id', 'code')
        ->all();

    $expectedOptions = collect(MalaysiaLanguageCatalog::codes())
        ->mapWithKeys(fn (string $code): array => [
            (string) $languageIds[$code] => MalaysiaLanguageCatalog::labels()[$code],
        ])
        ->all();

    Livewire::test(Create::class)
        ->assertFormFieldExists('languages', function (Select $field) use ($expectedOptions): bool {
            expect($field->getOptions())->toBe($expectedOptions);

            return true;
        });
});

it('can submit event with single language', function () {
    $fixtures = submitEventLanguageFixtures();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventLanguageFormData($fixtures, [
            'title' => 'Single Language Event',
            'languages' => [languageId('ms')],
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
            'languages' => [languageId('ms'), languageId('ar'), languageId('en')],
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
