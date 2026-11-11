<?php

declare(strict_types=1);

namespace App\Features\Organizations\Api;

/**
 * Write boundary for organization lifecycle changes.
 *
 * Implementations delegate to the canonical package actions, which retain
 * authorization, transaction and lifecycle-hook behaviour.
 */
interface OrganizationCommands
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(string $actorId, array $attributes): OrganizationSummary;

    public function makePublic(string $organizationId, string $actorId): OrganizationSummary;

    public function makePrivate(string $organizationId, string $actorId): OrganizationSummary;

    public function suspend(string $organizationId, string $actorId): OrganizationSummary;

    public function restore(string $organizationId, string $actorId): OrganizationSummary;

    public function archive(string $organizationId, string $actorId): OrganizationSummary;

    public function transferOwnership(
        string $organizationId,
        string $actorId,
        string $newOwnerId,
    ): OrganizationSummary;
}
