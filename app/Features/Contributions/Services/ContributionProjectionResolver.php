<?php

declare(strict_types=1);

namespace App\Features\Contributions\Services;

use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Events\Api\ContributionProjection as EventContributionProjection;
use App\Features\Institutions\Api\ContributionProjection as InstitutionContributionProjection;
use App\Features\Persons\Api\ContributionProjection as PersonContributionProjection;
use App\Features\References\Api\ContributionProjection as ReferenceContributionProjection;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Resolves the owner-published contribution projection for a persisted morph alias.
 *
 * Contributions owns contribution requests, not the form contract, state or
 * update validation of another entity. Each subject owner publishes its own
 * ContributionProjection Api contract; this resolver only maps the persisted
 * subject alias to the owning contract and lets the container supply the
 * owner's implementation, so no Platform or Shared class owns the dispatch.
 */
final readonly class ContributionProjectionResolver
{
    public function __construct(private Container $container) {}

    public function resolve(string $entityType): EventContributionProjection|InstitutionContributionProjection|PersonContributionProjection|ReferenceContributionProjection
    {
        $projection = match (ContributionSubjectType::tryFrom($entityType)) {
            ContributionSubjectType::Event => EventContributionProjection::class,
            ContributionSubjectType::Institution => InstitutionContributionProjection::class,
            ContributionSubjectType::Person => PersonContributionProjection::class,
            ContributionSubjectType::Reference => ReferenceContributionProjection::class,
            default => throw new RuntimeException('Unsupported contribution entity type.'),
        };

        $resolved = $this->container->make($projection);

        if (
            ! $resolved instanceof EventContributionProjection
            && ! $resolved instanceof InstitutionContributionProjection
            && ! $resolved instanceof PersonContributionProjection
            && ! $resolved instanceof ReferenceContributionProjection
        ) {
            throw new RuntimeException('Contribution projection contract resolved to an unsupported implementation.');
        }

        return $resolved;
    }
}
