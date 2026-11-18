<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

interface ResourceReader extends ResourceDefinition
{
    /** @return array<string, mixed> */
    public function list(ResourceActor $actor, ResourceQuery $query): array;

    /** @return array<string, mixed> */
    public function show(ResourceActor $actor, string $recordId): array;

    /** @return array<string, mixed> */
    public function related(ResourceActor $actor, string $recordId, string $relation, ResourceQuery $query): array;

    /** @return array<string, mixed> */
    public function media(ResourceActor $actor, string $recordId): array;
}
