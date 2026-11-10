<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Write boundary for Directory records.
 *
 * Administrative writes (create/update/delete/status) authorize the supplied
 * actor through the owning resource policy. Contribution composition
 * operations (`apply`, `syncRelations`) preserve the caller's existing
 * approval authorization; pass an actor to have it checked as an update.
 */
interface DirectoryCommands
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(DirectoryRecordType $type, array $attributes, string $actorId): DirectoryDetail;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(DirectoryRecordType $type, string $recordId, array $attributes, string $actorId): DirectoryDetail;

    public function delete(DirectoryRecordType $type, string $recordId, string $actorId): void;

    /**
     * Apply a staged contribution payload to an existing record.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> Changed attributes.
     */
    public function apply(DirectoryRecordType $type, string $recordId, array $attributes, ?string $actorId = null): array;

    public function changeStatus(DirectoryRecordType $type, string $recordId, string $status, string $actorId): DirectoryDetail;

    /**
     * @param  array<string, mixed>  $relations
     */
    public function syncRelations(DirectoryRecordType $type, string $recordId, array $relations, ?string $actorId = null): void;
}
