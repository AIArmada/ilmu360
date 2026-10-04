<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Actions\CreateEventSessionAction;
use AIArmada\Events\Actions\SyncEventLanguagesAction;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use AIArmada\Events\Models\VenueSpaceType;
use App\Data\Events\ValidatedEventSubmission;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Space;
use App\Services\EventKeyPersonSyncService;
use App\Services\Prayer\HardcodedPrayerFallback;
use App\Services\PrayerTimeExpressionResolver;
use App\Support\Events\AdminEventTimeMapper;
use App\Support\Submission\SubmissionRelationSync;
use App\Support\Submission\SubmissionValues;
use BackedEnum;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;

final readonly class PersistSessionSubmissionAction
{
    public function __construct(
        private CreateEventSessionAction $createEventSession,
        private GenerateEventSlugAction $generateEventSlug,
        private EventKeyPersonSyncService $eventKeyPersonSync,
        private SyncEventClassificationsAction $syncClassifications,
        private SyncEventLanguagesAction $syncLanguages,
        private SubmissionRelationSync $relationSync,
    ) {}

    /**
     * The container keeps its organizer, people, languages,
     * classifications, references, media, admission, and status
     * untouched; every relation below is scoped to the new session.
     *
     * @param  (callable(HasMedia): void)|null  $persistRelationships
     * @return array{event: Event, session: EventSession, submission: EventSubmission}
     */
    public function handle(ValidatedEventSubmission $submission, ?callable $persistRelationships = null): array
    {
        $state = $submission->state;

        $event = $this->resolvePersistedContainer($submission);
        $occurrence = $this->resolveTargetOccurrence($event, $submission->occurrenceId, $submission->validationKeyPrefix);
        $occurrenceId = (string) $occurrence->getKey();

        // The package uniquifies the slug within the event scope.
        $sessionDescription = $state['description'] ?? null;
        $sessionSummary = $this->sessionSummary($sessionDescription);

        $session = $this->createEventSession->handle($occurrence, [
            'title' => $state['title'],
            'slug' => $this->generateEventSlug->handle(
                (string) $state['title'],
                $state['event_date'] ?? null,
                $submission->timezone,
                null,
                $submission->personSlugSegments,
            ),
            'summary' => $sessionSummary,
            'description' => $sessionSummary,
            'starts_at' => $submission->startsAt,
            'ends_at' => $submission->endsAt,
            'timezone' => $submission->timezone,
            'visibility' => $state['visibility'] ?? $this->enumValue($event->visibility),
            'delivery_mode' => $state['event_format'] ?? $this->enumValue($event->delivery_mode),
            'metadata' => $this->localizedDescriptionMetadata($sessionDescription),
        ]);

        $this->persistSessionDetails($session, $submission);

        $this->eventKeyPersonSync->syncSession(
            $session,
            $this->relationSync->normalizePersonIds($state['persons'] ?? []),
            $this->relationSync->canonicalKeyPeople($state['other_key_people'] ?? []),
        );

        if (! empty($state['languages']) && is_array($state['languages'])) {
            $this->syncLanguages->handle(
                $session,
                Language::codesForIds($state['languages']),
                metadata: ['source' => 'commerce_languages'],
            );
        }

        $this->syncClassifications->handle($session, $state);
        $this->relationSync->syncSessionReferences($event, $occurrence, $session, $state['references'] ?? null);

        if ($persistRelationships !== null) {
            $persistRelationships($session);
        }

        $submissionData = ['submitter_name' => $state['submitter_name'] ?? $submission->submitter?->name];

        if (isset($state['notes'])) {
            $submissionData['notes'] = $state['notes'];
        }

        $eventSubmission = EventSubmission::query()->create([
            'event_id' => $event->getKey(),
            'event_occurrence_id' => $occurrenceId,
            'event_session_id' => $session->getKey(),
            'status' => 'pending',
            'submitted_at' => now(),
            'submission_data' => $submissionData,
            'submitter_type' => $submission->submitter?->getMorphClass(),
            'submitter_id' => $submission->submitter?->getKey(),
        ]);

        return ['event' => $event, 'session' => $session, 'submission' => $eventSubmission];
    }

    private function persistSessionDetails(EventSession $session, ValidatedEventSubmission $submission): void
    {
        $state = $submission->state;
        $scope = ['event_id' => $session->event_id, 'event_occurrence_id' => $session->event_occurrence_id];

        foreach (['event_url' => 'external', 'live_url' => 'streaming'] as $field => $type) {
            if (filled($state[$field] ?? null)) {
                $session->links()->create($scope + ['link_type' => $type, 'url' => $state[$field], 'visibility' => 'public']);
            }
        }

        $session->audiences()->create($scope + ['audience_type' => 'gender', 'value' => $state['gender'] ?? EventGenderRestriction::All->value]);

        foreach ($state['age_group'] ?? [EventAgeGroup::AllAges->value] as $order => $ageGroup) {
            $session->audiences()->create($scope + ['audience_type' => 'age_group', 'value' => $ageGroup, 'sort_order' => $order]);
        }

        if ($state['is_muslim_only'] ?? false) {
            $session->audiences()->create($scope + ['audience_type' => 'religion', 'value' => 'muslim_only']);
        }

        $session->audienceProfiles()->create($scope + ['is_child_friendly' => $state['children_allowed'] ?? true]);

        if ($submission->prayerTime !== null && ! $submission->prayerTime->isCustomTime()) {
            $offset = $submission->prayerOffset ?? 5;
            $session->timeExpressions()->create($scope + [
                'time_mode' => 'prayer_relative',
                'anchor_type' => 'prayer',
                'anchor_code' => $submission->prayerReference,
                'relation' => $offset < 0 ? 'before' : 'after',
                'offset_minutes' => abs($offset),
                'display_label' => $submission->prayerDisplayText,
                'resolver_class' => PrayerTimeExpressionResolver::class,
                'metadata' => [
                    'prayer' => array_filter([
                        'source' => $submission->prayerSource ?? HardcodedPrayerFallback::SOURCE,
                        'fetched_at' => $submission->prayerFetchedAt ?? now()->toIso8601String(),
                        'country' => $submission->prayerCountry,
                        'zone' => $submission->prayerZone,
                        'prayer_date' => AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $submission->timezone),
                        'lat' => $submission->prayerLat,
                        'lng' => $submission->prayerLng,
                        'venue_id' => $submission->targetVenueId,
                        'institution_id' => $submission->targetInstitutionId,
                    ], static fn (mixed $value): bool => $value !== null),
                ],
                'resolved_starts_at' => $submission->startsAt,
                'resolved_at' => now(),
            ]);
        }

        if (($state['event_format'] ?? EventFormat::Physical->value) === EventFormat::Online->value) {
            return;
        }

        $institution = $submission->targetInstitutionId === null ? null : Institution::query()->find($submission->targetInstitutionId);
        $spaceIds = $state['space_ids'] ?? [];
        $spaces = Space::query()->whereKey($spaceIds)->get(['id', 'name', 'space_type'])->keyBy('id');
        $spaceTypeIds = VenueSpaceType::query()->whereIn('code', $spaces->pluck('space_type')->filter()->unique())->pluck('id', 'code');
        $locationIds = $spaceIds === [] ? [null] : $spaceIds;

        foreach ($locationIds as $order => $spaceId) {
            $space = $spaces->get($spaceId);
            $session->locations()->create($scope + [
                'location_role' => $order === 0 ? 'primary' : 'additional',
                'locationable_type' => $institution?->getMorphClass(),
                'locationable_id' => $institution?->getKey(),
                'venue_id' => $submission->targetVenueId,
                'venue_space_id' => $spaceId,
                'venue_space_type_id' => $space === null ? null : $spaceTypeIds->get($space->space_type),
                'space_name_snapshot' => $space?->name,
                'visibility' => 'public',
                'status' => 'active',
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * Re-resolve the container from storage; a deleted container fails
     * closed instead of silently becoming a new event.
     */
    private function resolvePersistedContainer(ValidatedEventSubmission $submission): Event
    {
        $containerId = $submission->eventContainer?->getKey();

        $event = is_string($containerId) && $containerId !== ''
            ? Event::query()->find($containerId)
            : null;

        if (! $event instanceof Event) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_id', $submission->validationKeyPrefix) => __('Majlis yang dipilih tidak lagi tersedia.'),
            ]);
        }

        return $event;
    }

    /**
     * The selected occurrence wins; otherwise the primary occurrence is the
     * default for single-date events. Terminal occurrences never accept
     * new sessions.
     */
    private function resolveTargetOccurrence(Event $event, ?string $occurrenceId, string $validationKeyPrefix = ''): EventOccurrence
    {
        if (is_string($occurrenceId) && $occurrenceId !== '') {
            $occurrence = EventOccurrence::query()
                ->whereKey($occurrenceId)
                ->where('event_id', $event->getKey())
                ->first();

            if (! $occurrence instanceof EventOccurrence) {
                throw ValidationException::withMessages([
                    SubmissionValues::prefixedKey('event_occurrence_id', $validationKeyPrefix) => __('Jadual yang dipilih bukan milik majlis ini.'),
                ]);
            }
        } else {
            $occurrence = $event->primaryOccurrence;

            if (! $occurrence instanceof EventOccurrence) {
                throw ValidationException::withMessages([
                    SubmissionValues::prefixedKey('event_id', $validationKeyPrefix) => __('The selected event has no occurrence for this session.'),
                ]);
            }
        }

        if (in_array((string) $occurrence->status, [EventOccurrence::CANCELLED, EventOccurrence::COMPLETED, EventOccurrence::ARCHIVED], true)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_occurrence_id', $validationKeyPrefix) => __('Jadual yang dipilih tidak lagi menerima sesi baharu.'),
            ]);
        }

        return $occurrence;
    }

    /**
     * Session summary and description are plain string columns (the package
     * action string-casts them), so a localized map resolves to the current
     * locale value, then the fallback locale value, then the first non-blank
     * entry. Strings pass through for the package normalizers.
     */
    private function sessionSummary(mixed $description): ?string
    {
        if (is_string($description)) {
            return $description;
        }

        if (! is_array($description)) {
            return null;
        }

        $locales = [];

        foreach ([app()->getLocale(), config('app.fallback_locale')] as $locale) {
            if (is_string($locale) && $locale !== '' && ! in_array($locale, $locales, true)) {
                $locales[] = $locale;
            }
        }

        foreach ($locales as $locale) {
            $value = $description[$locale] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        foreach ($description as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The full localized map survives on the array-cast metadata column; the
     * string-only summary/description fields carry just the resolved scalar.
     *
     * @return array{description_localized: array<string, string|null>}|null
     */
    private function localizedDescriptionMetadata(mixed $description): ?array
    {
        if (! is_array($description) || $description === []) {
            return null;
        }

        return ['description_localized' => $description];
    }

    /**
     * Model-attribute unwrap only: backed enums become their values, anything
     * else (including null) passes through untouched.
     */
    private function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
