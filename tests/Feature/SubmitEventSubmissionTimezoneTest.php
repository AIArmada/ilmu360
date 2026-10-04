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
use App\Support\Submission\SubmissionTimingPolicy;
use App\Support\Submission\SubmitEventPrefill;
use Carbon\Carbon;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
    Cache::flush();

    $this->seed(EventRoleSeeder::class);
});

/**
 * @return array{domain_tag: EventTerm, discipline_tag: EventTerm, institution: Institution, person: Person}
 */
function submissionTimezoneFixtures(): array
{
    return [
        'domain_tag' => submitEventTerm('domain'),
        'discipline_tag' => submitEventTerm('discipline'),
        'institution' => Institution::factory()->create([
            'status' => 'verified',
            'allow_public_event_submission' => true,
        ]),
        'person' => Person::factory()->create([
            'status' => 'verified',
            'allow_public_event_submission' => true,
        ]),
    ];
}

/**
 * @param  array{domain_tag: EventTerm, discipline_tag: EventTerm, institution: Institution, person: Person}  $fixtures
 * @return array<string, mixed>
 */
function submissionTimezoneFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'title' => 'Submission Timezone Event',
        'description' => 'Timezone choice test.',
        'event_date' => now()->addDays(5)->format('Y-m-d'),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [languageId('ms')],
        'domain_tags' => [$fixtures['domain_tag']->id],
        'discipline_tags' => [$fixtures['discipline_tag']->id],
        'primary_organizer_id' => $fixtures['institution']->id,
        'persons' => [$fixtures['person']->id],
        'submitter_name' => 'Guest Submitter',
        'submitter_email' => 'guest@example.com',
        'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
    ], $overrides);
}

function submissionTimezoneMultiCountry(): string
{
    return (string) ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta', 'Asia/Jayapura'], '62')->getKey();
}

it('auto-fills the single linked timezone on mount and submits without an explicit choice', function () {
    $fixtures = submissionTimezoneFixtures();
    $malaysiaId = (string) ensureTestMalaysiaCountry()->getKey();

    $component = Livewire::test(Create::class);

    $component
        ->assertSet('data.submission_country_id', $malaysiaId)
        ->assertSet('data.submission_timezone', 'Asia/Kuala_Lumpur')
        ->assertFormFieldHidden('submission_timezone');

    setSubmitEventFormState($component, submissionTimezoneFormData($fixtures, [
        'title' => 'Single Zone Auto Event',
    ]))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Single Zone Auto Event')->sole();

    expect($event->timezone)->toBe('Asia/Kuala_Lumpur');
});

it('requires an explicit timezone choice for multi-zone countries', function () {
    $fixtures = submissionTimezoneFixtures();
    $indonesiaId = submissionTimezoneMultiCountry();

    $component = Livewire::test(Create::class)
        ->set('data.submission_country_id', $indonesiaId);

    $component
        ->assertSet('data.submission_timezone', null)
        ->assertFormFieldVisible('submission_timezone')
        ->assertFormFieldExists('submission_timezone', function (Select $field): bool {
            expect($field->isRequired())->toBeTrue()
                ->and($field->getOptions())->toBe([
                    'Asia/Jakarta' => 'Asia/Jakarta',
                    'Asia/Jayapura' => 'Asia/Jayapura',
                ]);

            return true;
        });

    setSubmitEventFormState($component, submissionTimezoneFormData($fixtures, [
        'title' => 'Multi Zone Missing Choice',
        'submission_country_id' => $indonesiaId,
    ]))
        ->call('submit')
        ->assertHasErrors(['data.submission_timezone']);

    expect(Event::query()->where('title', 'Multi Zone Missing Choice')->exists())->toBeFalse();
});

it('collapses same-clock multi-zone countries to the preferred representative', function () {
    $fixtures = submissionTimezoneFixtures();
    $malaysiaId = (string) ensureTestAddressCountry('MY', 'Malaysia', 'MYS', ['Asia/Kuala_Lumpur', 'Asia/Kuching'], '60')->getKey();

    $component = Livewire::test(Create::class);

    $component
        ->assertSet('data.submission_country_id', $malaysiaId)
        ->assertSet('data.submission_timezone', 'Asia/Kuala_Lumpur')
        ->assertFormFieldHidden('submission_timezone');

    expect($component->instance()->clientProgressConfiguration()['multi_timezone_country_ids'])
        ->not->toContain($malaysiaId);

    $eventDate = now()->addDays(5)->format('Y-m-d');

    setSubmitEventFormState($component, submissionTimezoneFormData($fixtures, [
        'title' => 'Same Clock Auto Event',
        'submission_country_id' => $malaysiaId,
        'event_date' => $eventDate,
    ]))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Same Clock Auto Event')->sole();

    expect($event->timezone)->toBe('Asia/Kuala_Lumpur')
        ->and($event->starts_at->timezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'))->toBe("{$eventDate} 20:00")
        ->and($event->starts_at->timezone('UTC')->format('H:i'))->toBe('12:00');
});

it('keeps the selector for seasonally diverging zones with matching current offsets', function (string $travelDate) {
    Carbon::setTestNow(Carbon::parse($travelDate, 'UTC'));

    try {
        $fixtures = submissionTimezoneFixtures();
        $americaId = (string) ensureTestAddressCountry('US', 'United States', 'USA', ['America/Denver', 'America/Phoenix'], '1')->getKey();

        if (Carbon::now('UTC')->month === 1) {
            expect(Carbon::now('America/Denver')->getOffset())
                ->toBe(Carbon::now('America/Phoenix')->getOffset());
        }

        $component = Livewire::test(Create::class)
            ->set('data.submission_country_id', $americaId);

        $component
            ->assertSet('data.submission_timezone', null)
            ->assertFormFieldVisible('submission_timezone')
            ->assertFormFieldExists('submission_timezone', function (Select $field): bool {
                expect($field->isRequired())->toBeTrue()
                    ->and($field->getOptions())->toBe([
                        'America/Denver' => 'America/Denver',
                        'America/Phoenix' => 'America/Phoenix',
                    ]);

                return true;
            });

        setSubmitEventFormState($component, submissionTimezoneFormData($fixtures, [
            'title' => 'Seasonal Divergence Requires Choice',
            'submission_country_id' => $americaId,
        ]))
            ->call('submit')
            ->assertHasErrors(['data.submission_timezone']);

        expect(Event::query()->where('title', 'Seasonal Divergence Requires Choice')->exists())->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'january matching offsets' => '2026-01-15 12:00:00',
    'july differing offsets' => '2026-07-15 12:00:00',
]);

it('collapses equivalent zones for countries without a preferred representative', function () {
    $fixtures = submissionTimezoneFixtures();
    $genericId = (string) ensureTestAddressCountry('XX', 'Equivalent Test', 'XXX', ['Asia/Kuala_Lumpur', 'Asia/Singapore'], null)->getKey();
    syncPrimaryAddressForTest($fixtures['institution'], ['country_id' => $genericId]);

    $component = Livewire::test(Create::class)
        ->set('data.submission_country_id', $genericId);

    $component
        ->assertSet('data.submission_timezone', 'Asia/Kuala_Lumpur')
        ->assertFormFieldHidden('submission_timezone');

    expect($component->instance()->clientProgressConfiguration()['multi_timezone_country_ids'])
        ->not->toContain($genericId);

    setSubmitEventFormState($component, submissionTimezoneFormData($fixtures, [
        'title' => 'Generic Equivalent Auto Event',
        'submission_country_id' => $genericId,
    ]))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    expect(Event::query()->where('title', 'Generic Equivalent Auto Event')->sole()->timezone)
        ->toBe('Asia/Kuala_Lumpur');
});

it('defaults the collapsed representative for duplicate sources outside the effective options', function () {
    $malaysiaId = (string) ensureTestAddressCountry('MY', 'Malaysia', 'MYS', ['Asia/Kuala_Lumpur', 'Asia/Kuching'], '60')->getKey();

    $sourceEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
        'timezone' => 'Asia/Kuching',
        'starts_at' => now()->addDays(9)->setTimezone('UTC'),
    ]);

    $prefill = SubmitEventPrefill::duplicateDefaults(
        $sourceEvent->fresh(),
        null,
        $malaysiaId,
    );

    expect($prefill['submission_timezone'])->toBe('Asia/Kuala_Lumpur');
});

it('exposes collapsed effective zones through the frontend api contract', function () {
    $malaysiaId = (string) ensureTestAddressCountry('MY', 'Malaysia', 'MYS', ['Asia/Kuala_Lumpur', 'Asia/Kuching'], '60')->getKey();
    $indonesiaId = submissionTimezoneMultiCountry();

    $contract = $this->getJson(route('api.client.forms.submit-event'))
        ->assertOk()
        ->json('data');

    $timezoneField = collect($contract['fields'])->firstWhere('name', 'submission_timezone');
    $rule = collect($contract['conditional_rules'])->firstWhere('field', 'submission_timezone');

    expect($timezoneField['options_by_country'][$malaysiaId] ?? null)->toBe(['Asia/Kuala_Lumpur'])
        ->and($timezoneField['options_by_country'][$indonesiaId] ?? null)->toBe(['Asia/Jakarta', 'Asia/Jayapura'])
        ->and($rule['required_when']['submission_country_id'] ?? [])->toContain($indonesiaId)
        ->and($rule['required_when']['submission_country_id'] ?? [])->not->toContain($malaysiaId);
});

it('clears the timezone choice when the country changes and auto-fills single zones', function () {
    $malaysiaId = (string) ensureTestMalaysiaCountry()->getKey();
    $indonesiaId = submissionTimezoneMultiCountry();
    $singaporeId = (string) ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65')->getKey();
    $americaId = (string) ensureTestAddressCountry('US', 'United States', 'USA', ['America/New_York', 'America/Los_Angeles'], '1')->getKey();

    Livewire::test(Create::class)
        ->assertSet('data.submission_timezone', 'Asia/Kuala_Lumpur')
        ->set('data.submission_country_id', $indonesiaId)
        ->assertSet('data.submission_timezone', null)
        ->set('data.submission_timezone', 'Asia/Jayapura')
        ->assertSet('data.submission_timezone', 'Asia/Jayapura')
        ->set('data.submission_country_id', $americaId)
        ->assertSet('data.submission_timezone', null)
        ->set('data.submission_timezone', 'America/New_York')
        ->set('data.submission_country_id', $singaporeId)
        ->assertSet('data.submission_timezone', 'Asia/Singapore')
        ->set('data.submission_country_id', $malaysiaId)
        ->assertSet('data.submission_timezone', 'Asia/Kuala_Lumpur');
});

it('rejects invalid and cross-country timezones without writing', function (string $timezone) {
    $fixtures = submissionTimezoneFixtures();
    $indonesiaId = submissionTimezoneMultiCountry();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submissionTimezoneFormData($fixtures, [
            'title' => 'Rejected Timezone Attempt',
            'submission_country_id' => $indonesiaId,
            'submission_timezone' => $timezone,
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.submission_timezone']);

    expect(Event::query()->where('title', 'Rejected Timezone Attempt')->exists())->toBeFalse();
})->with([
    'unknown zone' => 'Asia/Tokyo',
    'cross-country zone' => 'Asia/Singapore',
    'overlong value' => str_repeat('a', 65),
]);

it('rejects an explicit invalid timezone for single-zone countries', function () {
    $fixtures = submissionTimezoneFixtures();
    $singaporeId = (string) ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65')->getKey();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submissionTimezoneFormData($fixtures, [
            'title' => 'Single Zone Explicit Invalid',
            'submission_country_id' => $singaporeId,
            'submission_timezone' => 'Asia/Tokyo',
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.submission_timezone']);

    expect(Event::query()->where('title', 'Single Zone Explicit Invalid')->exists())->toBeFalse();
});

it('persists the second timezone with correct UTC conversion', function () {
    $fixtures = submissionTimezoneFixtures();
    $indonesiaId = submissionTimezoneMultiCountry();
    syncPrimaryAddressForTest($fixtures['institution'], ['country_id' => $indonesiaId]);
    $eventDate = now()->addDays(5)->format('Y-m-d');

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submissionTimezoneFormData($fixtures, [
            'title' => 'Second Timezone Conversion',
            'submission_country_id' => $indonesiaId,
            'submission_timezone' => 'Asia/Jayapura',
            'event_date' => $eventDate,
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '20:15',
            'end_time' => '21:30',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Second Timezone Conversion')->sole();

    expect($event->timezone)->toBe('Asia/Jayapura')
        ->and($event->starts_at->timezone('Asia/Jayapura')->format('Y-m-d H:i'))->toBe("{$eventDate} 20:15")
        ->and($event->starts_at->timezone('UTC')->format('H:i'))->toBe('11:15')
        ->and($event->ends_at->timezone('Asia/Jayapura')->format('H:i'))->toBe('21:30')
        ->and($event->ends_at->timezone('UTC')->format('H:i'))->toBe('12:30');
});

it('blocks submissions for countries without linked timezones', function () {
    $fixtures = submissionTimezoneFixtures();
    $countryId = (string) ensureTestAddressCountry('AQ', 'Antarctica Test', 'AQT', [], null)->getKey();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submissionTimezoneFormData($fixtures, [
            'title' => 'Zero Timezone Country',
            'submission_country_id' => $countryId,
            'submission_timezone' => null,
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.submission_country_id']);

    expect(Event::query()->where('title', 'Zero Timezone Country')->exists())->toBeFalse();
});

it('counts the timezone in form progress only for multi-zone countries', function () {
    $indonesiaId = submissionTimezoneMultiCountry();
    $malaysiaId = (string) ensureTestMalaysiaCountry()->getKey();

    $component = Livewire::test(Create::class)
        ->set('data.submission_country_id', $indonesiaId);

    $missingChoiceProgress = $component->instance()->formProgress();

    $component->set('data.submission_timezone', 'Asia/Jakarta');

    $chosenProgress = $component->instance()->formProgress();

    expect($chosenProgress)->toBeGreaterThan($missingChoiceProgress);

    $component->set('data.submission_country_id', $malaysiaId);

    expect($component->instance()->clientProgressConfiguration()['multi_timezone_country_ids'])
        ->toContain($indonesiaId)
        ->not->toContain($malaysiaId);

    expect($component->html())
        ->toContain("'submission_timezone'")
        ->toContain('multi_timezone_country_ids');
});

it('shows the chosen timezone in the review preview', function () {
    $indonesiaId = submissionTimezoneMultiCountry();

    $component = Livewire::withQueryParams(['step' => 'form.semak-sebelum-hantar::data::wizard-step'])
        ->test(Create::class);

    setSubmitEventFormState($component, [
        'submission_country_id' => $indonesiaId,
        'submission_timezone' => 'Asia/Jayapura',
    ]);

    $component
        ->assertSee(__('Submission timezone'))
        ->assertSee('Asia/Jayapura');
});

it('preserves duplicate timezone only when valid for the selected country', function () {
    $indonesiaId = submissionTimezoneMultiCountry();
    $singaporeId = (string) ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65')->getKey();

    $sourceEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
        'timezone' => 'Asia/Jayapura',
        'starts_at' => now()->addDays(9)->setTimezone('UTC'),
    ]);

    Livewire::withQueryParams(['duplicate' => $sourceEvent->getKey()])
        ->test(Create::class)
        ->assertSet('data.submission_timezone', 'Asia/Kuala_Lumpur');

    $component = Livewire::test(Create::class)
        ->set('data.submission_country_id', $indonesiaId);

    $prefill = SubmitEventPrefill::duplicateDefaults(
        $sourceEvent->fresh(),
        null,
        $indonesiaId,
    );

    expect($prefill['submission_timezone'])->toBe('Asia/Jayapura');

    $prefill = SubmitEventPrefill::duplicateDefaults(
        $sourceEvent->fresh(),
        null,
        $singaporeId,
    );

    expect($prefill['submission_timezone'])->toBe('Asia/Singapore')
        ->and($component->get('data.submission_timezone'))->toBeNull();
});

it('enforces the timezone choice through the frontend api', function () {
    $fixtures = submissionTimezoneFixtures();
    $indonesiaId = submissionTimezoneMultiCountry();
    syncPrimaryAddressForTest($fixtures['institution'], ['country_id' => $indonesiaId]);

    $payload = [
        'title' => 'API Timezone Event',
        'description' => 'API timezone test.',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => 'lain_waktu',
        'custom_time' => '20:15',
        'event_format' => 'physical',
        'visibility' => 'public',
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'languages' => [languageId('ms')],
        'domain_tags' => [$fixtures['domain_tag']->getKey()],
        'primary_organizer_id' => $fixtures['institution']->getKey(),
        'persons' => [$fixtures['person']->getKey()],
        'submission_country_id' => $indonesiaId,
        'submitter_name' => 'Guest Submitter',
        'submitter_email' => 'guest@example.test',
    ];

    $this->postJson(route('api.client.submit-event.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['submission_timezone']);

    $this->postJson(route('api.client.submit-event.store'), array_merge($payload, [
        'title' => 'API Second Timezone Event',
        'submission_timezone' => 'Asia/Singapore',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['submission_timezone']);

    $this->postJson(route('api.client.submit-event.store'), array_merge($payload, [
        'title' => 'API Second Timezone Event',
        'submission_timezone' => 'Asia/Jayapura',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.event.title', 'API Second Timezone Event');

    $event = withGlobalOwnerContext(fn () => Event::query()->where('title', 'API Second Timezone Event')->firstOrFail());

    expect($event->timezone)->toBe('Asia/Jayapura')
        ->and($event->starts_at?->timezone('Asia/Jayapura')->format('H:i'))->toBe('20:15')
        ->and($event->starts_at?->timezone('UTC')->format('H:i'))->toBe('11:15')
        ->and(Event::query()->where('title', 'API Timezone Event')->exists())->toBeFalse();
});

it('collapses equivalent linked zones to the mapped representative', function (
    string $iso2,
    string $name,
    string $iso3,
    array $linked,
    string $expected,
    bool $beatsAlphabetical,
) {
    $countryId = (string) ensureTestAddressCountry($iso2, $name, $iso3, $linked, null)->getKey();
    $policy = app(SubmissionTimingPolicy::class);

    // Locks the dataset premise: beating cases must keep an alphabetically
    // earlier linked zone, coincident cases must stay alphabetically first.
    expect(min($linked) === $expected)->toBe(! $beatsAlphabetical);

    expect($policy->countryTimezones($countryId))->toBe([$expected])
        ->and($policy->submissionTimezoneRequired($countryId))->toBeFalse()
        ->and($policy->defaultSubmissionTimezone($countryId))->toBe($expected)
        ->and($policy->previewSubmissionTimezone($countryId))->toBe($expected)
        ->and($policy->resolveSubmissionTimezone($countryId))->toBe($expected)
        ->and($policy->resolveSubmissionTimezone($countryId, $expected))->toBe($expected);

    $collapsedAway = array_values(array_diff($linked, [$expected]))[0];

    expect(fn () => $policy->resolveSubmissionTimezone($countryId, $collapsedAway))
        ->toThrow(ValidationException::class);
})->with([
    'Argentina' => ['AR', 'Argentina', 'ARG', ['America/Argentina/Buenos_Aires', 'America/Argentina/Cordoba', 'America/Argentina/San_Luis', 'America/Argentina/Ushuaia'], 'America/Argentina/Buenos_Aires', false],
    'Cyprus' => ['CY', 'Cyprus', 'CYP', ['Asia/Famagusta', 'Asia/Nicosia'], 'Asia/Nicosia', true],
    'Germany' => ['DE', 'Germany', 'DEU', ['Europe/Berlin', 'Europe/Busingen'], 'Europe/Berlin', false],
    'Kazakhstan' => ['KZ', 'Kazakhstan', 'KAZ', ['Asia/Almaty', 'Asia/Aqtau', 'Asia/Oral', 'Asia/Qyzylorda'], 'Asia/Almaty', false],
    'Marshall Islands' => ['MH', 'Marshall Islands', 'MHL', ['Pacific/Kwajalein', 'Pacific/Majuro'], 'Pacific/Majuro', true],
    'Palestine' => ['PS', 'Palestine', 'PSE', ['Asia/Gaza', 'Asia/Hebron'], 'Asia/Gaza', false],
    'Uzbekistan' => ['UZ', 'Uzbekistan', 'UZB', ['Asia/Samarkand', 'Asia/Tashkent'], 'Asia/Tashkent', true],
]);

it('falls back to canonical order when the mapped representative is not linked', function (
    string $iso2,
    string $name,
    string $iso3,
    array $linked,
    string $expected,
    string $unlinkedPreferred,
) {
    $countryId = (string) ensureTestAddressCountry($iso2, $name, $iso3, $linked, null)->getKey();
    $policy = app(SubmissionTimingPolicy::class);

    expect($policy->countryTimezones($countryId))->toBe([$expected])
        ->and($policy->defaultSubmissionTimezone($countryId))->toBe($expected)
        ->and($policy->previewSubmissionTimezone($countryId))->toBe($expected)
        ->and($policy->resolveSubmissionTimezone($countryId))->toBe($expected);

    // The unlinked preference must neither appear in the effective options
    // nor be accepted as an explicit choice.
    expect($policy->countryTimezones($countryId))->not->toContain($unlinkedPreferred);

    expect(fn () => $policy->resolveSubmissionTimezone($countryId, $unlinkedPreferred))
        ->toThrow(ValidationException::class);
})->with([
    'Argentina without Buenos Aires' => ['AR', 'Argentina', 'ARG', ['America/Argentina/Cordoba', 'America/Argentina/Mendoza'], 'America/Argentina/Cordoba', 'America/Argentina/Buenos_Aires'],
    'Kazakhstan without Almaty' => ['KZ', 'Kazakhstan', 'KAZ', ['Asia/Aqtau', 'Asia/Oral'], 'Asia/Aqtau', 'Asia/Almaty'],
]);

it('still requires a choice when a mapped country links divergent zones', function () {
    // Pacific/Auckland stands in for a divergent linked zone (data anomaly):
    // any divergence in the set must disable collapsing even though the
    // mapped representative is linked.
    $countryId = (string) ensureTestAddressCountry('DE', 'Germany', 'DEU', ['Europe/Berlin', 'Pacific/Auckland'], '49')->getKey();
    $policy = app(SubmissionTimingPolicy::class);

    expect($policy->countryTimezones($countryId))->toBe(['Europe/Berlin', 'Pacific/Auckland'])
        ->and($policy->submissionTimezoneRequired($countryId))->toBeTrue()
        ->and($policy->defaultSubmissionTimezone($countryId))->toBeNull()
        ->and($policy->previewSubmissionTimezone($countryId))->toBe(config('app.timezone', 'UTC'))
        ->and($policy->resolveSubmissionTimezone($countryId, 'Pacific/Auckland'))->toBe('Pacific/Auckland');

    expect(fn () => $policy->resolveSubmissionTimezone($countryId))
        ->toThrow(ValidationException::class);
});
