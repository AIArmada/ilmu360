<?php

declare(strict_types=1);

namespace App\Shared\Contributions;

/**
 * Current contribution state projected from an entity.
 *
 * Contributions computes `original_data` for update requests from the target
 * entity's current state. The projection stays with the conventional
 * ContributionEntityMutationService while the owning features still expose
 * their state through it; this contract lets the Contributions feature consume
 * the projection without importing the root adapter.
 */
interface ContributionEntityState
{
    /**
     * @return array<string, mixed>
     */
    public function stateFor(string $entityType, string $entityId): array;

    /**
     * @return array{accepts_partial_updates: bool, fields: list<array<string, mixed>>, conditional_rules: list<array<string, mixed>>, direct_edit_media_fields: list<string>}
     */
    public function contractForId(string $entityType, string $entityId): array;
}
