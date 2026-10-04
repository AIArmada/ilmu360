<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Events\Actions\CreateEventOccurrenceAction;
use AIArmada\Events\Models\EventAudience;
use AIArmada\Events\Models\EventAudienceProfile;
use AIArmada\Events\Models\EventInvolvement;
use AIArmada\Events\Models\EventLink;
use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Events\SubmitFrontendEventAction;
use App\Actions\Events\SyncEventClassificationsAction;
use App\Data\Prayer\PrayerTimesDTO;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Services\Prayer\JakimZoneResolver;
use App\Services\Prayer\PrayerTimesCache;
use Carbon\CarbonImmutable;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\MediaLibrary\HasMedia;

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
 * @return array<string, mixed>
 */
function submitSessionPayload(EventTerm $domainTag, EventTerm $disciplineTag, array $overrides = []): array
{
    return array_merge([
        'title' => 'Isolated Session Submission',
        'description' => 'Session isolation test.',
        'event_date' => now()->addDays(6)->format('Y-m-d'),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [languageId('ms')],
        'domain_tags' => [$domainTag->id],
        'discipline_tags' => [$disciplineTag->id],
        'submitter_name' => 'Session Submitter',
        'submitter_email' => 'session@example.com',
    ], $overrides);
}

function submitSessionRequest(): Request
{
    return Request::create('/hantar-majlis', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
}

function submitSessionContainer(User $owner, Person $organizer): Event
{
    $event = Event::factory()->create([
        'status' => 'approved',
        'created_by_type' => $owner->getMorphClass(),
        'created_by_id' => $owner->getKey(),
    ]);

    $event->setPrimaryOrganizer($organizer);

    addTestMember($organizer, $owner, MemberRole::Owner);

    return $event->fresh();
}

it('leaves the parent untouched while linking the session canonically', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $sessionSpeaker = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $event = submitSessionContainer($owner, $organizer);

    $parentReference = Reference::factory()->create(['status' => 'verified']);
    $event->references()->attach((string) $parentReference->getKey(), ['sort_order' => 0]);
    $event->syncLanguages([languageId('en')]);
    $parentDomain = submitEventTerm('domain');
    $parentDiscipline = submitEventTerm('discipline');
    app(SyncEventClassificationsAction::class)->handle($event->fresh(), [
        'event_category_ids' => [eventCategoryId('kelas_kursus')],
        'domain_tags' => [(string) $parentDomain->getKey()],
        'discipline_tags' => [(string) $parentDiscipline->getKey()],
    ]);
    $event = $event->fresh();

    $parentInvolvementIds = EventInvolvement::query()->where('event_id', $event->getKey())->pluck('id')->all();
    $parentOccurrenceId = (string) $event->primaryOccurrence->getKey();
    $parentLanguageCodes = $event->fresh()->languageRecords()->pluck('language_code')->all();
    $parentResolvedCodes = $event->fresh()->languages->pluck('code')->all();
    $parentClassificationTermIds = $event->fresh()->classifications()->pluck('event_term_id')->all();
    $parentCategoryTermIds = $event->fresh()->categoryClassifications()->pluck('event_term_id')->all();
    $parentReferenceIds = $event->fresh()->references()->pluck('references.id')->all();
    $parentEventReferenceIds = $event->fresh()->eventReferences()->pluck('id')->all();

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $organizer->getKey(),
            'persons' => [$sessionSpeaker->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );

    $session = $result['session'];
    $submission = $result['submission']->fresh();

    expect($session)->toBeInstanceOf(EventSession::class)
        ->and((string) $session->event_id)->toBe((string) $event->getKey())
        ->and((string) $session->event_occurrence_id)->toBe($parentOccurrenceId)
        ->and($submission->event_session_id)->toBe((string) $session->getKey())
        ->and((string) $submission->event_occurrence_id)->toBe($parentOccurrenceId)
        ->and($submission->target_type)->toBeNull()
        ->and($submission->target_id)->toBeNull();

    // Parent graph is byte-identical: organizer, people, status, media.
    expect(EventInvolvement::query()->where('event_id', $event->getKey())->whereNull('event_session_id')->pluck('id')->all())
        ->toEqualCanonicalizing($parentInvolvementIds)
        ->and((string) $event->fresh()->status)->toBe('approved')
        ->and($event->fresh()->media()->count())->toBe(0);

    // The session owns its own key people, languages, and classifications.
    expect(EventInvolvement::query()->where('event_session_id', $session->getKey())->count())->toBeGreaterThan(0)
        ->and($session->languages()->count())->toBeGreaterThan(0)
        ->and($session->classifications()->count())->toBeGreaterThan(0);

    $freshParent = $event->fresh();
    expect($freshParent->languageRecords()->pluck('language_code')->all())->toEqualCanonicalizing($parentLanguageCodes)
        ->and($freshParent->languages->pluck('code')->all())->toEqualCanonicalizing($parentResolvedCodes)
        ->and($freshParent->classifications()->pluck('event_term_id')->all())->toEqualCanonicalizing($parentClassificationTermIds)
        ->and($freshParent->categoryClassifications()->pluck('event_term_id')->all())->toEqualCanonicalizing($parentCategoryTermIds)
        ->and($freshParent->references()->pluck('references.id')->all())->toEqualCanonicalizing($parentReferenceIds)
        ->and($freshParent->eventReferences()->pluck('id')->all())->toEqualCanonicalizing($parentEventReferenceIds);

    $eagerParent = $event->fresh()->load(['languages', 'classifications', 'categoryClassifications', 'references', 'eventReferences']);
    expect($eagerParent->getRelation('languages')->pluck('language_code')->all())->toEqualCanonicalizing($parentLanguageCodes)
        ->and($eagerParent->languages->pluck('code')->all())->toEqualCanonicalizing($parentResolvedCodes)
        ->and($eagerParent->classifications->pluck('event_term_id')->all())->toEqualCanonicalizing($parentClassificationTermIds)
        ->and($eagerParent->categoryClassifications->pluck('event_term_id')->all())->toEqualCanonicalizing($parentCategoryTermIds)
        ->and($eagerParent->references->pluck('id')->all())->toEqualCanonicalizing($parentReferenceIds)
        ->and($eagerParent->eventReferences->pluck('id')->all())->toEqualCanonicalizing($parentEventReferenceIds);

    expect($session->languages()->pluck('language_code')->all())->toBe(['ms'])
        ->and($session->classifications()->pluck('event_term_id')->all())->toContain((string) $this->domainTag->getKey())
        ->and($session->classifications()->pluck('event_term_id')->all())->toContain((string) $this->disciplineTag->getKey());
});

it('persists session location audiences links and prayer timing within its scope', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $space = Space::factory()->create(['venue_id' => $venue->getKey()]);
    $event = submitSessionContainer($owner, $organizer);
    $before = [
        'locations' => $event->locations()->pluck('id')->all(),
        'audiences' => $event->audiences()->pluck('id')->all(),
        'audience_profiles' => $event->audienceProfiles()->pluck('id')->all(),
        'links' => $event->links()->pluck('id')->all(),
        'time_expressions' => $event->timeExpressions()->pluck('id')->all(),
    ];

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $organizer->getKey(),
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'space_ids' => [$space->getKey()],
            'gender' => EventGenderRestriction::WomenOnly->value,
            'age_group' => [EventAgeGroup::Adults->value],
            'children_allowed' => false,
            'is_muslim_only' => true,
            'event_url' => 'https://example.com/session',
            'live_url' => 'https://example.com/live',
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );

    $session = $result['session'];
    $location = $session->locations()->sole();
    $expression = $session->timeExpressions()->sole();
    expect((string) $location->venue_id)->toBe((string) $venue->getKey())
        ->and((string) $location->venue_space_id)->toBe((string) $space->getKey())
        ->and($location->space_name_snapshot)->toBe($space->name)
        ->and($session->audiences()->pluck('value', 'audience_type')->all())->toMatchArray([
            'gender' => 'women_only', 'age_group' => 'adults', 'religion' => 'muslim_only',
        ])
        ->and($session->audienceProfiles()->sole()->is_child_friendly)->toBeFalse()
        ->and($session->links()->pluck('url', 'link_type')->all())->toMatchArray([
            'external' => 'https://example.com/session', 'streaming' => 'https://example.com/live',
        ])
        ->and($expression->display_label)->toBe(EventPrayerTime::SelepasMaghrib->getLabel())
        ->and($expression->anchor_code)->toBe('maghrib');

    $parent = $event->fresh();
    expect([
        'locations' => $parent->locations()->pluck('id')->all(),
        'audiences' => $parent->audiences()->pluck('id')->all(),
        'audience_profiles' => $parent->audienceProfiles()->pluck('id')->all(),
        'links' => $parent->links()->pluck('id')->all(),
        'time_expressions' => $parent->timeExpressions()->pluck('id')->all(),
    ])->toBe($before);

    $parent->forceFill([
        'gender' => EventGenderRestriction::MenOnly->value,
        'age_group' => [EventAgeGroup::Youth->value],
        'children_allowed' => true,
        'is_muslim_only' => false,
        'event_url' => 'https://example.com/parent',
        'live_url' => null,
    ])->save();
    $parent->syncLocation($venue->getKey());

    expect($session->fresh()->audiences()->pluck('value', 'audience_type')->all())->toMatchArray([
        'gender' => 'women_only', 'age_group' => 'adults', 'religion' => 'muslim_only',
    ])
        ->and($session->fresh()->audienceProfiles()->sole()->is_child_friendly)->toBeFalse()
        ->and($session->fresh()->links()->pluck('url', 'link_type')->all())->toMatchArray([
            'external' => 'https://example.com/session', 'streaming' => 'https://example.com/live',
        ])
        ->and((string) $session->fresh()->locations()->sole()->venue_space_id)->toBe((string) $space->getKey());
});

it('deletes the submitted session graph with its event container', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = submitSessionContainer($owner, $organizer);
    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $organizer->getKey(),
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'event_url' => 'https://example.com/session',
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );
    $sessionId = $result['session']->getKey();

    $event->delete();

    expect(EventSession::query()->whereKey($sessionId)->exists())->toBeFalse()
        ->and(EventOccurrence::query()->where('event_id', $event->getKey())->exists())->toBeFalse();
    foreach ([EventLocation::class, EventAudience::class, EventAudienceProfile::class, EventLink::class, EventTimeExpression::class] as $model) {
        expect($model::query()->where('event_id', $event->getKey())->exists())->toBeFalse();
    }
});

it('removes failed session uploads while preserving the parent media', function () {
    Storage::fake('public');
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = submitSessionContainer($owner, $organizer);
    $parentMedia = $event->addMedia(UploadedFile::fake()->image('parent.png', 1600, 900))->toMediaCollection('cover', 'public');
    $parentPath = $parentMedia->getPathRelativeToRoot();
    $parentFiles = Storage::disk('public')->allFiles();

    expect(fn () => app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $organizer->getKey(),
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
        persistRelationships: function (HasMedia $model): void {
            $model->addMedia(UploadedFile::fake()->image('session.png', 1600, 900))->toMediaCollection('cover', 'public');
            throw new RuntimeException('Session upload failure.');
        },
    ))->toThrow(RuntimeException::class, 'Session upload failure.');

    expect(EventSession::query()->where('event_id', $event->getKey())->count())->toBe(0)
        ->and($event->fresh()->media()->count())->toBe(1)
        ->and(Storage::disk('public')->allFiles())->toBe($parentFiles);
    Storage::disk('public')->assertExists($parentPath);
});

it('attaches the session to the selected occurrence', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = submitSessionContainer($owner, $organizer);

    $second = app(CreateEventOccurrenceAction::class)->handle($event, [
        'title' => 'Second Date',
        'slug' => 'second-date-'.$event->getKey(),
        'starts_at' => now()->addDays(10),
        'ends_at' => now()->addDays(10)->addHours(2),
        'status' => 'scheduled',
        'visibility' => 'public',
    ]);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'event_occurrence_id' => (string) $second->getKey(),
            'primary_organizer_id' => $organizer->getKey(),
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );

    expect((string) $result['session']->event_occurrence_id)->toBe((string) $second->getKey())
        ->and((string) $result['submission']->fresh()->event_occurrence_id)->toBe((string) $second->getKey());
});

it('rejects foreign and terminal occurrences', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = submitSessionContainer($owner, $organizer);
    $otherEvent = Event::factory()->create(['status' => 'approved']);

    $foreignOccurrence = app(CreateEventOccurrenceAction::class)->handle($otherEvent, [
        'title' => 'Foreign Date',
        'slug' => 'foreign-date-'.$otherEvent->getKey(),
        'starts_at' => now()->addDays(10),
        'status' => 'scheduled',
        'visibility' => 'public',
    ]);

    $cancelledOccurrence = app(CreateEventOccurrenceAction::class)->handle($event, [
        'title' => 'Cancelled Date',
        'slug' => 'cancelled-date-'.$event->getKey(),
        'starts_at' => now()->addDays(11),
        'status' => 'scheduled',
        'visibility' => 'public',
    ]);
    $cancelledOccurrence->update(['status' => EventOccurrence::CANCELLED]);

    $base = [
        'primary_organizer_id' => $organizer->getKey(),
        'location_type' => 'venue',
        'location_venue_id' => $venue->getKey(),
        'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
    ];

    expect(fn () => app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, $base + ['event_occurrence_id' => (string) $foreignOccurrence->getKey()]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    ))->toThrow(ValidationException::class, 'bukan milik majlis ini');

    expect(fn () => app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, $base + ['event_occurrence_id' => (string) $cancelledOccurrence->getKey()]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    ))->toThrow(ValidationException::class, 'tidak lagi menerima sesi');

    expect(EventSession::query()->where('event_id', $event->getKey())->exists())->toBeFalse();
});

it('keeps no guest contacts for authenticated member session submits', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = submitSessionContainer($owner, $organizer);

    // A guest cannot pass the container update policy, so the member owner
    // submits while guest contact fields travel with the payload.
    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $organizer->getKey(),
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submitter_email' => 'guest-session@example.com',
            'submitter_phone' => '+60123456789',
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );

    $submission = $result['submission']->fresh();

    // Member submitters keep no guest contacts; the parent never gains
    // guest fields either way.
    $parentAttributes = array_keys($event->fresh()->getAttributes());

    expect($submission->contactMethods()->count())->toBe(0)
        ->and($parentAttributes)->not->toContain('submitter_email')
        ->and($parentAttributes)->not->toContain('submitter_phone')
        ->and($parentAttributes)->not->toContain('submitter_name');
});

it('uniquifies same-title session slugs within the event', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = submitSessionContainer($owner, $organizer);

    $base = [
        'title' => 'Repeated Session Title',
        'primary_organizer_id' => $organizer->getKey(),
        'location_type' => 'venue',
        'location_venue_id' => $venue->getKey(),
        'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
    ];

    $first = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, $base),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );

    $second = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, $base),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );

    expect((string) $first['session']->slug)->not->toBe((string) $second['session']->slug)
        ->and(EventSession::query()->where('event_id', $event->getKey())->count())->toBe(2);
});

it('syncs session references without touching parent references', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = submitSessionContainer($owner, $organizer);
    $reference = Reference::factory()->create(['status' => 'verified']);

    app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $organizer->getKey(),
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'references' => [$reference->getKey()],
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );

    $session = EventSession::query()->where('event_id', $event->getKey())->sole();
    $sessionReference = $session->references()->firstOrFail();

    expect($session->references()->count())->toBe(1)
        ->and((string) $sessionReference->referenceable_id)->toBe((string) $reference->getKey())
        ->and((string) $sessionReference->event_id)->toBe((string) $event->getKey())
        ->and((string) $sessionReference->event_session_id)->toBe((string) $session->getKey())
        ->and($sessionReference->event_occurrence_id)->not->toBeNull()
        ->and($event->fresh()->references()->count())->toBe(0)
        ->and($event->fresh()->eventReferences()->count())->toBe(0)
        ->and($event->fresh()->load('references')->references->count())->toBe(0)
        ->and($event->fresh()->load('eventReferences')->eventReferences->count())->toBe(0);
});

it('keeps session rows when parent references and languages resync', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = submitSessionContainer($owner, $organizer);

    $parentRef = Reference::factory()->create(['status' => 'verified']);
    $sessionRef = Reference::factory()->create(['status' => 'verified']);
    $parentRef2 = Reference::factory()->create(['status' => 'verified']);

    $event->references()->attach((string) $parentRef->getKey(), ['sort_order' => 0]);
    $event->syncLanguages([languageId('en')]);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $organizer->getKey(),
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'languages' => [languageId('ms')],
            'references' => [(string) $sessionRef->getKey()],
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );

    $session = $result['session']->fresh();
    $sessionLanguageIds = $session->languages()->pluck('id')->all();
    $sessionReferenceIds = $session->references()->pluck('id')->all();

    $freshParent = $event->fresh();
    $freshParent->references()->sync([(string) $parentRef2->getKey() => ['sort_order' => 0]]);
    $freshParent->syncLanguages([languageId('ar')]);

    expect($freshParent->fresh()->references()->pluck('references.id')->all())->toEqualCanonicalizing([(string) $parentRef2->getKey()])
        ->and($freshParent->fresh()->languageRecords()->pluck('language_code')->all())->toBe(['ar'])
        ->and($freshParent->fresh()->languages->pluck('code')->all())->toBe(['ar'])
        ->and($session->fresh()->languages()->pluck('id')->all())->toEqualCanonicalizing($sessionLanguageIds)
        ->and($session->fresh()->references()->pluck('id')->all())->toEqualCanonicalizing($sessionReferenceIds)
        ->and($session->fresh()->languages()->pluck('language_code')->all())->toBe(['ms'])
        ->and((string) $session->fresh()->references()->firstOrFail()->referenceable_id)->toBe((string) $sessionRef->getKey());
});

it('rejects cover uploads that are not 16:9', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);

    setSubmitEventFormState(
        Livewire::actingAs($user)->test(Create::class),
        submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $person->getKey(),
            'persons' => [$person->getKey()],
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            'cover' => UploadedFile::fake()->image('cover.png', 1200, 1600),
        ]),
    )
        ->call('submit')
        ->assertHasErrors(['data.cover']);

    expect(Event::query()->where('title', 'Isolated Session Submission')->exists())->toBeFalse();
});

it('requires an explicit occurrence selection for multi-date events before captcha', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = submitSessionContainer($owner, $organizer);

    app(CreateEventOccurrenceAction::class)->handle($event, [
        'title' => 'Second Date',
        'slug' => 'second-date-explicit-'.$event->getKey(),
        'starts_at' => now()->addDays(10),
        'ends_at' => now()->addDays(10)->addHours(2),
        'status' => 'scheduled',
        'visibility' => 'public',
    ]);

    config()->set('services.turnstile.enabled', true);
    config()->set('services.turnstile.site_key', 'test-site');
    config()->set('services.turnstile.secret_key', 'test-secret');

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);

    try {
        app(SubmitFrontendEventAction::class)->handle(
            state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
                'primary_organizer_id' => $organizer->getKey(),
                'location_type' => 'venue',
                'location_venue_id' => $venue->getKey(),
                'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            ]),
            request: submitSessionRequest(),
            submitter: $owner,
            eventContainer: $event,
            validationKeyPrefix: 'data.',
        );

        $this->fail('Expected a validation error for the missing occurrence selection.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.event_occurrence_id')
            ->and(implode(' ', (array) $exception->errors()['data.event_occurrence_id']))
            ->toContain('Sila pilih jadual majlis untuk sesi ini.');
    }

    Http::assertNothingSent();
    expect(EventSession::query()->where('event_id', $event->getKey())->exists())->toBeFalse();
});

it('refuses to default to a terminal occurrence when no selection is given', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = submitSessionContainer($owner, $organizer);

    $event->primaryOccurrence->update(['status' => EventOccurrence::CANCELLED]);

    expect(fn () => app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $organizer->getKey(),
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    ))->toThrow(ValidationException::class, 'tidak lagi menerima sesi baharu');

    expect(EventSession::query()->where('event_id', $event->getKey())->exists())->toBeFalse();
});

it('resolves the single eligible occurrence when the other dates are terminal', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = submitSessionContainer($owner, $organizer);

    $eligibleId = (string) $event->primaryOccurrence->getKey();

    $cancelled = app(CreateEventOccurrenceAction::class)->handle($event, [
        'title' => 'Cancelled Date',
        'slug' => 'cancelled-date-default-'.$event->getKey(),
        'starts_at' => now()->addDays(11),
        'status' => 'scheduled',
        'visibility' => 'public',
    ]);
    $cancelled->update(['status' => EventOccurrence::CANCELLED]);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $organizer->getKey(),
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );

    expect((string) $result['session']->event_occurrence_id)->toBe($eligibleId);
});

it('reflects the saved private session visibility without touching the parent', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = submitSessionContainer($owner, $organizer);

    $event->forceFill(['visibility' => EventVisibility::Public->value])->save();

    $parentStatus = (string) $event->fresh()->status;
    $parentRegistrationMode = $event->fresh()->registration_mode;

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'visibility' => EventVisibility::Private->value,
            'primary_organizer_id' => $organizer->getKey(),
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );

    expect($result['visibility'])->toBe(EventVisibility::Private->value)
        ->and($result['auto_approved'])->toBeFalse()
        ->and((string) $result['session']->fresh()->visibility)->toBe(EventVisibility::Private->value)
        ->and($event->fresh()->visibility)->toBe(EventVisibility::Public)
        ->and((string) $event->fresh()->status)->toBe($parentStatus)
        ->and($event->fresh()->registration_mode)->toBe($parentRegistrationMode);
});

it('keeps scoped session submissions honest with no parent auto-approval', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    $institution->members()->syncWithoutDetaching([$user->id]);

    $event = Event::factory()->create([
        'status' => 'draft',
        'created_by_type' => $user->getMorphClass(),
        'created_by_id' => $user->getKey(),
    ]);

    $event->setPrimaryOrganizer($institution);
    $event = $event->fresh();

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'location_same_as_institution' => true,
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $user,
        eventContainer: $event,
        scopedInstitution: $institution,
    );

    expect($result['auto_approved'])->toBeFalse()
        ->and($result['session'])->toBeInstanceOf(EventSession::class)
        ->and((string) $event->fresh()->status)->toBe('draft');
});

it('displays the submitted session institution and its own address', function () {
    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $location = Institution::factory()->create([
        'name' => 'Submitted Session Institution',
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $address = Address::query()->create([
        'country_id' => ensureTestMalaysiaCountry()->getKey(),
        'line1' => 'Distinct Submitted Session Address',
        'city' => 'Distinct Submitted Session City',
    ]);
    $location->addresses()->sync([$address->getKey() => ['is_primary' => true]]);
    $event = submitSessionContainer($owner, $organizer);
    $event->forceFill(['visibility' => EventVisibility::Public, 'published_at' => now()])->save();
    $event->primaryOccurrence->update(['status' => 'published', 'visibility' => 'public']);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'primary_organizer_id' => $organizer->getKey(),
            'location_type' => 'institution',
            'location_institution_id' => $location->getKey(),
            'submission_country_id' => ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );
    $session = $result['session'];

    $this->get(route('events.session', [$event->slug, $session->occurrence->slug, $session->slug]))
        ->assertSuccessful()
        ->assertSee($location->display_name)
        ->assertSee('Distinct Submitted Session City');
});

it('retains the original prayer date on midnight-rolled session submissions', function () {
    config(['prayer.enabled' => true]);

    $owner = User::factory()->create();
    $organizer = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $venue->primaryAddress()->update(['latitude' => 3.139, 'longitude' => 101.6869]);
    app(JakimZoneResolver::class)->rememberZone(3.139, 101.6869, 'WLY01');
    $event = submitSessionContainer($owner, $organizer);

    $eventDate = now()->addDays(6)->format('Y-m-d');
    $yearMonth = substr($eventDate, 0, 7);

    // Isha 23:58 KL + 5 rolls the start past midnight.
    $base = prayerCacheDto($eventDate);
    $times = $base->timesUtc;
    $times['isha'] = CarbonImmutable::parse($eventDate.' 15:58:00', 'UTC');
    app(PrayerTimesCache::class)->putMonthly('WLY01', $yearMonth, [$eventDate => new PrayerTimesDTO(
        timesUtc: $times,
        source: $base->source,
        fetchedAt: $base->fetchedAt,
        timezoneUsed: $base->timezoneUsed,
        date: $base->date,
        zoneOrCell: $base->zoneOrCell,
    )], 'MY');

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: submitSessionPayload($this->domainTag, $this->disciplineTag, [
            'event_date' => $eventDate,
            'prayer_time' => EventPrayerTime::SelepasIsyak->value,
            'primary_organizer_id' => $organizer->getKey(),
            'location_type' => 'venue',
            'location_venue_id' => $venue->getKey(),
            'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        ]),
        request: submitSessionRequest(),
        submitter: $owner,
        eventContainer: $event,
    );

    $session = $result['session'];
    $expectedStart = CarbonImmutable::parse($eventDate.' 00:00:00', 'Asia/Kuala_Lumpur')->addDay()->setTime(0, 3)->utc();

    expect($session->starts_at->toIso8601String())->toBe($expectedStart->toIso8601String());

    $expression = EventTimeExpression::query()
        ->where('event_session_id', $session->getKey())
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression?->metadata['prayer']['prayer_date'] ?? null)->toBe($eventDate);
});
