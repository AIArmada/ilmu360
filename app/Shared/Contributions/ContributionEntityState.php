<?php

declare(strict_types=1);

namespace App\Shared\Contributions;

use Illuminate\Database\Eloquent\Model;

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
    public function stateFor(Model $entity): array;
}
