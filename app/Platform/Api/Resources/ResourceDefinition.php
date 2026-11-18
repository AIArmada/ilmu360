<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

interface ResourceDefinition
{
    public function surface(): ResourceSurface;

    public function key(): string;

    /** @return array<string, mixed> */
    public function metadata(ResourceActor $actor): array;

    /** @return list<array<string, mixed>> */
    public function filters(ResourceActor $actor): array;

    public function canAccess(ResourceActor $actor): bool;

    public function canWrite(ResourceActor $actor, ?string $recordId = null): bool;
}
