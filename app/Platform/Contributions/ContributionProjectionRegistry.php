<?php

declare(strict_types=1);

namespace App\Platform\Contributions;

use App\Shared\Contributions\ContributionProjection;

final class ContributionProjectionRegistry
{
    /** @var list<ContributionProjection> */
    private array $projections = [];

    public function register(ContributionProjection $projection): void
    {
        if (! in_array($projection, $this->projections, true)) {
            $this->projections[] = $projection;
        }
    }

    /** @return list<ContributionProjection> */
    public function all(): array
    {
        return $this->projections;
    }
}
