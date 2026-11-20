<?php

declare(strict_types=1);

namespace App\Features\Contributions\Services;

use App\Features\Contributions\Api\ContributionEntityState;
use Illuminate\Database\Eloquent\Model;

/**
 * Neutral dispatcher for owner-published contribution projections.
 *
 * Entity form contracts, current state and update validation live with the
 * feature that owns the entity. This class only accepts the persisted morph
 * alias or model and delegates to the matching owner projection, so it never
 * imports an owner's models, workflows or per-entity schema.
 */
final readonly class ContributionEntityStateService implements ContributionEntityState
{
    public function __construct(private ContributionProjectionResolver $resolver) {}

    public function contractForId(string $entityType, string $entityId): array
    {
        return $this->resolver->resolve($entityType)->contractFor($entityId);
    }

    public function stateFor(string $entityType, string $entityId): array
    {
        return $this->resolver->resolve($entityType)->stateFor($entityId);
    }

    /** @return array<string, mixed> */
    public function stateForEntity(Model $entity): array
    {
        return $this->stateFor($entity->getMorphClass(), (string) $entity->getKey());
    }

    /** @return array<string, mixed> */
    public function contractFor(Model $entity): array
    {
        return $this->resolver->resolve($entity->getMorphClass())->contractFor((string) $entity->getKey());
    }

    /** @return array<string, mixed> */
    public function updateRulesFor(Model $entity): array
    {
        return $this->resolver->resolve($entity->getMorphClass())->updateRulesFor((string) $entity->getKey());
    }
}
