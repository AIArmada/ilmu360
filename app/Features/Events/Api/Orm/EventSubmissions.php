<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use App\Features\Events\Api\Data\EventSubmissionRewardFact;
use App\Features\Events\Api\EventSubmissionInput;
use App\Features\Events\Models\EventSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Public event-submission boundary.
 *
 * Root transport adapters use immutable scalar inputs and receive only
 * identifiers and flags. Event and occurrence models remain inside Events.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Events/FeatureServiceProvider.php, Rewards/Queries, Events/Services, Events/Actions, Events/EntryPoints, Identity/EntryPoints
 */
interface EventSubmissions
{
    /**
     * Structural validation and normalization of raw submission state.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function validate(array $state, string $prefix = ''): array;

    /**
     * Reject unknown, inactive or wrong-taxonomy category selections.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function normalizeCategories(array $state, string $prefix = ''): array;

    /**
     * Resolve cached provider prayer clock using scalar selections.
     *
     * @return array{clock: string, date: string, starts_at: string, source: string, fetched_at: string, zone: string|null, lat: float|null, lng: float|null, country: string}|null
     */
    public function resolvePrayerStartClock(
        ?string $institutionId,
        ?string $venueId,
        ?string $countryCode,
        string $timezone,
        string $prayerTime,
        string $eventDate,
    ): ?array;

    /** @param array<string, mixed> $state */
    public function assertCommunityPhysicalFormat(array $state, string $prefix = ''): void;

    /**
     * Assert selected languages and references are currently available.
     *
     * @param  array<string, mixed>  $state
     */
    public function assertCatalogSelectionsAreAvailable(array $state, string $prefix = ''): void;

    /**
     * Persist an ordinary public submission as a parent event plus occurrence.
     *
     * @return array{event_id: string, submission_id: string, occurrence_id: string|null, session_id: string|null, parent_created: bool, grouped: bool, occurrence_pending_review: bool}
     */
    public function persistNew(EventSubmissionInput $input): array;

    /**
     * Persist a session inside an existing event container.
     *
     * @return array{event_id: string, submission_id: string, occurrence_id: string|null, session_id: string|null, parent_created: bool, grouped: bool, occurrence_pending_review: bool}
     */
    public function persistSession(EventSubmissionInput $input): array;

    /**
     * Approve a pending occurrence submission inside the caller's transaction.
     *
     * @return array{event_id: string, submission_id: string, occurrence_id: string}
     */
    public function approveOccurrenceSubmission(
        string $submissionId,
        string $performedByType,
        string $performedById,
        ?string $note = null,
    ): array;

    /**
     * Reject a pending occurrence submission inside the caller's transaction.
     *
     * @return array{event_id: string, submission_id: string, occurrence_id: string}
     */
    public function rejectOccurrenceSubmission(
        string $submissionId,
        string $performedByType,
        string $performedById,
        string $reason,
        string $note,
    ): array;

    /**
     * Persist the completion writes owned by Events.
     *
     * Stores guest submitter contacts when requested, then applies the
     * open-door admission defaults only for a newly created parent event.
     * Returns the moderation action the caller should apply, if any.
     *
     * @param  array<string, mixed>  $state
     * @return 'approve'|'submit'|null
     */
    public function completeWrites(
        string $eventId,
        string $submissionId,
        array $state,
        bool $storeSubmitterContacts,
        bool $sessionSubmission,
        bool $parentCreated,
        bool $autoApproved,
    ): ?string;

    /**
     * Documented transport: paginated submissions for an account, excluding
     * events owned by an institution the account does not belong to, with the
     * contribution presentation relations eager loaded.
     *
     * @return LengthAwarePaginator<int, EventSubmission>
     */
    public function paginateForSubmitter(string $submitterId, int $perPage, string $pageName): LengthAwarePaginator;

    /**
     * Documented transport: submissions for an account with the event loaded,
     * newest first.
     *
     * @return EloquentCollection<int, EventSubmission>
     */
    public function submissionsForAccount(string $accountId): EloquentCollection;

    /**
     * Documented transport: planner submissions for an account with the event
     * and planner relations loaded, newest first.
     *
     * @return EloquentCollection<int, EventSubmission>
     */
    public function plannerSubmissionsForAccount(string $accountId): EloquentCollection;

    public function existsForSubmitter(string $eventId, string $accountId): bool;

    /**
     * Moderation status for a submission id, or null when it no longer exists.
     */
    public function moderationStatus(string $submissionId): ?string;

    /** Immutable source evidence for reward qualification. */
    public function findSubmission(string $submissionId): ?EventSubmissionRewardFact;

    /**
     * Whether an earlier submission exists for the actor.
     */
    public function hasEarlierSubmissionActivity(
        string $actorId,
        string $actorType,
        \DateTimeInterface $before,
        string $exceptId,
    ): bool;
}
