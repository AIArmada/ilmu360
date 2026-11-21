<?php

declare(strict_types=1);

namespace App\Features\Places\Actions;

use App\Features\Places\Api\InstitutionSpaceLinks;

final readonly class DeleteSpaceInstitutionLinksAction
{
    public function __construct(private InstitutionSpaceLinks $links) {}

    public function handle(string $spaceId): void
    {
        $this->links->deleteForSpace($spaceId);
    }
}
