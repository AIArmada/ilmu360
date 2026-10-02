<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Actions\CreateEventSessionAction;
use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Models\EventInvolvement;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Signals\Models\SignalEvent;
use App\Actions\Events\SubmitFrontendEventAction;
use App\Contracts\ShareTrackingContract;
use App\Enums\DawahShareOutcomeType;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Models\Venue;
use App\Services\EventKeyPersonSyncService;
use App\Services\ModerationService;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\MediaLibrary\HasMedia;

beforeEach(function () {
    fakePrayerTimesApi();

    $this->seed(EventRoleSeeder::class);

    $this->domainTag = submitEventTerm('domain');
    $this->disciplineTag = submitEventTerm('discipline');
});

/**
 * @return array<string, mixed>
 */
function submitAtomicityPayload(EventTerm $domainTag, EventTerm $disciplineTag, array $overrides = []): array
{
    return array_merge([
        'title' => 'Atomicity Submission',
        'description' => 'Completion atomicity test.',
        'event_date' => now()->addDays(5)->format('Y-m-d'),
        'prayer_time' => EventPrayerTime::LainWaktu->value,
        'custom_time' => '20:00',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [languageId('ms')],
        'domain_tags' => [$domainTag->id],
        'discipline_tags' => [$disciplineTag->id],
        'submitter_name' => 'Atomicity Submitter',
        'submitter_email' => 'atomicity@example.com',
    ], $overrides);
}

function submitAtomicityRequest(): Request
{
    return Request::create('/hantar-majlis', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
}

it('persists guest contacts privately with NONE admission and a single UTC occurrence', function () {
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $eventDate = now()->addDays(5)->format('Y-m-d');

    setSubmitEventFormState(
        Livewire::test(Create::class),
        submitAtomicityPayload($this->domainTag, $this->disciplineTag, [
            'event_date' => $eventDate,
            'primary_organizer_id' => $person->getKey(),
            'persons' => [$person->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submitter_phone' => '+60123456789',
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $event = Event::query()->where('title', 'Atomicity Submission')->sole();
    $submission = EventSubmission::query()->where('event_id', $event->getKey())->sole();
    $contacts = $submission->contactMethods()->orderBy('sort_order')->get();

    expect((string) $event->status)->toBe('pending')
        ->and($event->registration_mode)->toBe(RegistrationMode::None)
        ->and($event->accessPolicy->registration_required)->toBeFalse()
        ->and($event->accessPolicy->walk_in_allowed)->toBeTrue()
        ->and($contacts->pluck('value')->all())->toEqualCanonicalizing(['atomicity@example.com', '+60123456789'])
        ->and($contacts->every(fn ($contact): bool => $contact->is_public === false))->toBeTrue()
        ->and($submission->target_type)->toBeNull()
        ->and($submission->target_id)->toBeNull()
        ->and($submission->event_session_id)->toBeNull();

    expect(SignalEvent::query()->where('event_name', 'moderation.event.submitted')->where('properties->event_id', $event->getKey())->exists())->toBeTrue();

    $occurrences = EventOccurrence::query()->where('event_id', $event->getKey())->get();

    // 20:00 Asia/Kuala_Lumpur is 12:00 UTC on the same calendar day.
    expect($occurrences)->toHaveCount(1)
        ->and($occurrences->sole()->ends_at)->toBeNull()
        ->and($occurrences->sole()->starts_at->setTimezone('UTC')->format('Y-m-d H:i'))->toBe($eventDate.' 12:00');
});

it('does not poison a valid submission when share tracking fails', function () {
    Log::spy();

    $tracker = $this->mock(ShareTrackingContract::class);
    $tracker->shouldReceive('recordOutcome')->once()->andThrow(new RuntimeException('tracking down'));

    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    setSubmitEventFormState(
        Livewire::actingAs($user)->test(Create::class),
        submitAtomicityPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Tracking Failure Submission',
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

    expect(Event::query()->where('title', 'Tracking Failure Submission')->exists())->toBeTrue();

    Log::shouldHaveReceived('warning')->with('Share outcome tracking failed for an event submission.', Mockery::type('array'))->once();
});

it('records the share outcome once a submission commits', function () {
    $tracker = $this->mock(ShareTrackingContract::class);
    $tracker->shouldReceive('recordOutcome')->once()->with(
        DawahShareOutcomeType::EventSubmission,
        Mockery::pattern('/^event_submission:submission:/'),
        Mockery::type(Event::class),
        Mockery::type(User::class),
        Mockery::type(Request::class),
        Mockery::on(fn (array $metadata): bool => isset($metadata['submission_id'])),
    );

    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    setSubmitEventFormState(
        Livewire::actingAs($user)->test(Create::class),
        submitAtomicityPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Tracked Submission',
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

    expect(Event::query()->where('title', 'Tracked Submission')->exists())->toBeTrue();
});

it('rolls the graph back and suppresses after-commit effects when completion fails', function () {
    Storage::fake('public');
    $moderation = $this->mock(ModerationService::class);
    $moderation->shouldReceive('approve')->once()->andThrow(new RuntimeException('moderation down'));

    $tracker = $this->mock(ShareTrackingContract::class);
    $tracker->shouldNotReceive('recordOutcome');

    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);

    $institution->members()->syncWithoutDetaching([$user->id]);

    $component = setSubmitEventFormState(
        Livewire::withQueryParams(['institution' => $institution->id])->actingAs($user)->test(Create::class),
        submitAtomicityPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Rolled Back Submission',
            'location_same_as_institution' => true,
            'persons' => [$person->getKey()],
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            'cover' => UploadedFile::fake()->image('cover.png', 1600, 900),
        ]),
    );

    expect(fn () => $component->call('submit'))->toThrow(RuntimeException::class, 'moderation down');

    expect(Event::query()->where('title', 'Rolled Back Submission')->exists())->toBeFalse()
        ->and(EventSubmission::query()->count())->toBe(0)
        ->and(EventOccurrence::query()->count())->toBe(0);

    Notification::assertNothingSent();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('removes partial uploads when a submission media callback fails', function () {
    Storage::fake('public');
    $organizer = Institution::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $tracker = $this->mock(ShareTrackingContract::class);
    $tracker->shouldNotReceive('recordOutcome');

    expect(fn () => app(SubmitFrontendEventAction::class)->handle(
        state: submitAtomicityPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $organizer->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitAtomicityRequest(),
        persistRelationships: function (HasMedia $model): void {
            $model->addMedia(UploadedFile::fake()->image('cover.png', 1600, 900))->toMediaCollection('cover', 'public');
            throw new RuntimeException('Partial upload failure.');
        },
    ))->toThrow(RuntimeException::class, 'Partial upload failure.');

    expect(Event::query()->where('title', 'Atomicity Submission')->exists())->toBeFalse()
        ->and(EventSubmission::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects unavailable references and unknown languages before persistence', function (string $field, string $invalidKind) {
    $organizer = Institution::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $id = $invalidKind === 'rejected'
        ? Reference::factory()->create(['status' => 'rejected'])->getKey()
        : (string) Str::uuid();

    try {
        app(SubmitFrontendEventAction::class)->handle(
            state: submitAtomicityPayload($this->domainTag, $this->disciplineTag, [
                'primary_organizer_id' => $organizer->getKey(),
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
                $field => [$id],
            ]),
            request: submitAtomicityRequest(),
            validationKeyPrefix: 'data.',
        );
        $this->fail('Expected invalid catalog selection to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.'.$field);
    }

    expect(Event::query()->where('title', 'Atomicity Submission')->exists())->toBeFalse()
        ->and(EventSubmission::query()->count())->toBe(0);
})->with([
    'missing reference' => ['references', 'missing'],
    'rejected reference' => ['references', 'rejected'],
    'missing language' => ['languages', 'missing'],
]);

it('does not let an ordinary member auto-approve verify pending related records', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $pendingPerson = Person::factory()->create(['status' => 'pending', 'allow_public_event_submission' => true]);
    $pendingLocation = Venue::factory()->create(['status' => 'pending']);

    syncPrimaryAddressForTest($pendingLocation, ['country_id' => (string) ensureTestMalaysiaCountry()->getKey()]);
    $pendingLocation = $pendingLocation->refresh();

    $institution->members()->syncWithoutDetaching([$user->id]);

    // The member can see the pending person through membership of the
    // submitting institution? No: pending entities stay untouched.
    setSubmitEventFormState(
        Livewire::withQueryParams(['institution' => $institution->id])->actingAs($user)->test(Create::class),
        submitAtomicityPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Member Auto Approve Submission',
            'location_same_as_institution' => false,
            'location_type' => 'venue',
            'location_venue_id' => $pendingLocation->getKey(),
            'persons' => [$pendingPerson->getKey()],
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $event = Event::query()->where('title', 'Member Auto Approve Submission')->sole();

    expect((string) $event->status)->toBe('approved')
        ->and($pendingPerson->fresh()->status)->toBe('pending')
        ->and($pendingLocation->fresh()->status)->toBe('pending');
});

it('still verifies pending related records for moderator approvals', function () {
    $this->seed(RoleSeeder::class);

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $event = Event::factory()->create(['status' => 'pending']);
    $pendingPerson = Person::factory()->create(['status' => 'pending']);

    EventKeyPerson::query()->create([
        'event_id' => $event->getKey(),
        'involveable_type' => 'person',
        'involveable_id' => $pendingPerson->getKey(),
        'role_code' => 'speaker',
        'visibility' => 'public',
        'status' => 'active',
    ]);

    OwnerContext::withOwner(null, fn () => app(ModerationService::class)->approve($event, $moderator));

    expect($pendingPerson->fresh()->status)->toBe('verified');
});

it('deduplicates repeated persons instead of creating duplicate involvements', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    app(SubmitFrontendEventAction::class)->handle(
        state: submitAtomicityPayload($this->domainTag, $this->disciplineTag, [
            'title' => 'Deduped Persons Submission',
            'primary_organizer_id' => $person->getKey(),
            'persons' => [$person->getKey(), $person->getKey(), $person->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitAtomicityRequest(),
        submitter: $user,
    );

    $event = Event::query()->where('title', 'Deduped Persons Submission')->sole();

    expect(EventKeyPerson::query()->where('event_id', $event->getKey())->where('role_code', 'speaker')->whereNull('event_session_id')->count())
        ->toBe(1);
});

it('preserves private visibility and other scopes when syncing event key people', function () {
    $event = Event::factory()->create(['status' => 'approved']);
    $speaker = Person::factory()->create(['status' => 'verified']);
    $moderatorPerson = Person::factory()->create(['status' => 'verified']);
    $occurrence = $event->primaryOccurrence;

    app(EventKeyPersonSyncService::class)->sync($event, [$speaker->getKey()], [
        [
            'role_code' => 'moderator',
            'involveable_type' => 'person',
            'involveable_id' => $moderatorPerson->getKey(),
            'display_name' => null,
            'visibility' => 'private',
            'notes' => null,
        ],
    ]);

    expect(EventKeyPerson::query()->where('event_id', $event->getKey())->where('role_code', 'moderator')->value('visibility'))
        ->toBe('private');

    $session = app(CreateEventSessionAction::class)->handle($occurrence, [
        'title' => 'Scoped Session',
        'slug' => 'scoped-session-'.$event->getKey(),
        'starts_at' => now()->addDays(3),
        'status' => 'scheduled',
        'visibility' => 'public',
    ]);

    $sessionSpeaker = Person::factory()->create(['status' => 'verified']);

    app(EventKeyPersonSyncService::class)->syncSession($session, [$sessionSpeaker->getKey()]);

    expect(EventInvolvement::query()->where('event_session_id', $session->getKey())->count())->toBe(1);

    // Re-syncing the event scope must not touch the session scope.
    $replacement = Person::factory()->create(['status' => 'verified']);

    app(EventKeyPersonSyncService::class)->sync($event, [$replacement->getKey()]);

    expect(EventInvolvement::query()->where('event_session_id', $session->getKey())->count())->toBe(1)
        ->and((string) EventInvolvement::query()->where('event_session_id', $session->getKey())->value('involveable_id'))
        ->toBe((string) $sessionSpeaker->getKey())
        ->and(EventKeyPerson::query()->where('event_id', $event->getKey())->whereNull('event_session_id')->count())->toBe(1);
});

it('keeps organizer and person authorization queries bounded with many persons', function () {
    $user = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $persons = Person::factory()->count(12)->create(['status' => 'verified', 'allow_public_event_submission' => true]);

    $domainTag = $this->domainTag;
    $disciplineTag = $this->disciplineTag;

    $countScopeSelects = function (array $personIds, string $title) use ($user, $organizer, $venue, $domainTag, $disciplineTag): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(SubmitFrontendEventAction::class)->handle(
            state: submitAtomicityPayload($domainTag, $disciplineTag, [
                'title' => $title,
                'primary_organizer_id' => $organizer->getKey(),
                'persons' => $personIds,
                'location_type' => 'venue',
                'location_venue_id' => $venue->getKey(),
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            ]),
            request: submitAtomicityRequest(),
            submitter: $user,
        );

        $queries = collect(DB::getQueryLog())
            ->map(fn (array $entry): string => strtolower((string) ($entry['query'] ?? '')))
            ->filter(fn (string $sql): bool => str_starts_with(ltrim($sql), 'select')
                && (str_contains($sql, '"persons"') || str_contains($sql, '"institutions"')))
            ->values();

        DB::disableQueryLog();

        return $queries->count();
    };

    $few = $countScopeSelects(
        $persons->take(2)->map(fn (Person $person): string => (string) $person->getKey())->all(),
        'Bounded Few Persons',
    );
    $many = $countScopeSelects(
        $persons->map(fn (Person $person): string => (string) $person->getKey())->all(),
        'Bounded Many Persons',
    );

    expect($many)->toBe($few);
});
