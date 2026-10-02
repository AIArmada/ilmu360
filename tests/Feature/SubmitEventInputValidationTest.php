<?php

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Events\SubmitFrontendEventAction;
use App\Actions\Events\ValidateEventSubmissionInputAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    fakePrayerTimesApi();

    $this->seed(EventRoleSeeder::class);

    // Seed the canonical category catalog before any Event factory warms the
    // per-request category cache; factories must never see an empty catalog.
    eventCategoryId('kuliah_ceramah');

    $this->domainTag = submitEventTerm('domain');
    $this->disciplineTag = submitEventTerm('discipline');
});

/**
 * @param  array<string, mixed>  $overrides
 * @param  list<string>  $without
 * @return array<string, mixed>
 */
function submitInputValidationPayload(EventTerm $domainTag, EventTerm $disciplineTag, array $overrides = [], array $without = []): array
{
    $payload = array_merge([
        'title' => 'Input Validation Submission',
        'description' => 'Structural input validation test.',
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
        'submitter_name' => 'Input Validation Submitter',
        'submitter_email' => 'input-validation@example.com',
    ], $overrides);

    foreach ($without as $key) {
        unset($payload[$key]);
    }

    return $payload;
}

function submitInputValidationRequest(): Request
{
    return Request::create('/hantar-majlis', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
}

function enableInputValidationCaptchaTrap(): void
{
    config()->set('services.turnstile.enabled', true);
    config()->set('services.turnstile.site_key', 'test-site');
    config()->set('services.turnstile.secret_key', 'test-secret');

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);
}

it('rejects malformed structural input before captcha or writes', function (array $overrides, string $expectedKey) {
    enableInputValidationCaptchaTrap();

    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $eventsBefore = Event::query()->count();

    try {
        app(SubmitFrontendEventAction::class)->handle(
            state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, array_merge([
                'primary_organizer_id' => $person->getKey(),
                'persons' => [$person->getKey()],
                'location_type' => 'venue',
                'location_venue_id' => $venue->getKey(),
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            ], $overrides)),
            request: submitInputValidationRequest(),
            submitter: $user,
            validationKeyPrefix: 'data.',
        );

        $this->fail("Expected a validation error for [{$expectedKey}].");
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($expectedKey);
    }

    Http::assertNothingSent();
    expect(Event::query()->count())->toBe($eventsBefore);
})->with([
    'blank title' => [['title' => ''], 'data.title'],
    'long title' => [['title' => str_repeat('a', 256)], 'data.title'],
    'non-string title' => [['title' => 123], 'data.title'],
    'bad event format' => [['event_format' => 'bogus'], 'data.event_format'],
    'bad visibility' => [['visibility' => 'bogus'], 'data.visibility'],
    'bad gender' => [['gender' => 'bogus'], 'data.gender'],
    'non-array age group' => [['age_group' => 'bogus'], 'data.age_group'],
    'empty age group' => [['age_group' => []], 'data.age_group'],
    'bad age group item' => [['age_group' => ['bogus']], 'data.age_group'],
    'null age group item' => [['age_group' => [null]], 'data.age_group'],
    'non-array categories' => [['event_category_ids' => 'bogus'], 'data.event_category_ids'],
    'bad category id' => [['event_category_ids' => ['not-a-uuid']], 'data.event_category_ids'],
    'null category id' => [['event_category_ids' => [null]], 'data.event_category_ids'],
    'bad organizer id' => [['primary_organizer_id' => 'not-a-uuid'], 'data.primary_organizer_id'],
    'bad venue id' => [['location_venue_id' => 'not-a-uuid'], 'data.location_venue_id'],
    'bad occurrence id' => [['event_occurrence_id' => 'not-a-uuid'], 'data.event_occurrence_id'],
    'bad space id' => [['space_id' => 'not-a-uuid'], 'data.space_id'],
    'non-array space ids' => [['space_ids' => 'bogus'], 'data.space_ids'],
    'bad space ids item' => [['space_ids' => ['not-a-uuid']], 'data.space_ids'],
    'bad languages item' => [['languages' => ['not-a-uuid']], 'data.languages'],
    'bad persons item' => [['persons' => ['not-a-uuid']], 'data.persons'],
    'bad references item' => [['references' => ['not-a-uuid']], 'data.references'],
    'non-array domain tags' => [['domain_tags' => 'bogus'], 'data.domain_tags'],
    'blank tag item' => [['domain_tags' => ['']], 'data.domain_tags'],
    'null tag item' => [['domain_tags' => [null]], 'data.domain_tags'],
    'long tag item' => [['domain_tags' => [str_repeat('t', 256)]], 'data.domain_tags'],
    'non-array key people' => [['other_key_people' => 'bogus'], 'data.other_key_people'],
    'non-array key people row' => [['other_key_people' => ['bogus']], 'data.other_key_people.0'],
    'missing role code' => [['other_key_people' => [['involveable_id' => (string) Str::uuid()]]], 'data.other_key_people.0.role_code'],
    'unknown role code' => [['other_key_people' => [['role_code' => 'unknown', 'display_name' => 'Guest']]], 'data.other_key_people.0.role_code'],
    'speaker in other roles' => [['other_key_people' => [['role_code' => 'speaker', 'display_name' => 'Guest']]], 'data.other_key_people.0.role_code'],
    'key person without identity' => [['other_key_people' => [['role_code' => 'moderator']]], 'data.other_key_people.0.display_name'],
    'blank key person identity' => [['other_key_people' => [['role_code' => 'moderator', 'display_name' => '   ']]], 'data.other_key_people.0.display_name'],
    'bad involveable id' => [['other_key_people' => [['role_code' => 'moderator', 'involveable_id' => 'bad']]], 'data.other_key_people.0.involveable_id'],
    'bad key people visibility' => [['other_key_people' => [['role_code' => 'moderator', 'visibility' => 'bogus']]], 'data.other_key_people.0.visibility'],
    'bad children allowed' => [['children_allowed' => 'yes'], 'data.children_allowed'],
    'bad muslim only' => [['is_muslim_only' => 2], 'data.is_muslim_only'],
    'bad same as institution' => [['location_same_as_institution' => 'maybe'], 'data.location_same_as_institution'],
    'bad location type' => [['location_type' => 'bogus'], 'data.location_type'],
    'array captcha token' => [['captcha_token' => ['token']], 'data.captcha_token'],
    'numeric captcha token' => [['captcha_token' => 123], 'data.captcha_token'],
    'array institution scope' => [['scoped_institution_id' => ['forged']], 'data.scoped_institution_id'],
    'invalid event url' => [['event_url' => 'not a url'], 'data.event_url'],
    'script event url' => [['event_url' => 'javascript:alert(1)'], 'data.event_url'],
    'data live url' => [['live_url' => 'data:text/html,<script>alert(1)</script>'], 'data.live_url'],
    'non-web live url' => [['live_url' => 'ftp://example.com/video'], 'data.live_url'],
    'long submitter name' => [['submitter_name' => str_repeat('n', 256)], 'data.submitter_name'],
    'non-string notes' => [['notes' => 42], 'data.notes'],
    'non-string description' => [['description' => 42], 'data.description'],
    'localized description with non-string value' => [['description' => ['ms' => 42]], 'data.description'],
    'localized description with nested array' => [['description' => ['ms' => ['nested']]], 'data.description'],
    'localized description with non-string key' => [['description' => ['not a map']], 'data.description'],
    'localized description with blank key' => [['description' => ['' => 'blank locale']], 'data.description'],
]);

it('requires the title and event categories', function () {
    enableInputValidationCaptchaTrap();

    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    foreach (['title', 'event_category_ids'] as $missing) {
        try {
            app(SubmitFrontendEventAction::class)->handle(
                state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, [
                    'primary_organizer_id' => $person->getKey(),
                    'persons' => [$person->getKey()],
                    'location_type' => 'venue',
                    'location_venue_id' => $venue->getKey(),
                    'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
                ], without: [$missing]),
                request: submitInputValidationRequest(),
                submitter: $user,
                validationKeyPrefix: 'data.',
            );

            $this->fail("Expected a validation error for missing [{$missing}].");
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey("data.{$missing}");
        }
    }

    Http::assertNothingSent();
    expect(Event::query()->count())->toBe(0);
});

it('accepts enum objects and applies omitted-key defaults', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, [
            'event_format' => EventFormat::Physical,
            'age_group' => [EventAgeGroup::AllAges],
            'primary_organizer_id' => $person->getKey(),
            'persons' => [$person->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ], without: ['visibility', 'gender', 'children_allowed', 'is_muslim_only']),
        request: submitInputValidationRequest(),
        submitter: $user,
    );

    $event = $result['event']->fresh();

    expect($event->visibility)->toBe(EventVisibility::Public)
        ->and($event->getAttributes()['delivery_mode'])->toBe(EventFormat::Physical->value)
        ->and((bool) $event->children_allowed)->toBeTrue()
        ->and($result['visibility'])->toBe(EventVisibility::Public->value);
});

it('accepts canonical localized description arrays', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, [
            'description' => ['ms' => 'Huraian majlis.', 'en' => 'Event description.'],
            'primary_organizer_id' => $person->getKey(),
            'persons' => [$person->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitInputValidationRequest(),
        submitter: $user,
    );

    $event = $result['event']->fresh();

    expect($event->getKey())->not->toBeNull()
        ->and($event->description)->toBe(['ms' => 'Huraian majlis.', 'en' => 'Event description.'])
        ->and(Event::query()->where('title', 'Input Validation Submission')->exists())->toBeTrue();
});

it('persists localized descriptions to sessions as locale scalars', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    $container = Event::factory()->create([
        'status' => 'approved',
        'created_by_type' => $owner->getMorphClass(),
        'created_by_id' => $owner->getKey(),
    ]);
    $container->setPrimaryOrganizer($organizer);
    $container = $container->fresh();

    addTestMember($organizer, $owner, MemberRole::Owner);

    app()->setLocale('ms');

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Localized Session Submission',
            'description' => ['ms' => 'Huraian sesi.', 'en' => 'Session description.'],
            'primary_organizer_id' => $organizer->getKey(),
            'persons' => [$organizer->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitInputValidationRequest(),
        submitter: $owner,
        eventContainer: $container,
    );

    $session = $result['session']->fresh();

    expect($session->summary)->toBe('Huraian sesi.')
        ->and($session->description)->toBe('Huraian sesi.')
        ->and($session->metadata['description_localized'])->toBe(['ms' => 'Huraian sesi.', 'en' => 'Session description.']);

    app()->setLocale('en');

    $fallback = app(SubmitFrontendEventAction::class)->handle(
        state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Fallback Session Submission',
            'description' => ['ms' => 'Hanya Bahasa Melayu.'],
            'primary_organizer_id' => $organizer->getKey(),
            'persons' => [$organizer->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitInputValidationRequest(),
        submitter: $owner,
        eventContainer: $container,
    );

    $fallbackSession = $fallback['session']->fresh();

    expect($fallbackSession->summary)->toBe('Hanya Bahasa Melayu.')
        ->and($fallbackSession->metadata['description_localized'])->toBe(['ms' => 'Hanya Bahasa Melayu.']);
});

it('normalizes blank optional enums to canonical defaults', function () {
    $categoryId = (string) Str::uuid();

    $normalized = app(ValidateEventSubmissionInputAction::class)->handle([
        'title' => 'Blank Enum Defaults',
        'event_category_ids' => [$categoryId],
        'event_format' => '',
        'visibility' => '   ',
        'gender' => null,
    ]);

    expect($normalized['event_format'])->toBe(EventFormat::Physical->value)
        ->and($normalized['visibility'])->toBe(EventVisibility::Public->value)
        ->and($normalized['gender'])->toBe(EventGenderRestriction::All->value);

    $explicit = app(ValidateEventSubmissionInputAction::class)->handle([
        'title' => 'Explicit Enum Values',
        'event_category_ids' => [$categoryId],
        'event_format' => EventFormat::Online->value,
        'visibility' => EventVisibility::Private->value,
        'gender' => EventGenderRestriction::WomenOnly->value,
    ]);

    expect($explicit['event_format'])->toBe(EventFormat::Online->value)
        ->and($explicit['visibility'])->toBe(EventVisibility::Private->value)
        ->and($explicit['gender'])->toBe(EventGenderRestriction::WomenOnly->value);
});

it('persists canonical enums for blank optional enum input', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, [
            'event_format' => '',
            'visibility' => '  ',
            'gender' => '',
            'primary_organizer_id' => $person->getKey(),
            'persons' => [$person->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitInputValidationRequest(),
        submitter: $user,
    );

    $event = $result['event']->fresh();

    expect($event->getAttributes()['delivery_mode'])->toBe(EventFormat::Physical->value)
        ->and($event->visibility)->toBe(EventVisibility::Public)
        ->and($event->gender)->toBe(EventGenderRestriction::All->value);
});

it('returns UUIDs trimmed exactly as validated', function () {
    $categoryId = (string) Str::uuid();
    $organizerId = (string) Str::uuid();
    $personId = (string) Str::uuid();

    $normalized = app(ValidateEventSubmissionInputAction::class)->handle([
        'title' => 'Whitespace UUIDs',
        'event_category_ids' => ["  {$categoryId}  "],
        'primary_organizer_id' => "  {$organizerId}  ",
        'persons' => ["  {$personId}  "],
        'space_ids' => [''],
        'other_key_people' => [['role_code' => 'moderator', 'involveable_id' => "  {$personId}  "]],
    ]);

    expect($normalized['event_category_ids'])->toBe([$categoryId])
        ->and($normalized['primary_organizer_id'])->toBe($organizerId)
        ->and($normalized['persons'])->toBe([$personId])
        ->and($normalized['space_ids'])->toBe([null])
        ->and($normalized['other_key_people'][0]['involveable_id'])->toBe($personId);
});

it('normalizes backed enums and collections while keeping malformed data for the rules', function () {
    $categoryId = (string) Str::uuid();
    $personId = (string) Str::uuid();

    $normalized = app(ValidateEventSubmissionInputAction::class)->handle([
        'title' => 'Direct Validator Check',
        'event_category_ids' => collect([$categoryId]),
        'event_format' => EventFormat::Online,
        'visibility' => EventVisibility::Private,
        'age_group' => collect([EventAgeGroup::AllAges]),
        'persons' => collect([$personId]),
        'domain_tags' => collect([EventFormat::Online]),
    ]);

    expect($normalized['event_format'])->toBe(EventFormat::Online->value)
        ->and($normalized['visibility'])->toBe(EventVisibility::Private->value)
        ->and($normalized['gender'])->toBe(EventGenderRestriction::All->value)
        ->and($normalized['prayer_time'])->toBe('')
        ->and($normalized['age_group'])->toBe([EventAgeGroup::AllAges->value])
        ->and($normalized['event_category_ids'])->toBe([$categoryId])
        ->and($normalized['persons'])->toBe([$personId])
        ->and($normalized['domain_tags'])->toBe([EventFormat::Online->value]);

    expect(fn () => app(ValidateEventSubmissionInputAction::class)->handle([
        'title' => 'Direct Validator Check',
        'event_category_ids' => [$categoryId],
        'event_format' => 'bogus',
    ]))->toThrow(ValidationException::class);
});

it('reports unprefixed keys without a prefix and prefixed keys with one', function () {
    $failing = ['title' => '', 'event_category_ids' => ['not-a-uuid']];

    try {
        app(ValidateEventSubmissionInputAction::class)->handle($failing);

        $this->fail('Expected unprefixed validation errors.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKeys(['title', 'event_category_ids']);
    }

    try {
        app(ValidateEventSubmissionInputAction::class)->handle($failing, 'data.');

        $this->fail('Expected prefixed validation errors.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKeys(['data.title', 'data.event_category_ids']);
    }
});

it('rejects unknown event category ids before captcha or writes', function () {
    enableInputValidationCaptchaTrap();

    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $eventsBefore = Event::query()->count();

    try {
        app(SubmitFrontendEventAction::class)->handle(
            state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, [
                'event_category_ids' => [(string) Str::uuid()],
                'primary_organizer_id' => $person->getKey(),
                'persons' => [$person->getKey()],
                'location_type' => 'venue',
                'location_venue_id' => $venue->getKey(),
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            ]),
            request: submitInputValidationRequest(),
            submitter: $user,
            validationKeyPrefix: 'data.',
        );

        $this->fail('Expected a validation error for unknown event_category_ids.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.event_category_ids')
            ->and($exception->errors()['data.event_category_ids'])->toContain(__('Pilihan kategori majlis tidak sah.'));
    }

    Http::assertNothingSent();
    expect(Event::query()->count())->toBe($eventsBefore);
});

it('does not accept hidden taxonomy term selections outside the public form contract', function () {
    $taxonomy = EventTaxonomy::query()->create([
        'code' => 'internal-review',
        'name' => 'Internal review',
        'is_hierarchical' => false,
        'is_active' => true,
    ]);
    $internalTerm = EventTerm::query()->create([
        'event_taxonomy_id' => $taxonomy->getKey(),
        'code' => 'internal-only',
        'name' => 'Internal only',
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $person->getKey(),
            'persons' => [$person->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            'taxonomy_term_ids' => [$internalTerm->getKey()],
        ]),
        request: submitInputValidationRequest(),
        submitter: $user,
    );

    expect($result['event']->classifications()->where('event_term_id', $internalTerm->getKey())->exists())->toBeFalse()
        ->and($result['event']->classifications()->where('taxonomy_code', 'event_category')->exists())->toBeTrue();
});

it('rejects wrong-taxonomy event category selections without a prefix', function () {
    enableInputValidationCaptchaTrap();

    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $eventsBefore = Event::query()->count();

    try {
        app(SubmitFrontendEventAction::class)->handle(
            state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, [
                'event_category_ids' => [(string) $this->domainTag->getKey()],
                'primary_organizer_id' => $person->getKey(),
                'persons' => [$person->getKey()],
                'location_type' => 'venue',
                'location_venue_id' => $venue->getKey(),
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            ]),
            request: submitInputValidationRequest(),
            submitter: $user,
        );

        $this->fail('Expected a validation error for wrong-taxonomy event_category_ids.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('event_category_ids')
            ->and($exception->errors()['event_category_ids'])->toContain(__('Pilihan kategori majlis tidak sah.'));
    }

    Http::assertNothingSent();
    expect(Event::query()->count())->toBe($eventsBefore);
});

it('rejects inactive event categories before captcha or writes', function () {
    enableInputValidationCaptchaTrap();

    eventCategoryId('kuliah_ceramah');

    $taxonomy = EventTaxonomy::query()->where('code', 'event_category')->firstOrFail();

    $inactive = EventTerm::query()->create([
        'event_taxonomy_id' => $taxonomy->getKey(),
        'code' => 'test-inactive-'.Str::lower(Str::random(8)),
        'name' => 'Inactive Test Category',
        'sort_order' => 999,
        'is_active' => false,
    ]);

    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $eventsBefore = Event::query()->count();

    try {
        app(SubmitFrontendEventAction::class)->handle(
            state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, [
                'event_category_ids' => [(string) $inactive->getKey()],
                'primary_organizer_id' => $person->getKey(),
                'persons' => [$person->getKey()],
                'location_type' => 'venue',
                'location_venue_id' => $venue->getKey(),
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            ]),
            request: submitInputValidationRequest(),
            submitter: $user,
            validationKeyPrefix: 'data.',
        );

        $this->fail('Expected a validation error for inactive event_category_ids.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.event_category_ids')
            ->and($exception->errors()['data.event_category_ids'])->toContain(__('Pilihan kategori majlis tidak sah.'));
    }

    Http::assertNothingSent();
    expect(Event::query()->count())->toBe($eventsBefore);
});

it('rejects mixed valid and unknown event categories without silently dropping invalid', function () {
    enableInputValidationCaptchaTrap();

    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $eventsBefore = Event::query()->count();

    try {
        app(SubmitFrontendEventAction::class)->handle(
            state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, [
                'event_category_ids' => [eventCategoryId('kuliah_ceramah'), (string) Str::uuid()],
                'primary_organizer_id' => $person->getKey(),
                'persons' => [$person->getKey()],
                'location_type' => 'venue',
                'location_venue_id' => $venue->getKey(),
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            ]),
            request: submitInputValidationRequest(),
            submitter: $user,
            validationKeyPrefix: 'data.',
        );

        $this->fail('Expected a validation error for mixed valid/unknown event_category_ids.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.event_category_ids')
            ->and($exception->errors()['data.event_category_ids'])->toContain(__('Pilihan kategori majlis tidak sah.'));
    }

    Http::assertNothingSent();
    expect(Event::query()->count())->toBe($eventsBefore);
});

it('accepts valid parent and child categories and normalizes canonically', function () {
    eventCategoryId('kuliah_ceramah');

    $taxonomy = EventTaxonomy::query()->where('code', 'event_category')->firstOrFail();

    $parent = EventTerm::query()->create([
        'event_taxonomy_id' => $taxonomy->getKey(),
        'code' => 'test-parent-'.Str::lower(Str::random(8)),
        'name' => 'Parent Test Category',
        'sort_order' => 998,
        'is_active' => true,
    ]);

    $child = EventTerm::query()->create([
        'event_taxonomy_id' => $taxonomy->getKey(),
        'parent_id' => $parent->getKey(),
        'code' => 'test-child-'.Str::lower(Str::random(8)),
        'name' => 'Child Test Category',
        'sort_order' => 999,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitInputValidationPayload($this->domainTag, $this->disciplineTag, [
            'event_category_ids' => [(string) $parent->getKey(), (string) $child->getKey()],
            'primary_organizer_id' => $person->getKey(),
            'persons' => [$person->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitInputValidationRequest(),
        submitter: $user,
    );

    $event = $result['event']->fresh();

    expect($event->getKey())->not->toBeNull();

    $storedCategoryIds = $event->classifications()
        ->where('taxonomy_code', 'event_category')
        ->pluck('event_term_id')
        ->map(static fn (mixed $id): string => (string) $id)
        ->all();

    expect($storedCategoryIds)->toBe([(string) $parent->getKey()]);
});
