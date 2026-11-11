<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Shared\Events\ValidatedEventSubmission;

/**
 * Public event-submission boundary.
 *
 * Root transport adapters (Livewire page, frontend API controller, submission
 * orchestrators) use this contract while the Event model and completion
 * side effects remain conventional. Methods take plain arrays/scalars and the
 * shared validated submission value; results expose scalar identifiers and
 * flags only, never Eloquent models.
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
    public function assertCatalogSelectionsAreAvailable(array $state, string $prefix = ''): void;

    /**
     * Persist an ordinary public submission as a parent event plus occurrence.
     *
     * @param  (callable(\Spatie\MediaLibrary\HasMedia): void)|null  $persistMedia
     * @return array{event_id: string, submission_id: string|null, occurrence_id: string|null, session_id: string|null, parent_created: bool, grouped: bool, occurrence_pending_review: bool}
     */
    public function persistNew(ValidatedEventSubmission $input, ?callable $persistMedia = null): array;

    /**
     * Persist a session inside an existing event container.
     *
     * @param  (callable(\Spatie\MediaLibrary\HasMedia): void)|null  $persistMedia
     * @return array{event_id: string, submission_id: string|null, occurrence_id: string|null, session_id: string|null, parent_created: bool, grouped: bool, occurrence_pending_review: bool}
     */
    public function persistSession(ValidatedEventSubmission $input, ?callable $persistMedia = null): array;
}
