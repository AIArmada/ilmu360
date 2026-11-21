<?php

declare(strict_types=1);

namespace App\Features\Places\Observers;

use App\Features\Places\Actions\DeleteSpaceInstitutionLinksAction;
use App\Features\Places\Models\Space;

final readonly class SpaceInstitutionLinkCleanupObserver
{
    public function __construct(private DeleteSpaceInstitutionLinksAction $cleanup) {}

    public function deleted(Space $space): void
    {
        $this->cleanup->handle((string) $space->getKey());
    }
}
