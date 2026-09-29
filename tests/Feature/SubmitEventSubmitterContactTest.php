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
use App\Support\Submission\SubmitterContactRules;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();

    $this->domainTag = submitEventTerm('domain');
    $this->disciplineTag = submitEventTerm('discipline');
});

/**
 * @return array<string, mixed>
 */
function submitEventContactPayload(EventTerm $domainTag, EventTerm $disciplineTag, array $overrides = []): array
{
    return array_merge([
        'title' => 'Submitter Contact Submission',
        'description' => 'Submitter contact validation test.',
        'event_date' => now()->addDays(5)->format('Y-m-d'),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [languageId('ms')],
        'domain_tags' => [$domainTag->id],
        'discipline_tags' => [$disciplineTag->id],
        'submitter_name' => 'Guest Submitter',
    ], $overrides);
}

function submitEventContactEntities(): array
{
    $institution = Institution::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);

    return [$institution, $person];
}

it('accepts valid submitter phone formats', function (string $phone) {
    expect(SubmitterContactRules::isValidPhone($phone))->toBeTrue();
})->with([
    'international' => '+60123456789',
    'international spaced' => '+60 12-345 6789',
    'local mobile' => '0123456789',
    'local dashed' => '012-3456789',
    'landline' => '03-12345678',
    'parenthesised area code' => '(03) 1234 5678',
    'singapore' => '+65 8123 4567',
    'shortest' => '1234567',
]);

it('rejects invalid submitter phone formats', function (mixed $phone) {
    expect(SubmitterContactRules::isValidPhone($phone))->toBeFalse();
})->with([
    'empty' => '',
    'blank' => '   ',
    'null' => null,
    'letters' => 'abcdefg',
    'mixed letters' => '0123-abc',
    'too few digits' => '123456',
    'too many digits' => '1234567890123456',
    'too long' => '+60123456789012345678',
    'double plus' => '++60123456789',
    'plus only' => '+',
    'separators only' => '--()--',
    'trailing separator' => '0123456789-',
]);

it('validates submitter email formats', function (mixed $email, bool $valid) {
    expect(SubmitterContactRules::isValidEmail($email))->toBe($valid);
})->with([
    'simple' => ['guest@example.com', true],
    'plus addressing' => ['guest+majlis@example.com', true],
    'subdomain' => ['guest@mail.example.coop', true],
    'missing domain' => ['guest@', false],
    'missing at' => ['guest.example.com', false],
    'single label domain' => ['guest@mail', true],
    'double dot domain' => ['guest@example..com', false],
    'spaces' => ['gue st@example.com', false],
    'empty' => ['', false],
    'null' => [null, false],
]);

it('rejects guest submissions with an invalid phone number', function () {
    [$institution, $person] = submitEventContactEntities();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventContactPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $institution->id,
            'persons' => [$person->id],
            'submitter_email' => 'guest@example.com',
            'submitter_phone' => 'bukan-nombor',
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.submitter_phone']);
});

it('rejects guest submissions with an invalid email address', function () {
    [$institution, $person] = submitEventContactEntities();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventContactPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $institution->id,
            'persons' => [$person->id],
            'submitter_email' => 'bukan-email',
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.submitter_email']);
});

it('requires guests to provide an email or a phone number', function () {
    [$institution, $person] = submitEventContactEntities();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventContactPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $institution->id,
            'persons' => [$person->id],
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.submitter_email', 'data.submitter_phone']);
});

it('accepts guest submissions with a valid phone number and no email', function () {
    [$institution, $person] = submitEventContactEntities();

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitEventContactPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Phone Only Contact Event',
            'primary_organizer_id' => $institution->id,
            'persons' => [$person->id],
            'submitter_phone' => '+60123456789',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    expect(Event::query()->where('title', 'Phone Only Contact Event')->exists())->toBeTrue();
});

it('rejects api submissions with an invalid phone number', function () {
    [$institution, $person] = submitEventContactEntities();
    $domainTag = submitEventTerm('domain');

    $this->postJson(route('api.client.submit-event.store'), [
        'title' => 'API Invalid Phone Event',
        'description' => 'API description',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => 'selepas_maghrib',
        'event_format' => 'physical',
        'visibility' => 'public',
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'languages' => [languageId('ms')],
        'domain_tags' => [$domainTag->getKey()],
        'primary_organizer_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'submission_country_id' => ensureTestMalaysiaCountry()->getKey(),
        'submitter_name' => 'Guest Submitter',
        'submitter_email' => 'guest@example.test',
        'submitter_phone' => '12',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['submitter_phone']);
});
