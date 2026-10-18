<?php

use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventAudience;
use AIArmada\Events\Models\EventAudienceProfile;
use AIArmada\Events\Models\EventClassification;
use AIArmada\Events\Models\EventInvolvement;
use AIArmada\Events\Models\EventLink;
use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventReference;
use AIArmada\Events\Models\EventSubmissionLog;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\Signals\Models\SignalEvent;
use App\Actions\Events\ModerateEventOccurrenceSubmissionAction;
use App\Actions\Events\SubmitFrontendEventAction;
use App\Actions\Events\SyncEventClassificationsAction;
use App\Data\Api\Event\EventPayloadData;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Services\PublicScheduleDiscoveryService;
use App\Support\Events\PublicScheduleSlug;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;

beforeEach(function (): void {
    fakePrayerTimesApi();
    $this->seed(EventRoleSeeder::class);
    eventCategoryId('kuliah_ceramah');
    $this->domainTag = submitEventTerm('domain');
    $this->disciplineTag = submitEventTerm('discipline');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function publicOccurrenceGroupingPayload(Institution $institution, array $overrides = []): array
{
    $title = (string) ($overrides['title'] ?? 'Tajuk Sumbangan');

    return array_merge([
        'title' => $title,
        'description' => 'Huraian untuk '.$title,
        'event_date' => now()->addDays(8)->toDateString(),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::WomenOnly->value,
        'age_group' => [EventAgeGroup::Adults->value],
        'children_allowed' => false,
        'is_muslim_only' => true,
        'languages' => [languageId('ms')],
        'domain_tags' => [],
        'discipline_tags' => [],
        'persons' => [],
        'primary_organizer_id' => $institution->getKey(),
        'location_type' => 'institution',
        'location_institution_id' => $institution->getKey(),
        'location_same_as_institution' => true,
        'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'event_url' => 'https://example.com/event',
        'live_url' => 'https://live.example.com/event',
        'submitter_name' => 'Public Contributor',
        'submitter_email' => 'public@example.com',
    ], $overrides);
}

function publicOccurrenceGroupingRequest(): Request
{
    return Request::create('/hantar-majlis', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
}

function publicOccurrenceGroupingInstitution(string $name = 'Masjid Shah Alam'): Institution
{
    return Institution::factory()->create([
        'name' => $name,
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
}

it('creates a date-free institutional parent and preserves every contribution on its own occurrence', function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = publicOccurrenceGroupingInstitution();
    $speaker = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $reference = Reference::factory()->create(['status' => 'verified']);
    $domainTag = $this->domainTag;
    $disciplineTag = $this->disciplineTag;
    $date = now()->addDays(8)->toDateString();
    $mediaSubject = null;

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Fiqh of Neighbourhoods',
            'event_date' => $date,
            'persons' => [$speaker->getKey()],
            'references' => [$reference->getKey()],
            'domain_tags' => [$domainTag->getKey()],
            'discipline_tags' => [$disciplineTag->getKey()],
        ]),
        request: publicOccurrenceGroupingRequest(),
        persistRelationships: function (HasMedia $subject) use (&$mediaSubject): void {
            $mediaSubject = $subject;
            $subject->addMedia(UploadedFile::fake()->image('occurrence-cover.png', 1600, 900))
                ->toMediaCollection('cover', 'public');
        },
    );

    $event = $result['event'];
    $occurrence = $result['occurrence'];
    $submission = $result['submission']->fresh();
    $expression = EventTimeExpression::query()->where('event_occurrence_id', $occurrence->getKey())->sole();

    expect($result['grouped'])->toBeTrue()
        ->and($result['parent_created'])->toBeTrue()
        ->and($event->title)->toContain($institution->name)
        ->and($event->title)->toContain(EventPrayerTime::SelepasMaghrib->getLabel())
        ->and($event->title)->not->toContain('Fiqh of Neighbourhoods')
        ->and($event->description)->toBeNull()
        ->and($event->summary)->toBeNull()
        ->and($event->default_venue_id)->toBeNull()
        ->and($event->event_url)->toBeNull()
        ->and($event->live_url)->toBeNull()
        ->and($event->timeExpressions()->count())->toBe(0)
        ->and($event->locations()->count())->toBe(0)
        ->and($event->audiences()->count())->toBe(0)
        ->and($event->links()->count())->toBe(0)
        ->and($submission->event_occurrence_id)->toBe((string) $occurrence->getKey())
        ->and($submission->event_session_id)->toBeNull()
        ->and($occurrence->title)->toBe('Fiqh of Neighbourhoods')
        ->and($occurrence->metadata['description_localized'])->toMatchArray(['ms' => 'Huraian untuk Fiqh of Neighbourhoods'])
        ->and($expression->anchor_code)->toBe('maghrib')
        ->and($expression->metadata['submission_group']['prayer_slot'])->toBe(EventPrayerTime::SelepasMaghrib->value)
        ->and(EventLocation::query()->where('event_occurrence_id', $occurrence->getKey())->sole()->locationable_id)->toBe((string) $institution->getKey())
        ->and(EventAudience::query()->where('event_occurrence_id', $occurrence->getKey())->where('audience_type', 'gender')->value('value'))->toBe(EventGenderRestriction::WomenOnly->value)
        ->and(EventAudienceProfile::query()->where('event_occurrence_id', $occurrence->getKey())->sole()->is_child_friendly)->toBeFalse()
        ->and(EventLink::query()->where('event_occurrence_id', $occurrence->getKey())->count())->toBe(2)
        ->and(EventClassification::query()->where('event_occurrence_id', $occurrence->getKey())->whereIn('event_term_id', [$domainTag->getKey(), $disciplineTag->getKey()])->count())->toBe(2)
        ->and($occurrence->languages()->pluck('language_code')->all())->toBe(['ms'])
        ->and(EventInvolvement::query()->where('event_occurrence_id', $occurrence->getKey())->where('involveable_id', $speaker->getKey())->exists())->toBeTrue()
        ->and(EventReference::query()->where('event_occurrence_id', $occurrence->getKey())->where('referenceable_id', $reference->getKey())->exists())->toBeTrue()
        ->and($mediaSubject)->toBeInstanceOf(EventOccurrence::class)
        ->and($occurrence->getMedia('cover'))->toHaveCount(1)
        ->and($event->getMedia('cover'))->toHaveCount(0)
        ->and($event->card_image_url)->not->toBe(asset('images/placeholders/event-wide-v1.jpg'))
        ->and($occurrence->timezone)->toBe('Asia/Kuala_Lumpur')
        ->and($occurrence->starts_at->setTimezone('Asia/Kuala_Lumpur')->toDateString())->toBe($date);
});

it('reuses the institution type and prayer slot while keeping old occurrence content unchanged', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $firstSpeaker = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $secondSpeaker = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $firstDate = now()->addDays(8)->toDateString();
    $secondDate = now()->addDays(15)->toDateString();

    $first = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Tajuk Pertama',
            'event_date' => $firstDate,
            'persons' => [$firstSpeaker->getKey()],
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    $firstOccurrenceId = (string) $first['occurrence']->getKey();

    $second = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Tajuk Kedua yang Berbeza',
            'description' => 'Huraian kedua kekal berasingan.',
            'event_date' => $secondDate,
            'persons' => [$secondSpeaker->getKey()],
            'domain_tags' => [$this->domainTag->getKey()],
        ]),
        request: publicOccurrenceGroupingRequest(),
    );

    $event = $first['event']->fresh();
    $firstOccurrence = EventOccurrence::query()->findOrFail($firstOccurrenceId);
    $secondOccurrence = $second['occurrence']->fresh();

    expect($second['event']->getKey())->toBe($first['event']->getKey())
        ->and($second['parent_created'])->toBeFalse()
        ->and($second['grouped'])->toBeTrue()
        ->and($second['occurrence']->getKey())->not->toBe($firstOccurrenceId)
        ->and($event->occurrences()->count())->toBe(2)
        ->and($event->schedule_kind)->toBe(ScheduleKind::CustomChain)
        ->and($event->title)->not->toContain('Tajuk Pertama')
        ->and($firstOccurrence->title)->toBe('Tajuk Pertama')
        ->and($firstOccurrence->metadata['description_localized']['ms'])->toBe('Huraian untuk Tajuk Pertama')
        ->and($secondOccurrence->title)->toBe('Tajuk Kedua yang Berbeza')
        ->and($secondOccurrence->metadata['description_localized']['ms'])->toBe('Huraian kedua kekal berasingan.')
        ->and(EventInvolvement::query()->where('event_occurrence_id', $firstOccurrenceId)->where('involveable_id', $firstSpeaker->getKey())->exists())->toBeTrue()
        ->and(EventInvolvement::query()->where('event_occurrence_id', $secondOccurrence->getKey())->where('involveable_id', $secondSpeaker->getKey())->exists())->toBeTrue()
        ->and(EventSubmission::query()->where('event_id', $event->getKey())->count())->toBe(2)
        ->and(EventTimeExpression::query()->where('event_id', $event->getKey())->whereNull('event_occurrence_id')->count())->toBe(0);
});

it('assigns unique occurrence slugs when identical contributions share a parent and date', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $speaker = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $payload = publicOccurrenceGroupingPayload($institution, [
        'title' => 'Identical Topic',
        'event_date' => now()->addDays(8)->toDateString(),
        'persons' => [$speaker->getKey()],
    ]);

    $first = app(SubmitFrontendEventAction::class)->handle(
        state: $payload,
        request: publicOccurrenceGroupingRequest(),
    );
    $second = app(SubmitFrontendEventAction::class)->handle(
        state: $payload,
        request: publicOccurrenceGroupingRequest(),
    );

    $firstSlug = (string) $first['occurrence']->slug;
    $secondSlug = (string) $second['occurrence']->slug;
    $discovery = app(PublicScheduleDiscoveryService::class);

    expect($second['event']->getKey())->toBe($first['event']->getKey())
        ->and($second['occurrence']->getKey())->not->toBe($first['occurrence']->getKey())
        ->and($firstSlug)->not->toBe($secondSlug)
        ->and($discovery->findOccurrence($first['event'], $firstSlug)?->getKey())->toBe($first['occurrence']->getKey())
        ->and($discovery->findOccurrence($second['event'], $secondSlug)?->getKey())->toBe($second['occurrence']->getKey());
});

it('uses the nearest eligible future occurrence as the parent date without per-event lookups', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $firstDate = now()->addDays(8)->toDateString();
    $secondDate = now()->addDays(15)->toDateString();

    $first = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Past Group Date',
            'event_date' => $firstDate,
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    $second = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Next Group Date',
            'event_date' => $secondDate,
        ]),
        request: publicOccurrenceGroupingRequest(),
    );

    DB::table('event_occurrences')->where('id', $first['occurrence']->getKey())->update([
        'starts_at' => now()->subDay(),
        'status' => 'completed',
    ]);
    $loadedEvent = Event::query()
        ->with(['primaryOccurrence', 'displayOccurrence'])
        ->findOrFail($first['event']->getKey());
    $occurrenceQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$occurrenceQueries): void {
        if (str_contains($query->sql, 'event_occurrences')) {
            $occurrenceQueries[] = $query->sql;
        }
    });

    expect($loadedEvent->displayOccurrence?->getKey())->toBe($second['occurrence']->getKey())
        ->and($loadedEvent->starts_at?->toDateString())->toBe($second['occurrence']->starts_at->toDateString())
        ->and($loadedEvent->ends_at?->toDateString())->toBe($second['occurrence']->ends_at?->toDateString())
        ->and($occurrenceQueries)->toBeEmpty();
});

it('uses the nearest upcoming group occurrence in event listings and API date filters and sorting', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $first = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Completed Earlier Occurrence',
            'event_date' => now()->addDays(8)->toDateString(),
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    DB::table('events')->where('id', $first['event']->getKey())->update([
        'status' => 'approved',
        'published_at' => now(),
        'visibility' => EventVisibility::Public->value,
    ]);

    $nextStartDate = now()->addDays(16)->toDateString();
    $second = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Next Upcoming Occurrence',
            'event_date' => $nextStartDate,
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    DB::table('event_occurrences')->where('id', $first['occurrence']->getKey())->update([
        'starts_at' => now()->subDay(),
        'status' => 'completed',
    ]);
    DB::table('event_occurrences')->where('id', $second['occurrence']->getKey())->update([
        'status' => 'published',
        'visibility' => EventVisibility::Public->value,
    ]);

    $laterEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'starts_at' => now()->addDays(24),
    ]);
    $minimumStart = now()->addDays(15);

    $listedEvents = Event::query()
        ->where('status', 'approved')
        ->where('visibility', EventVisibility::Public->value)
        ->where('starts_at', '>=', $minimumStart)
        ->orderBy('starts_at')
        ->get();

    $response = $this->getJson('/api/v1/events?filter[starts_after]='.now()->addDays(15)->toDateString().'&sort=starts_at')
        ->assertOk();
    $apiEventIds = collect($response->json('data'))->pluck('id')->map(strval(...))->all();

    expect($second['event']->getKey())->toBe($first['event']->getKey())
        ->and($first['event']->fresh()->starts_at?->toDateString())->toBe($nextStartDate)
        ->and($listedEvents->pluck('id')->map(strval(...))->all())->toBe([
            (string) $first['event']->getKey(),
            (string) $laterEvent->getKey(),
        ])
        ->and($apiEventIds)->toBe([
            (string) $first['event']->getKey(),
            (string) $laterEvent->getKey(),
        ]);
});

it('matches the canonical parent identity and keeps each prayer expression on its occurrence', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $first = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Legacy Topic',
            'event_date' => now()->addDays(8)->toDateString(),
        ]),
        request: publicOccurrenceGroupingRequest(),
    );

    $event = $first['event'];
    $occurrence = $first['occurrence'];
    $second = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'New Canonical Matched Topic',
            'event_date' => now()->addDays(16)->toDateString(),
        ]),
        request: publicOccurrenceGroupingRequest(),
    );

    expect($second['event']->getKey())->toBe($event->getKey())
        ->and($second['parent_created'])->toBeFalse()
        ->and($event->fresh()->occurrences()->count())->toBe(2)
        ->and($occurrence->fresh()->title)->toBe('Legacy Topic')
        ->and(EventTimeExpression::query()->where('event_occurrence_id', $occurrence->getKey())->sole()->anchor_code)->toBe('maghrib')
        ->and(EventTimeExpression::query()->where('event_occurrence_id', $second['occurrence']->getKey())->sole()->anchor_code)->toBe('maghrib')
        ->and(EventTimeExpression::query()->where('event_id', $event->getKey())->whereNull('event_occurrence_id')->count())->toBe(0);
});

it('does not reuse a private or terminal parent candidate', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $basePayload = fn (string $title): array => publicOccurrenceGroupingPayload($institution, [
        'title' => $title,
        'event_date' => now()->addDays(8)->toDateString(),
    ]);

    $private = app(SubmitFrontendEventAction::class)->handle(
        state: $basePayload('Private Parent'),
        request: publicOccurrenceGroupingRequest(),
    );
    DB::table('events')->where('id', $private['event']->getKey())->update([
        'status' => 'approved',
        'visibility' => 'private',
    ]);

    $terminal = app(SubmitFrontendEventAction::class)->handle(
        state: $basePayload('Public Parent'),
        request: publicOccurrenceGroupingRequest(),
    );
    DB::table('events')->where('id', $terminal['event']->getKey())->update(['status' => 'cancelled']);

    $fresh = app(SubmitFrontendEventAction::class)->handle(
        state: $basePayload('Fresh Parent'),
        request: publicOccurrenceGroupingRequest(),
    );

    expect($terminal['event']->getKey())->not->toBe($private['event']->getKey())
        ->and($fresh['event']->getKey())->not->toBe($private['event']->getKey())
        ->and($fresh['event']->getKey())->not->toBe($terminal['event']->getKey());
});

it('matches an existing ordinary event from its canonical institution category and prayer expression', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $existing = Event::factory()->create([
        'title' => 'Existing Kuliah Topic',
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'published_at' => now(),
        'visibility' => EventVisibility::Public->value,
        'starts_at' => now()->addDays(8),
    ]);
    app(SyncEventClassificationsAction::class)->handle($existing, [
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
    ]);
    EventTimeExpression::query()->where('event_id', $existing->getKey())->delete();
    EventTimeExpression::query()->create([
        'event_id' => $existing->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'time_mode' => 'prayer_relative',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 5,
        'display_label' => EventPrayerTime::SelepasMaghrib->getLabel(),
    ]);
    $originalOccurrenceId = (string) $existing->primaryOccurrence()->value('id');

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'A Different Contribution Topic',
            'event_date' => now()->addDays(16)->toDateString(),
        ]),
        request: publicOccurrenceGroupingRequest(),
    );

    expect($result['event']->getKey())->toBe($existing->getKey())
        ->and($result['parent_created'])->toBeFalse()
        ->and($result['event']->metadata['public_submission_group']['prayer_slot'])->toBe(EventPrayerTime::SelepasMaghrib->value)
        ->and($result['event']->occurrences()->count())->toBe(2)
        ->and((string) $result['event']->primaryOccurrence()->value('id'))->toBe($originalOccurrenceId)
        ->and($result['occurrence']->title)->toBe('A Different Contribution Topic')
        ->and(EventSubmission::query()->where('event_occurrence_id', $result['occurrence']->getKey())->exists())->toBeTrue();
});

it('does not adopt an existing parent with multiple canonical prayer slots', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $existing = Event::factory()->create([
        'title' => 'Mixed Prayer Programme',
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'published_at' => now(),
        'visibility' => EventVisibility::Public->value,
        'starts_at' => now()->addDays(8),
    ]);
    app(SyncEventClassificationsAction::class)->handle($existing, [
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
    ]);

    $occurrence = $existing->primaryOccurrence;
    $occurrence->update(['status' => 'published', 'visibility' => EventVisibility::Public->value]);

    EventTimeExpression::query()->create([
        'event_id' => $existing->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'time_mode' => 'prayer_relative',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 0,
        'display_label' => EventPrayerTime::SelepasMaghrib->getLabel(),
    ]);
    EventTimeExpression::query()->create([
        'event_id' => $existing->getKey(),
        'event_occurrence_id' => $occurrence->getKey(),
        'event_session_id' => null,
        'time_mode' => 'prayer_relative',
        'anchor_type' => 'prayer',
        'anchor_code' => 'fajr',
        'relation' => 'after',
        'offset_minutes' => 0,
        'display_label' => EventPrayerTime::SelepasSubuh->getLabel(),
    ]);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Maghrib Contribution',
            'event_date' => now()->addDays(16)->toDateString(),
        ]),
        request: publicOccurrenceGroupingRequest(),
    );

    expect($result['event']->getKey())->not->toBe($existing->getKey())
        ->and($result['parent_created'])->toBeTrue()
        ->and($existing->occurrences()->count())->toBe(1);
});

it('renders the selected occurrence submission content on its public detail page and API payload', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $speaker = Person::factory()->create([
        'name' => 'Occurrence Speaker Only',
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $organizer = Person::factory()->create([
        'name' => 'Occurrence Organizer Only',
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $reference = Reference::factory()->create(['title' => 'Occurrence Reference Only', 'status' => 'verified']);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Occurrence Specific Topic',
            'description' => 'Occurrence specific description.',
            'persons' => [$speaker->getKey()],
            'primary_organizer_id' => $organizer->getKey(),
            'references' => [$reference->getKey()],
            'domain_tags' => [$this->domainTag->getKey()],
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    $event = $result['event'];
    $occurrence = $result['occurrence'];

    DB::table('events')->where('id', $event->getKey())->update([
        'status' => 'approved',
        'published_at' => now(),
        'visibility' => 'public',
    ]);
    $event->refresh();
    $occurrence->refresh();

    $this->get(route('events.occurrence', ['event' => $event, 'occurrenceSlug' => $occurrence->slug]))
        ->assertOk()
        ->assertSee('Occurrence Specific Topic')
        ->assertSee('Occurrence specific description.')
        ->assertSee('Occurrence Speaker Only')
        ->assertSee('Occurrence Organizer Only')
        ->assertSee('Occurrence Reference Only')
        ->assertSee('Wanita Sahaja')
        ->assertSee('Dewasa')
        ->assertSee('Malay')
        ->assertDontSee('Semua (Lelaki &amp; Wanita)')
        ->assertDontSee('Semua Peringkat Umur')
        ->assertDontSee('Mesra kanak-kanak');

    $publicOccurrenceUrl = route('events.occurrence', [
        'event' => $event,
        'occurrenceSlug' => PublicScheduleSlug::occurrence($occurrence),
    ]);
    $this->withSession([
        'event_auto_approved' => true,
        'event_occurrence_id' => $occurrence->getKey(),
        'event_occurrence_pending_review' => false,
        'event_occurrence_slug' => $occurrence->slug,
        'event_occurrence_status' => (string) $occurrence->status,
        'event_occurrence_visibility' => (string) $occurrence->visibility,
        'event_slug' => $event->slug,
        'event_status' => (string) $event->status,
        'event_visibility' => EventVisibility::Public->value,
        'event_title' => $event->title,
    ])->get(route('submit-event.success'))
        ->assertOk()
        ->assertSee('href="'.$publicOccurrenceUrl.'"', false)
        ->assertDontSee('href="'.route('events.show', $event).'"', false);

    $payload = EventPayloadData::fromModel($event->fresh())->toArray();

    expect($payload['title'])->toBe($event->title)
        ->and($payload['description'])->toBe('Occurrence specific description.')
        ->and($payload['gender'])->toBe(EventGenderRestriction::WomenOnly->value)
        ->and($payload['age_group'])->toBe([EventAgeGroup::Adults->value])
        ->and($payload['children_allowed'])->toBeFalse()
        ->and($payload['is_muslim_only'])->toBeTrue()
        ->and($payload['languages'])->toHaveCount(1)
        ->and($payload['references'])->toHaveCount(1)
        ->and($payload['prayer_offset'])->toBe(5)
        ->and($payload['display_occurrence']['id'])->toBe((string) $occurrence->getKey())
        ->and($payload['display_occurrence']['prayer_offset'])->toBe(5)
        ->and($payload['display_occurrence']['people'][0]['name'])->toBe('Occurrence Speaker Only')
        ->and($payload['display_occurrence']['organizer']['id'])->toBe((string) $organizer->getKey())
        ->and($payload['display_occurrence']['organizer']['type'])->toBe('person')
        ->and($payload['display_occurrence']['organizer']['name'])->toBe('Occurrence Organizer Only')
        ->and($result['event']->institution_id)->toBe((string) $institution->getKey());
});

it('filters public occurrence speakers, references and prayer slots while excluding draft occurrence data', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $publicSpeaker = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $draftSpeaker = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $publicReference = Reference::factory()->create(['title' => 'Public Occurrence Source', 'status' => 'verified']);
    $draftReference = Reference::factory()->create(['title' => 'Draft Occurrence Source', 'status' => 'verified']);

    $public = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Public Prayer Slot Submission',
            'persons' => [$publicSpeaker->getKey()],
            'references' => [$publicReference->getKey()],
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    DB::table('events')->where('id', $public['event']->getKey())->update([
        'status' => 'approved',
        'published_at' => now(),
        'visibility' => 'public',
    ]);
    $public['event']->refresh();

    $draft = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Draft Prayer Slot Submission',
            'persons' => [$draftSpeaker->getKey()],
            'references' => [$draftReference->getKey()],
        ]),
        request: publicOccurrenceGroupingRequest(),
    );

    expect($draft['event']->getKey())->toBe($public['event']->getKey())
        ->and((string) $draft['occurrence']->status)->toBe(EventOccurrence::DRAFT)
        ->and((string) $draft['occurrence']->visibility)->toBe(Event::PRIVATE);

    $matchingEventIds = function (string $filter, string $value): array {
        $response = $this->getJson('/api/v1/events?filter['.$filter.']='.$value)->assertOk();

        return collect($response->json('data'))->pluck('id')->all();
    };

    expect($matchingEventIds('person', (string) $publicSpeaker->getKey()))->toContain((string) $public['event']->getKey())
        ->and($matchingEventIds('reference_ids', (string) $publicReference->getKey()))->toContain((string) $public['event']->getKey())
        ->and($matchingEventIds('prayer_time', 'Selepas+Maghrib'))->toContain((string) $public['event']->getKey());

    $this->get(route('references.show', $publicReference))
        ->assertOk()
        ->assertSee($public['event']->title);

    DB::table('event_occurrences')->where('id', $public['occurrence']->getKey())->update([
        'status' => EventOccurrence::DRAFT,
        'visibility' => Event::PRIVATE,
    ]);

    expect($matchingEventIds('person', (string) $draftSpeaker->getKey()))->not->toContain((string) $public['event']->getKey())
        ->and($matchingEventIds('reference_ids', (string) $draftReference->getKey()))->not->toContain((string) $public['event']->getKey())
        ->and($matchingEventIds('prayer_time', 'Selepas+Maghrib'))->not->toContain((string) $public['event']->getKey());
});

it('creates different parents for institution, category, prayer direction and custom-time mismatches', function (): void {
    $firstInstitution = publicOccurrenceGroupingInstitution();
    $otherInstitution = publicOccurrenceGroupingInstitution('Masjid Seri Kembangan');
    $speaker = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $base = [
        'persons' => [$speaker->getKey()],
        'event_date' => now()->addDays(8)->toDateString(),
    ];

    $sameInstitutionAndSlot = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($firstInstitution, $base + ['title' => 'First']),
        request: publicOccurrenceGroupingRequest(),
    );
    $differentCategory = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($firstInstitution, $base + [
            'title' => 'Forum',
            'event_category_ids' => [eventCategoryId('forum_diskusi')],
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    $differentPrayerDirection = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($firstInstitution, $base + [
            'title' => 'Before Maghrib',
            'prayer_time' => EventPrayerTime::SebelumMaghrib->value,
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    $differentInstitution = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($otherInstitution, $base + ['title' => 'Other Mosque']),
        request: publicOccurrenceGroupingRequest(),
    );
    $customTime = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($firstInstitution, $base + [
            'title' => 'Custom Clock',
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '20:15',
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    $secondCustomTime = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($firstInstitution, $base + [
            'title' => 'Another Custom Clock',
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '20:15',
        ]),
        request: publicOccurrenceGroupingRequest(),
    );

    expect(collect([
        $sameInstitutionAndSlot['event']->getKey(),
        $differentCategory['event']->getKey(),
        $differentPrayerDirection['event']->getKey(),
        $differentInstitution['event']->getKey(),
        $customTime['event']->getKey(),
        $secondCustomTime['event']->getKey(),
    ])->unique())->toHaveCount(6)
        ->and($differentCategory['grouped'])->toBeTrue()
        ->and($differentPrayerDirection['grouped'])->toBeTrue()
        ->and($differentInstitution['grouped'])->toBeTrue()
        ->and($customTime['grouped'])->toBeFalse()
        ->and($secondCustomTime['grouped'])->toBeFalse()
        ->and(EventPayloadData::fromModel($differentPrayerDirection['event']->fresh())->toArray()['prayer_offset'])->toBe(-15)
        ->and(EventPayloadData::fromModel($differentPrayerDirection['event']->fresh())->toArray()['display_occurrence']['prayer_offset'])->toBe(-15);
});

it('stores Tarawih as a label-only occurrence and does not group it with Isyak', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $speaker = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $base = [
        'persons' => [$speaker->getKey()],
        'event_date' => now()->addDays(8)->toDateString(),
    ];

    $tarawih = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, array_merge($base, [
            'title' => 'Tarawih',
            'event_date' => '2027-02-10',
            'prayer_time' => EventPrayerTime::SelepasTarawih->value,
        ])),
        request: publicOccurrenceGroupingRequest(),
    );
    $isyak = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, $base + [
            'title' => 'Isyak',
            'prayer_time' => EventPrayerTime::SelepasIsyak->value,
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    $tarawihExpression = EventTimeExpression::query()
        ->where('event_occurrence_id', $tarawih['occurrence']->getKey())
        ->sole();
    $tarawihPayload = EventPayloadData::fromModel($tarawih['event']->fresh())->toArray();

    expect($tarawih['event']->getKey())->not->toBe($isyak['event']->getKey())
        ->and($tarawihExpression->anchor_code)->toBeNull()
        ->and($tarawihExpression->display_label)->toBe(EventPrayerTime::SelepasTarawih->getLabel())
        ->and($tarawihExpression->metadata['submission_group']['prayer_slot'])->toBe(EventPrayerTime::SelepasTarawih->value)
        ->and($tarawihPayload['prayer_reference'])->toBeNull()
        ->and($tarawihPayload['prayer_display_text'])->toBe(EventPrayerTime::SelepasTarawih->getLabel())
        ->and($tarawihPayload['prayer_offset'])->toBe(60)
        ->and($isyak['occurrence']->timeExpressions()->sole()->anchor_code)->toBe('isha');
});

it('holds appends to approved parents for occurrence review without changing the parent', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $speaker = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $firstDate = now()->addDays(5)->toDateString();
    $secondDate = now()->addDays(10)->toDateString();

    $first = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Approved Parent Date',
            'event_date' => $firstDate,
            'persons' => [$speaker->getKey()],
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    $event = $first['event'];
    DB::table('events')->where('id', $event->getKey())->update(['status' => 'approved']);
    $event->refresh();
    $registrationMode = $event->registration_mode;
    $accessPolicy = $event->accessPolicy()->firstOrFail()->only(['registration_required', 'walk_in_allowed']);

    $second = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Awaiting Review Date',
            'description' => 'Awaiting review only.',
            'event_date' => $secondDate,
            'persons' => [$speaker->getKey()],
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    $occurrence = $second['occurrence']->fresh();
    $submission = $second['submission']->fresh();
    $firstOccurrence = $first['occurrence']->fresh();

    expect($second['event']->getKey())->toBe($event->getKey())
        ->and($second['occurrence_pending_review'])->toBeTrue()
        ->and((string) $second['event']->fresh()->status)->toBe('approved')
        ->and((string) $occurrence->status)->toBe(EventOccurrence::DRAFT)
        ->and((string) $occurrence->visibility)->toBe(Event::PRIVATE)
        ->and((string) $submission->status)->toBe('pending')
        ->and($occurrence->metadata['public_submission']['requested_visibility'])->toBe(EventVisibility::Public->value)
        ->and($firstOccurrence->title)->toBe('Approved Parent Date')
        ->and($second['event']->fresh()->registration_mode)->toBe($registrationMode)
        ->and($second['event']->fresh()->accessPolicy()->firstOrFail()->only(['registration_required', 'walk_in_allowed']))->toBe($accessPolicy);

    $firstOccurrence->forceFill(['starts_at' => now()->subDays(1)])->save();
    $moderator = User::factory()->create();

    app(ModerateEventOccurrenceSubmissionAction::class)->approve(
        $submission,
        $moderator,
        'Schedule and venue verified.',
    );

    $reviewLog = EventSubmissionLog::query()
        ->where('event_submission_id', $submission->getKey())
        ->where('action', 'occurrence_approved')
        ->sole();
    $signalEvent = SignalEvent::query()
        ->where('event_name', 'moderation.event.occurrence_approved')
        ->latest('occurred_at')
        ->firstOrFail();

    expect((string) $event->fresh()->status)->toBe('approved')
        ->and((string) $event->fresh()->starts_at)->toBe((string) $occurrence->fresh()->starts_at)
        ->and((string) $submission->fresh()->status)->toBe('approved')
        ->and((string) $occurrence->fresh()->status)->toBe(EventOccurrence::SCHEDULED)
        ->and((string) $occurrence->fresh()->visibility)->toBe(Event::PUBLIC)
        ->and((string) $reviewLog->performed_by_id)->toBe((string) $moderator->getKey())
        ->and($reviewLog->metadata['event_occurrence_id'])->toBe((string) $occurrence->getKey())
        ->and(data_get($signalEvent->properties, 'event_occurrence_id'))->toBe((string) $occurrence->getKey());
});

it('rejects an appended occurrence with actor, review reason and outcome telemetry', function (): void {
    $institution = publicOccurrenceGroupingInstitution();
    $first = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Approved Parent for Rejection',
            'event_date' => now()->addDays(6)->toDateString(),
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    DB::table('events')->where('id', $first['event']->getKey())->update(['status' => 'approved']);

    $result = app(SubmitFrontendEventAction::class)->handle(
        state: publicOccurrenceGroupingPayload($institution, [
            'title' => 'Occurrence to Reject',
            'event_date' => now()->addDays(12)->toDateString(),
        ]),
        request: publicOccurrenceGroupingRequest(),
    );
    $moderator = User::factory()->create();

    app(ModerateEventOccurrenceSubmissionAction::class)->reject(
        $result['submission'],
        $moderator,
        'inaccurate_details',
        'The submitted time could not be verified.',
    );

    $submission = $result['submission']->fresh();
    $occurrence = $result['occurrence']->fresh();
    $reviewLog = EventSubmissionLog::query()
        ->where('event_submission_id', $submission->getKey())
        ->where('action', 'occurrence_rejected')
        ->sole();
    $signalEvent = SignalEvent::query()
        ->where('event_name', 'moderation.event.occurrence_rejected')
        ->latest('occurred_at')
        ->firstOrFail();

    expect((string) $result['event']->fresh()->status)->toBe('approved')
        ->and((string) $submission->status)->toBe('rejected')
        ->and($submission->review_reason)->toBe('inaccurate_details')
        ->and($submission->review_notes)->toBe('The submitted time could not be verified.')
        ->and((string) $occurrence->status)->toBe('archived')
        ->and($occurrence->status_reason)->toBe('The submitted time could not be verified.')
        ->and((string) $reviewLog->performed_by_id)->toBe((string) $moderator->getKey())
        ->and($reviewLog->metadata['reason_code'])->toBe('inaccurate_details')
        ->and(data_get($signalEvent->properties, 'event_occurrence_id'))->toBe((string) $occurrence->getKey())
        ->and(data_get($signalEvent->properties, 'reason_code'))->toBe('inaccurate_details');
});
