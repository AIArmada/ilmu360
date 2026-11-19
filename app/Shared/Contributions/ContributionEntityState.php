<?php

declare(strict_types=1);

namespace App\Shared\Contributions;

/**
 * Current contribution state projected from an entity.
 *
 * Contributions computes `original_data` for update requests from the target
 * entity's current state. The owning feature publishes the projection and the
 * Contributions dispatcher resolves it by persisted morph alias, so callers
 * never import entity models or private owner schema.
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
