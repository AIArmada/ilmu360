<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Institution contribution and lifecycle workflow boundary.
 *
 * Contributions, Events and legacy submission adapters call this instead of
 * mutating institution rows directly.
 */
interface InstitutionWorkflow
{
    /**
     * Create a pending institution submitted by an account.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createFromContribution(array $payload, string $proposerId): string;

    /**
     * Create an institution from a quick-add form; the actor is optional for
     * guest submissions.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createFromForm(array $payload, ?string $actorId = null): string;

    /**
     * Create or update an institution from the admin form.
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveFromAdmin(array $payload, string $actorId, ?string $institutionId = null): string;

    /**
     * Create an approved institution directly in the verified state.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createVerifiedFromContribution(array $payload): string;

    /**
     * Apply an approved contribution update.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> Changed attributes.
     */
    public function applyContribution(string $institutionId, array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncRelations(string $institutionId, array $payload): void;

    /**
     * @param  list<array<string, mixed>>  $names
     */
    public function syncNames(string $institutionId, array $names): void;

    /**
     * @param  list<string>  $institutionIds
     */
    public function verifyPending(array $institutionIds): void;

    public function changeStatus(string $institutionId, string $status): void;

    public function detachVerifier(string $accountId): void;

    /**
     * @param  list<string>  $institutionIds
     */
    public function restoreVerifier(string $accountId, array $institutionIds): void;

    public function syncSlugsForName(string $name, ?string $exceptInstitutionId = null): bool;

    public function syncSlug(string $institutionId): bool;

    public function recordImportExclusion(string $source, string $externalRef, string $institutionId): void;

    public function enforceSinglePrimaryName(string $nameId, string $institutionId, bool $isPrimary): void;

    /**
     * Delete institutions through the owning feature; returns the deleted count.
     *
     * @param  list<string>  $institutionIds
     */
    public function delete(array $institutionIds): int;
}
