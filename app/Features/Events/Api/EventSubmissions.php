<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Public event-submission boundary.
 *
 * Root transport adapters use immutable scalar inputs and receive only
 * identifiers and flags. Event and occurrence models remain inside Events.
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
     * Assert selected languages and references are currently available.
     *
     * @param  array<string, mixed>  $state
     */
    /** @param array<string, mixed> $state
     *  @return array<string, mixed>
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
}
