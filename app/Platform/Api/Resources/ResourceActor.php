<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

final readonly class ResourceActor
{
    public function __construct(
        public string $accountId,
        public ResourceSurface $surface,
    ) {}
}
