<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Person contribution, admin and lifecycle workflow boundary.
 *
 * Root Filament/Livewire/HTTP/contribution callers use this instead of
 * mutating person rows, names, affiliations or the search projection directly.
 */
interface PersonWorkflow
{
    /**
     * Create a pending person submitted by an account.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createFromContribution(array $payload, string $proposerId): string;

    /**
     * Create a person from a quick-add form; the actor is optional for
     * unowned author records.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createFromForm(array $payload, ?string $actorId = null, bool $assignOwner = true): string;

    /**
     * Create an unowned reference-author person without inventing identity
     * fields.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createAuthor(array $payload): string;

    /**
     * Create an approved person directly in the verified state.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createVerifiedFromContribution(array $payload): string;

    /**
     * Create or update a person from the admin form.
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveFromAdmin(array $payload, string $actorId, ?string $personId = null, bool $assignOwner = true): string;

    /**
     * Apply an approved contribution update.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> Changed attributes.
     */
    public function applyContribution(string $personId, array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncRelations(string $personId, array $payload): void;

    public function syncNames(string $personId, mixed $names): void;

    public function syncAffiliations(string $personId, mixed $institutions): void;

    public function syncTitleAssignments(string $personId, mixed $assignments): void;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function attachAffiliation(string $personId, string $institutionId, array $attributes): string;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateAffiliation(string $personId, string $affiliationId, array $attributes): void;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateAffiliationForInstitution(string $personId, string $institutionId, array $attributes): void;

    /**
     * @param  list<string>  $institutionIds
     */
    public function detachAffiliations(string $personId, array $institutionIds): int;

    /**
     * @param  list<string>  $affiliationIds
     */
    public function deleteAffiliations(string $personId, array $affiliationIds): int;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function saveName(string $personId, ?string $nameId, array $attributes): string;

    /**
     * @param  list<string>  $nameIds
     */
    public function deleteNames(string $personId, array $nameIds): int;

    /**
     * @param  list<string>  $personIds
     */
    public function verifyPending(array $personIds): void;

    public function changeStatus(string $personId, string $status): void;

    public function detachVerifier(string $accountId): void;

    /**
     * @param  list<string>  $personIds
     */
    public function restoreVerifier(string $accountId, array $personIds): void;

    public function syncSlugsForName(string $name): bool;

    public function syncSlug(string $personId): bool;

    /**
     * @param  array<string, mixed>  $deletedAddress
     */
    public function syncSlugAfterAddressDeleted(string $personId, array $deletedAddress): bool;

    public function slugForPerson(string $personId): string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function buildSlug(string $name, array $payload = [], ?string $ignorePersonId = null): string;

    /**
     * @return list<string>
     */
    public function backfillSlugs(): array;

    /**
     * @param  list<string>  $order
     */
    public function reorderFeatured(array $order): void;

    public function syncSearchProjection(string $personId, bool $bustCache = true): void;

    public function purgeSearchProjection(string $personId, bool $bustCache = true): void;

    public function reindexSearch(int $chunkSize = 100): int;

    /**
     * Delete people through the owning feature; returns the deleted count.
     *
     * @param  list<string>  $personIds
     */
    public function delete(array $personIds): int;
}
