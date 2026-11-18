<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

interface ResourceWriter
{
    public function key(): string;

    public function surface(): ResourceSurface;

    /** @return array<string, mixed> */
    public function schema(ResourceActor $actor, string $operation, ?string $recordId): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validateAndPreview(ResourceActor $actor, string $operation, ?string $recordId, array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function write(ResourceActor $actor, string $operation, ?string $recordId, array $payload): array;
}
