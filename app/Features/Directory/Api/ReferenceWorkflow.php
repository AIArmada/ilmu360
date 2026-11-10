<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Reference contribution, admin, lifecycle and indexing workflow boundary.
 *
 * Root Filament/Livewire/HTTP/contribution callers use this instead of
 * mutating reference rows, contributors, hierarchy, slugs or search state
 * directly.
 */
interface ReferenceWorkflow
{
    /**
     * Create a pending reference submitted by an account.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createFromContribution(array $payload): string;

    /**
     * Create a reference from a quick-add form.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createFromForm(array $payload): string;

    /**
     * Create an approved reference directly in the verified state.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createVerifiedFromContribution(array $payload): string;

    /**
     * Create or update a reference from the admin form.
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveFromAdmin(array $payload, ?string $referenceId = null): string;

    /**
     * Apply an approved contribution update.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> Changed attributes.
     */
    public function applyContribution(string $referenceId, array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncRelations(string $referenceId, array $payload): void;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncMedia(string $referenceId, array $payload): void;

    public function syncContributors(string $referenceId, mixed $authorIds): void;

    public function clearContributors(string $referenceId): void;

    public function changeStatus(string $referenceId, string $status): void;

    /**
     * Delete references through the owning feature; returns the deleted count.
     *
     * @param  list<string>  $referenceIds
     */
    public function delete(array $referenceIds): int;

    public function buildSlug(string $title, ?string $ignoreReferenceId = null): string;

    public function syncSlugsForTitle(string $title): bool;

    public function syncSlug(string $referenceId): bool;

    /**
     * Sync slugs and search identities after a hierarchy or bibliographic change.
     *
     * @param  list<string>  $changedAttributes
     */
    public function syncHierarchy(string $referenceId, array $changedAttributes = []): void;

    /**
     * @return list<string>
     */
    public function backfillSlugs(): array;

    public function syncSearchRecord(string $referenceId): void;

    public function reindexSearchFamily(string $referenceId): void;

    public function reindexSearch(int $chunkSize = 100): int;
}
