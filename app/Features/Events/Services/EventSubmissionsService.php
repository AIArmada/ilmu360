<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use AIArmada\Events\Features\Catalog\Models\Event as PackageEvent;
use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Events\Features\Governance\Models\EventSubmission as PackageEventSubmission;
use App\Features\Events\Actions\Submission\CompleteEventSubmissionWritesAction;
use App\Features\Events\Actions\Submission\PersistNewEventSubmissionAction;
use App\Features\Events\Actions\Submission\PersistSessionSubmissionAction;
use App\Features\Events\Actions\Submission\ResolveEventSubmissionPrayerStartAction;
use App\Features\Events\Actions\Submission\ReviewOccurrenceSubmissionAction;
use App\Features\Events\Actions\Submission\SubmissionRelationSync;
use App\Features\Events\Actions\Submission\ValidatedEventSubmission;
use App\Features\Events\Actions\Submission\ValidateEventSubmissionCategoriesAction;
use App\Features\Events\Actions\Submission\ValidateEventSubmissionInputAction;
use App\Features\Events\Api\Data\EventSubmissionCard;
use App\Features\Events\Api\Data\EventSubmissionPage;
use App\Features\Events\Api\Data\EventSubmissionRewardFact;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventSubmissionInput;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventSubmission;
use App\Features\Events\Queries\EventSubmissionCardProjector;
use App\Features\Events\Support\EventModel;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Models\User;
use App\Platform\Models\MorphModelResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Submission persistence boundary implementation.
 *
 * Delegates validation to the structural guard and persistence to the named
 * submission actions, then reduces their model results to scalar identifiers
 * and flags so no Eloquent model crosses the Events public boundary.
 */
final readonly class EventSubmissionsService implements EventSubmissions
{
    public function __construct(
        private ValidateEventSubmissionInputAction $validateInput,
        private ValidateEventSubmissionCategoriesAction $categories,
        private ResolveEventSubmissionPrayerStartAction $prayerStart,
        private PersistNewEventSubmissionAction $persistNewEvent,
        private PersistSessionSubmissionAction $persistSession,
        private ReviewOccurrenceSubmissionAction $reviewOccurrenceSubmission,
        private CompleteEventSubmissionWritesAction $completeWrites,
        private SubmissionRelationSync $relationSync,
        private EventSubmissionCardProjector $cards,
    ) {}

    public function validate(array $state, string $prefix = ''): array
    {
        return $this->validateInput->handle($state, $prefix);
    }

    public function normalizeCategories(array $state, string $prefix = ''): array
    {
        return $this->categories->normalize($state, $prefix);
    }

    public function resolvePrayerStartClock(
        ?string $institutionId,
        ?string $venueId,
        ?string $countryCode,
        string $timezone,
        string $prayerTime,
        string $eventDate,
    ): ?array {
        return $this->prayerStart->handle(
            $institutionId,
            $venueId,
            $countryCode,
            $timezone,
            $prayerTime,
            $eventDate,
        );
    }

    public function assertCommunityPhysicalFormat(array $state, string $prefix = ''): void
    {
        $this->categories->assertCommunityPhysicalFormat($state, $prefix);
    }

    public function assertCatalogSelectionsAreAvailable(array $state, string $prefix = ''): void
    {
        $this->relationSync->assertCatalogSelectionsAreAvailable($state, $prefix);
    }

    public function persistNew(EventSubmissionInput $input): array
    {
        $persisted = $this->persistNewEvent->handle($this->resolveInput($input));

        return $this->result(
            event: $persisted['event'],
            submission: $persisted['submission'],
            occurrence: $persisted['occurrence'],
            sessionId: null,
            parentCreated: $persisted['parent_created'],
            grouped: $persisted['grouped'],
            occurrencePendingReview: $persisted['occurrence_pending_review'],
        );
    }

    public function persistSession(EventSubmissionInput $input): array
    {
        $persisted = $this->persistSession->handle($this->resolveInput($input));
        $occurrence = $persisted['session']->occurrence;

        return $this->result(
            event: $persisted['event'],
            submission: $persisted['submission'],
            occurrence: $occurrence instanceof EventOccurrence ? $occurrence : null,
            sessionId: (string) $persisted['session']->getKey(),
            parentCreated: false,
            grouped: false,
            occurrencePendingReview: false,
        );
    }

    public function approveOccurrenceSubmission(
        string $submissionId,
        string $performedByType,
        string $performedById,
        ?string $note = null,
    ): array {
        return $this->reviewOccurrenceSubmission->approve(
            $submissionId,
            $performedByType,
            $performedById,
            $note,
        );
    }

    public function rejectOccurrenceSubmission(
        string $submissionId,
        string $performedByType,
        string $performedById,
        string $reason,
        string $note,
    ): array {
        return $this->reviewOccurrenceSubmission->reject(
            $submissionId,
            $performedByType,
            $performedById,
            $reason,
            $note,
        );
    }

    public function completeWrites(
        string $eventId,
        string $submissionId,
        array $state,
        bool $storeSubmitterContacts,
        bool $sessionSubmission,
        bool $parentCreated,
        bool $autoApproved,
    ): ?string {
        return $this->completeWrites->handle(
            eventId: $eventId,
            submissionId: $submissionId,
            state: $state,
            storeSubmitterContacts: $storeSubmitterContacts,
            sessionSubmission: $sessionSubmission,
            parentCreated: $parentCreated,
            autoApproved: $autoApproved,
        );
    }

    /**
     * @return array{event_id: string, submission_id: string, occurrence_id: string|null, session_id: string|null, parent_created: bool, grouped: bool, occurrence_pending_review: bool}
     */
    private function result(
        PackageEvent $event,
        PackageEventSubmission $submission,
        ?EventOccurrence $occurrence,
        ?string $sessionId,
        bool $parentCreated,
        bool $grouped,
        bool $occurrencePendingReview,
    ): array {
        return [
            'event_id' => (string) $event->getKey(),
            'submission_id' => (string) $submission->getKey(),
            'occurrence_id' => $occurrence instanceof EventOccurrence ? (string) $occurrence->getKey() : null,
            'session_id' => $sessionId,
            'parent_created' => $parentCreated,
            'grouped' => $grouped,
            'occurrence_pending_review' => $occurrencePendingReview,
        ];
    }

    private function resolveInput(EventSubmissionInput $input): ValidatedEventSubmission
    {
        $primaryOrganizer = $this->resolveModel($input->primaryOrganizerKind->value, $input->primaryOrganizerId);
        $submitter = $input->submitterId === null ? null : $this->resolveModel('user', $input->submitterId);
        $eventContainer = $input->eventContainerId === null
            ? null
            : EventModel::query('event')->find($input->eventContainerId);

        if ($eventContainer !== null && ! $eventContainer instanceof PackageEvent) {
            throw new \RuntimeException('Expected the event model to extend the events package model.');
        }

        return new ValidatedEventSubmission(
            state: $input->state,
            startsAt: Carbon::parse($input->startsAt),
            endsAt: $input->endsAt === null ? null : Carbon::parse($input->endsAt),
            timezone: $input->timezone,
            primaryOrganizer: $primaryOrganizer,
            targetInstitutionId: $input->targetInstitutionId,
            targetVenueId: $input->targetVenueId,
            prayerTime: $input->prayerTime === null ? null : EventPrayerTime::from($input->prayerTime),
            prayerReference: $input->prayerReference,
            prayerOffset: $input->prayerOffset,
            prayerDisplayText: $input->prayerDisplayText,
            autoApproved: $input->autoApproved,
            sessionSubmission: $input->sessionSubmission,
            submitter: $submitter,
            eventContainer: $eventContainer,
            personSlugSegments: $input->personSlugSegments,
            occurrenceId: $input->occurrenceId,
            validationKeyPrefix: $input->validationKeyPrefix,
            prayerSource: $input->prayerSource,
            prayerFetchedAt: $input->prayerFetchedAt,
            prayerZone: $input->prayerZone,
            prayerLat: $input->prayerLat,
            prayerLng: $input->prayerLng,
            prayerCountry: $input->prayerCountry,
        );
    }

    private function resolveModel(string $alias, string $id): Model
    {
        $modelClass = MorphModelResolver::resolve($alias);
        $model = $modelClass::query()->findOrFail($id);

        if (! $model instanceof Model) {
            throw new \RuntimeException("Expected the [{$alias}] morph alias to resolve to an Eloquent model.");
        }

        return $model;
    }

    public function paginateForSubmitter(
        string $submitterId,
        int $perPage,
        string $pageName,
        ?string $statusGroup = null,
        ?string $search = null,
    ): EventSubmissionPage {
        $query = $this->submitterQuery($submitterId)
            ->with($this->submissionRelations());

        $this->applyStatusGroup($query, $statusGroup);
        $this->applyTitleSearch($query, $search);

        $page = $query
            ->latest('created_at')
            ->paginate(perPage: $perPage, pageName: $pageName);

        return new EventSubmissionPage(
            items: $page->getCollection()->map(fn (EventSubmission $submission): EventSubmissionCard => $this->cards->toCard($submission))->all(),
            total: $page->total(),
            perPage: $page->perPage(),
            currentPage: $page->currentPage(),
        );
    }

    /** @return list<EventSubmissionCard> */
    public function recentForSubmitter(string $submitterId, int $limit = 5): array
    {
        return $this->submitterQuery($submitterId)
            ->with($this->submissionRelations())
            ->latest('created_at')
            ->limit(max($limit, 1))
            ->get()
            ->map(fn (EventSubmission $submission): EventSubmissionCard => $this->cards->toCard($submission))
            ->all();
    }

    public function findCardForSubmitter(string $submitterId, string $submissionId): ?EventSubmissionCard
    {
        if (! Str::isUuid($submissionId)) {
            return null;
        }

        $submission = $this->submitterQuery($submitterId)
            ->whereKey($submissionId)
            ->with($this->submissionRelations())
            ->first();

        return $submission instanceof EventSubmission ? $this->cards->toCard($submission) : null;
    }

    public function summaryCountsForSubmitter(string $submitterId): array
    {
        return [
            'total' => $this->submitterQuery($submitterId)->count(),
            'pending' => $this->countForStatusGroup($submitterId, 'pending'),
            'approved' => $this->countForStatusGroup($submitterId, 'published'),
            'attention' => $this->countForStatusGroup($submitterId, 'attention'),
        ];
    }

    public function searchPublicContributionTargets(string $search = '', int $limit = 12): array
    {
        $query = Event::query()
            ->where('events.status', 'approved')
            ->where('events.visibility', EventVisibility::Public)
            ->whereNotNull('events.published_at')
            ->whereHas('occurrences')
            ->with(['institution.names', 'occurrences'])
            ->orderBy('events.title')
            ->limit(max($limit, 1));

        $term = trim($search);

        if ($term !== '') {
            $query->whereLike('events.title', "%{$term}%");
        }

        /** @var list<array{slug: string, title: string, subtitle: ?string}> $targets */
        $targets = $query->get()
            ->map(function (Event $event): array {
                $institutionName = $event->institution?->getAttribute('display_name');
                $institutionName = is_string($institutionName) && $institutionName !== '' ? $institutionName : null;
                $startsAt = $event->starts_at;
                $dateLabel = $startsAt instanceof CarbonInterface
                    ? UserDateTimeFormatter::translatedFormat($startsAt, 'j M Y')
                    : null;
                $parts = array_values(array_filter([$institutionName, $dateLabel]));

                return [
                    'slug' => (string) $event->slug,
                    'title' => (string) $event->title,
                    'subtitle' => $parts !== [] ? implode(' · ', $parts) : null,
                ];
            })
            ->values()
            ->all();

        return $targets;
    }

    /**
     * @return Builder<EventSubmission>
     */
    private function submitterQuery(string $submitterId): Builder
    {
        $institutionIdSelector = 'events.institution_id';

        return EventSubmission::query()
            ->where('submitter_type', (new User)->getMorphClass())
            ->where('submitter_id', $submitterId)
            ->whereHas('event', function (Builder $query) use ($institutionIdSelector, $submitterId): void {
                $query->where(function (Builder $eventQuery) use ($institutionIdSelector, $submitterId): void {
                    $eventQuery->whereRaw("{$institutionIdSelector} is null")
                        ->orWhereRaw(
                            "not exists (
                                select 1
                                from institution_members
                                where institution_members.institution_id = {$institutionIdSelector}
                                  and institution_members.user_id = ?
                            )",
                            [$submitterId],
                        );
                });
            });
    }

    /**
     * @return list<string>
     */
    private function submissionRelations(): array
    {
        return [
            'event.institution',
            'event.persons',
            'event.references',
            'occurrence.involvements.involveable',
            'occurrence.references.referenceable',
        ];
    }

    private function countForStatusGroup(string $submitterId, string $statusGroup): int
    {
        $query = $this->submitterQuery($submitterId);
        $this->applyStatusGroup($query, $statusGroup);

        return $query->count();
    }

    /**
     * @param  Builder<EventSubmission>  $query
     */
    private function applyStatusGroup(Builder $query, ?string $statusGroup): void
    {
        $statuses = $this->statusGroupValues($statusGroup);

        if ($statuses === null) {
            return;
        }

        $query->where(function (Builder $statusQuery) use ($statuses): void {
            $statusQuery
                ->where(function (Builder $occurrenceBranch) use ($statuses): void {
                    $occurrenceBranch
                        ->whereNotNull('event_occurrence_id')
                        ->whereHas('occurrence', fn (Builder $occurrenceQuery): Builder => $occurrenceQuery->whereIn('status', $statuses));
                })
                ->orWhere(function (Builder $eventBranch) use ($statuses): void {
                    $eventBranch
                        ->whereNull('event_occurrence_id')
                        ->whereHas('event', fn (Builder $eventQuery): Builder => $eventQuery->whereIn('events.status', $statuses));
                });
        });
    }

    /**
     * @param  Builder<EventSubmission>  $query
     */
    private function applyTitleSearch(Builder $query, ?string $search): void
    {
        $term = trim((string) $search);

        if ($term === '') {
            return;
        }

        $pattern = "%{$term}%";

        $query->where(function (Builder $searchQuery) use ($pattern): void {
            $searchQuery
                ->whereHas('event', fn (Builder $eventQuery): Builder => $eventQuery->whereLike('events.title', $pattern))
                ->orWhereHas('occurrence', fn (Builder $occurrenceQuery): Builder => $occurrenceQuery->whereLike('title', $pattern));
        });
    }

    /**
     * @return list<string>|null
     */
    private function statusGroupValues(?string $statusGroup): ?array
    {
        return match ($statusGroup) {
            'pending' => ['pending', 'draft'],
            'published' => ['approved', 'published', 'scheduled', 'live', 'completed'],
            'attention' => ['rejected', 'needs_changes', 'cancelled', 'postponed', 'rescheduled', 'delayed'],
            default => null,
        };
    }

    /** @return list<EventSubmissionCard> */
    public function submissionsForAccount(string $accountId): array
    {
        return EventSubmission::query()
            ->where('submitter_type', (new User)->getMorphClass())
            ->where('submitter_id', $accountId)
            ->with(['event.institution', 'event.persons', 'event.references', 'occurrence.involvements.involveable', 'occurrence.references.referenceable'])
            ->latest()
            ->get()
            ->map(fn (EventSubmission $submission): EventSubmissionCard => $this->cards->toCard($submission))
            ->all();
    }

    /** @return list<EventSubmissionCard> */
    public function plannerSubmissionsForAccount(string $accountId): array
    {
        return EventSubmission::query()
            ->where('submitter_type', (new User)->getMorphClass())
            ->where('submitter_id', $accountId)
            ->with(['event.media', 'event.institution.media', 'event.venue', 'event.persons', 'event.references', 'occurrence.involvements.involveable', 'occurrence.references.referenceable'])
            ->latest()
            ->get()
            ->map(fn (EventSubmission $submission): EventSubmissionCard => $this->cards->toCard($submission))
            ->all();
    }

    public function existsForSubmitter(string $eventId, string $accountId): bool
    {
        return EventSubmission::query()
            ->where('event_id', $eventId)
            ->where('submitter_type', (new User)->getMorphClass())
            ->where('submitter_id', $accountId)
            ->exists();
    }

    public function moderationStatus(string $submissionId): ?string
    {
        $submission = EventSubmission::query()->find($submissionId);

        if (! $submission instanceof EventSubmission) {
            return null;
        }

        $status = $submission->getAttribute('status');

        if (is_string($status)) {
            return $status;
        }

        if (is_object($status) && method_exists($status, 'getValue')) {
            return (string) $status->getValue();
        }

        return (string) $status;
    }

    public function findSubmission(string $submissionId): ?EventSubmissionRewardFact
    {
        $submission = EventSubmission::query()->find($submissionId);

        if (! $submission instanceof EventSubmission) {
            return null;
        }

        $attrs = $submission->getAttributes();
        $metadata = array_key_exists('metadata', $attrs) ? $submission->getAttribute('metadata') : null;
        $metadata = is_array($metadata) ? $metadata : [];
        $occurredAt = $submission->getAttribute('submitted_at') ?? $submission->getAttribute('created_at');
        $ambiguous = $attrs['is_ambiguous_device'] ?? ($metadata['is_ambiguous_device'] ?? false);
        $bot = $attrs['is_bot'] ?? ($metadata['is_bot'] ?? false);

        return new EventSubmissionRewardFact(
            id: (string) $submission->getKey(),
            submitterType: $this->rewardScalar($submission->getAttribute('submitter_type')),
            submitterId: $this->rewardScalar($submission->getAttribute('submitter_id')),
            status: $this->rewardScalar($submission->getAttribute('status')),
            occurredAt: $occurredAt instanceof \DateTimeInterface
                ? \Carbon\CarbonImmutable::parse($occurredAt)->toIso8601String()
                : (is_string($occurredAt) ? $occurredAt : null),
            metadata: $metadata,
            fraudStatus: $this->rewardScalar($attrs['fraud_status'] ?? null),
            isAmbiguousDevice: in_array($ambiguous, [true, 1, '1'], true),
            isBot: in_array($bot, [true, 1, '1'], true),
        );
    }

    private function rewardScalar(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_object($value) && method_exists($value, 'getValue')
            ? (string) $value->getValue()
            : null;
    }

    public function hasEarlierSubmissionActivity(
        string $actorId,
        string $actorType,
        \DateTimeInterface $before,
        string $exceptId,
    ): bool {
        return EventSubmission::query()
            ->where(function (Builder $query) use ($actorId, $actorType): void {
                $query->where('submitter_type', $actorType)
                    ->where('submitter_id', $actorId);
            })
            ->where('submitted_at', '<', $before)
            ->whereKeyNot($exceptId)
            ->exists();
    }
}
