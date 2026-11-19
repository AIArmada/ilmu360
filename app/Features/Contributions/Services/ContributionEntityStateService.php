<?php

declare(strict_types=1);

namespace App\Features\Contributions\Services;

use App\Features\Contributions\Api\ContributionEntityState;
use App\Platform\Contributions\ContributionProjectionRegistry;
use App\Shared\Contributions\ContributionProjection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Neutral dispatcher for owner-published contribution projections.
 *
 * Entity form contracts, current state and update validation live with the
 * feature that owns the entity. This class only resolves the persisted morph
 * alias and delegates to the matching projection, so it never imports an
 * owner's models, workflows or per-entity schema.
 */
final readonly class ContributionEntityStateService implements ContributionEntityState
{
    public function __construct(private ContributionProjectionRegistry $projections) {}

    public function contractForId(string $entityType, string $entityId): array
    {
        return $this->projection($entityType)->contractFor($entityId);
    }

    public function stateFor(string $entityType, string $entityId): array
    {
        return $this->projection($entityType)->stateFor($entityId);
    }

    /** @return array<string, mixed> */
    public function stateForEntity(Model $entity): array
    {
        return $this->stateFor($entity->getMorphClass(), (string) $entity->getKey());
    }

    /** @return array<string, mixed> */
    public function contractFor(Model $entity): array
    {
        return $this->projection($entity->getMorphClass())->contractFor((string) $entity->getKey());
    }

    /** @return array<string, mixed> */
    public function updateRulesFor(Model $entity): array
    {
        return $this->projection($entity->getMorphClass())->updateRulesFor((string) $entity->getKey());
    }

    private function projection(string $entityType): ContributionProjection
    {
        foreach ($this->projections->all() as $projection) {
            if ($projection->supports($entityType)) {
                return $projection;
            }
        }

        throw new RuntimeException('Unsupported contribution entity type.');
    }
}
