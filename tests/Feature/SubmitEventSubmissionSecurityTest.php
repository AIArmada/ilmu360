<?php

use AIArmada\Events\Models\EventTerm;
use App\Actions\Events\SubmitFrontendEventAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Support\Submission\SubmissionTimingPolicy;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();

    $this->seed(EventRoleSeeder::class);

    $this->domainTag = submitEventTerm('domain');
    $this->disciplineTag = submitEventTerm('discipline');
});

/**
 * @return array<string, mixed>
 */
function submitSecurityPayload(EventTerm $domainTag, EventTerm $disciplineTag, array $overrides = []): array
{
    return array_merge([
        'title' => 'Security Submission',
        'description' => 'Security enforcement test.',
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
        'submitter_name' => 'Security Submitter',
        'submitter_email' => 'security@example.com',
    ], $overrides);
}

function submitSecurityRequest(): Request
{
    return Request::create('/hantar-majlis', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
}

it('rejects a forged scoped institution the submitter does not belong to', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    expect(fn () => app(SubmitFrontendEventAction::class)->handle(
        state: submitSecurityPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $person->getKey(),
            'persons' => [$person->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSecurityRequest(),
        submitter: $user,
        scopedInstitution: $institution,
    ))->toThrow(ValidationException::class, 'institusi ini');

    expect(Event::query()->where('title', 'Security Submission')->exists())->toBeFalse();
});

it('rejects a revoked membership on a subsequent submit request', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);

    $institution->members()->syncWithoutDetaching([$user->id]);

    $component = Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($user)
        ->test(Create::class)
        ->assertHasNoErrors();

    // Membership is revoked after the form was rendered.
    $institution->members()->detach($user->id);

    setSubmitEventFormState(
        $component,
        submitSecurityPayload($this->domainTag, $this->disciplineTag, [
            'location_same_as_institution' => true,
            'persons' => [$person->getKey()],
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.scoped_institution_id']);

    expect(Event::query()->where('title', 'Security Submission')->exists())->toBeFalse();
});

it('locks context identity props against client-side updates', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    expect(fn () => Livewire::actingAs($user)->test(Create::class)->set('scopedInstitutionId', $institution->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => Livewire::actingAs($user)->test(Create::class)->set('eventId', (string) Str::uuid()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('rejects session submissions to events the user cannot update', function () {
    $intruder = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create(['status' => 'approved']);

    $event->setPrimaryOrganizer($person);

    expect(fn () => app(SubmitFrontendEventAction::class)->handle(
        state: submitSecurityPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $person->getKey(),
            'persons' => [$person->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSecurityRequest(),
        submitter: $intruder,
        eventContainer: $event,
    ))->toThrow(ValidationException::class, 'mengubah majlis ini');

    $this->actingAs($intruder)
        ->get('/hantar-majlis?event='.$event->getKey())
        ->assertForbidden();
});

it('does not treat the location institution as ownership for scoped containers', function () {
    $user = User::factory()->create();
    $organizerInstitution = Institution::factory()->create(['status' => 'verified']);
    $locationInstitution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create([
        'status' => 'draft',
        'created_by_type' => $user->getMorphClass(),
        'created_by_id' => $user->getKey(),
        'institution_id' => $locationInstitution->getKey(),
    ]);

    $event->setPrimaryOrganizer($organizerInstitution);
    $locationInstitution->members()->syncWithoutDetaching([$user->id]);

    expect(fn () => app(SubmitFrontendEventAction::class)->handle(
        state: submitSecurityPayload($this->domainTag, $this->disciplineTag, [
            'location_same_as_institution' => true,
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSecurityRequest(),
        submitter: $user,
        eventContainer: $event,
        scopedInstitution: $locationInstitution,
    ))->toThrow(ValidationException::class, 'bukan di bawah institusi ini');

    $this->actingAs($user)
        ->get('/hantar-majlis?event='.$event->getKey().'&institution='.$locationInstitution->getKey())
        ->assertForbidden();
});

it('fails closed on invalid or deleted container ids', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/hantar-majlis?event=not-a-uuid')
        ->assertNotFound();

    $this->actingAs($user)
        ->get('/hantar-majlis?event='.(string) Str::uuid())
        ->assertNotFound();
});

it('throttles repeated submissions from the same client', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        setSubmitEventFormState(
            Livewire::actingAs($user)->test(Create::class),
            submitSecurityPayload($this->domainTag, $this->disciplineTag, [
                'title' => "Throttled Submission {$attempt}",
                'primary_organizer_id' => $person->getKey(),
                'persons' => [$person->getKey()],
                'location_type' => 'venue',
                'location_venue_id' => $venue->getKey(),
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            ]),
        )
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect();
    }

    $throttled = setSubmitEventFormState(
        Livewire::actingAs($user)->test(Create::class),
        submitSecurityPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Throttled Submission 6',
            'primary_organizer_id' => $person->getKey(),
            'persons' => [$person->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.captcha_token']);

    expect(implode(' ', (array) $throttled->errors()->get('data.captcha_token')))
        ->toContain('Terlalu banyak')
        ->and(Event::query()->where('title', 'Throttled Submission 6')->exists())->toBeFalse();
});

it('throttles AI extraction before running the extractor', function () {
    $user = User::factory()->create();

    for ($hit = 0; $hit < 5; $hit++) {
        RateLimiter::hit('submit-event:extract:127.0.0.1', 3600);
    }

    $throttled = Livewire::actingAs($user)->test(Create::class)
        ->call('extractEventFromMedia')
        ->assertHasErrors(['event_source_attachment']);

    expect(implode(' ', (array) $throttled->errors()->get('event_source_attachment')))
        ->toContain('Terlalu banyak');
});

it('rejects impossible dates and malformed times with prefixed errors instead of a 500', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    foreach (['2026-02-31', 'tomorrow', '12/31/2026', '2026-13-01'] as $eventDate) {
        try {
            app(SubmitFrontendEventAction::class)->handle(
                state: submitSecurityPayload($this->domainTag, $this->disciplineTag, [
                    'event_date' => $eventDate,
                    'primary_organizer_id' => $person->getKey(),
                    'persons' => [$person->getKey()],
                    'location_type' => 'venue',
                    'location_venue_id' => $venue->getKey(),
                    'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
                ]),
                request: submitSecurityRequest(),
                submitter: $user,
                validationKeyPrefix: 'data.',
            );

            $this->fail("Expected a validation error for event_date [{$eventDate}].");
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('data.event_date');
        }
    }

    try {
        app(SubmitFrontendEventAction::class)->handle(
            state: submitSecurityPayload($this->domainTag, $this->disciplineTag, [
                'prayer_time' => EventPrayerTime::LainWaktu->value,
                'custom_time' => '25:99',
                'primary_organizer_id' => $person->getKey(),
                'persons' => [$person->getKey()],
                'location_type' => 'venue',
                'location_venue_id' => $venue->getKey(),
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            ]),
            request: submitSecurityRequest(),
            submitter: $user,
            validationKeyPrefix: 'data.',
        );

        $this->fail('Expected a validation error for custom_time.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.custom_time');
    }

    expect(Event::query()->where('title', 'Security Submission')->exists())->toBeFalse();
});

it('validates space ownership before captcha with a prefixed key', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $otherVenue = Venue::factory()->create(['status' => 'verified']);
    $foreignSpace = Space::factory()->create(['venue_id' => $otherVenue->getKey()]);

    config()->set('services.turnstile.enabled', true);
    config()->set('services.turnstile.site_key', 'test-site');
    config()->set('services.turnstile.secret_key', 'test-secret');

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);

    try {
        app(SubmitFrontendEventAction::class)->handle(
            state: submitSecurityPayload($this->domainTag, $this->disciplineTag, [
                'primary_organizer_id' => $person->getKey(),
                'persons' => [$person->getKey()],
                'location_type' => 'venue',
                'location_venue_id' => $venue->getKey(),
                'space_ids' => [$foreignSpace->getKey()],
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            ]),
            request: submitSecurityRequest(),
            submitter: $user,
            validationKeyPrefix: 'data.',
        );

        $this->fail('Expected a validation error for space_ids.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.space_ids');
    }

    Http::assertNothingSent();
    expect(Event::query()->where('title', 'Security Submission')->exists())->toBeFalse();
});

it('rejects a venue that is no longer available for submission before captcha', function (string $status) {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => $status]);

    config()->set('services.turnstile.enabled', true);
    config()->set('services.turnstile.site_key', 'test-site');
    config()->set('services.turnstile.secret_key', 'test-secret');
    Http::fake();

    try {
        app(SubmitFrontendEventAction::class)->handle(
            state: submitSecurityPayload($this->domainTag, $this->disciplineTag, [
                'primary_organizer_id' => $person->getKey(),
                'persons' => [$person->getKey()],
                'location_type' => 'venue',
                'location_venue_id' => $venue->getKey(),
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
                'captcha_token' => 'unused-token',
            ]),
            request: submitSecurityRequest(),
            submitter: $user,
            validationKeyPrefix: 'data.',
        );

        $this->fail('Expected a validation error for an unavailable venue.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.location_venue_id');
    }

    Http::assertNothingSent();
    expect(Event::query()->where('title', 'Security Submission')->exists())->toBeFalse();
})->with(['rejected', 'archived']);

it('does not persist forged physical locations on an online submission', function (string $locationType) {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $institution = Institution::factory()->create(['status' => 'rejected', 'allow_public_event_submission' => false]);
    $venue = Venue::factory()->create(['status' => 'rejected']);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitSecurityPayload($this->domainTag, $this->disciplineTag, [
            'event_format' => EventFormat::Online->value,
            'primary_organizer_id' => $person->getKey(),
            'persons' => [$person->getKey()],
            'location_type' => $locationType,
            'location_institution_id' => $institution->getKey(),
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSecurityRequest(),
        submitter: $user,
    );

    expect($result['event']->institution_id)->toBeNull()
        ->and($result['event']->default_venue_id)->toBeNull();
})->with(['institution', 'venue']);

it('rejects physical spaces on an online submission before captcha', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $space = Space::factory()->create(['venue_id' => $venue->getKey()]);

    config()->set('services.turnstile.enabled', true);
    config()->set('services.turnstile.site_key', 'test-site');
    config()->set('services.turnstile.secret_key', 'test-secret');
    Http::fake();

    try {
        app(SubmitFrontendEventAction::class)->handle(
            state: submitSecurityPayload($this->domainTag, $this->disciplineTag, [
                'event_format' => EventFormat::Online->value,
                'primary_organizer_id' => $person->getKey(),
                'persons' => [$person->getKey()],
                'space_ids' => [$space->getKey()],
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
                'captcha_token' => 'unused-token',
            ]),
            request: submitSecurityRequest(),
            submitter: $user,
            validationKeyPrefix: 'data.',
        );

        $this->fail('Expected a validation error for physical spaces on an online submission.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.space_ids');
    }

    Http::assertNothingSent();
    expect(Event::query()->where('title', 'Security Submission')->exists())->toBeFalse();
});

it('requires a guest submitter name at the shared submission boundary', function (mixed $name) {
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    config()->set('services.turnstile.enabled', true);
    config()->set('services.turnstile.site_key', 'test-site');
    config()->set('services.turnstile.secret_key', 'test-secret');
    Http::fake();

    try {
        app(SubmitFrontendEventAction::class)->handle(
            state: submitSecurityPayload($this->domainTag, $this->disciplineTag, [
                'primary_organizer_id' => $person->getKey(),
                'persons' => [$person->getKey()],
                'location_type' => 'venue',
                'location_venue_id' => $venue->getKey(),
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
                'submitter_name' => $name,
                'captcha_token' => 'unused-token',
            ]),
            request: submitSecurityRequest(),
            validationKeyPrefix: 'data.',
        );

        $this->fail('Expected a validation error for a missing guest name.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.submitter_name');
    }

    Http::assertNothingSent();
    expect(Event::query()->where('title', 'Security Submission')->exists())->toBeFalse();
})->with([null, '', '   ']);

it('rejects a custom start time skipped by a daylight saving transition', function () {
    try {
        app(SubmissionTimingPolicy::class)->resolveStartsAt(
            '2027-03-28',
            EventPrayerTime::LainWaktu,
            '01:30',
            'Europe/London',
            'data.',
        );

        $this->fail('Expected a validation error for a nonexistent local start time.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.custom_time');
    }

    expect(app(SubmissionTimingPolicy::class)->resolveStartsAt(
        '2027-03-28',
        EventPrayerTime::LainWaktu,
        '02:30',
        'Europe/London',
    )->toIso8601String())->toBe('2027-03-28T01:30:00+00:00');
});

it('rejects an end time skipped by a daylight saving transition', function () {
    try {
        app(SubmissionTimingPolicy::class)->validateEndsAtAfterStartsAt(
            '01:30',
            Carbon::parse('2027-03-28 00:30', 'Europe/London')->utc(),
            'Europe/London',
            'data.',
        );

        $this->fail('Expected a validation error for a nonexistent local end time.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.end_time');
    }
});
