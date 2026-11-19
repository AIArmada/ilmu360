<?php

declare(strict_types=1);

namespace App\Platform\Api\Admin\Contracts;

interface AdminResourceHooks
{
    /** @param class-string $resourceClass */
    public function supports(string $resourceClass): bool;

    /**
     * @param  class-string  $resourceClass
     * @return list<array{key: string, label: string, type: string, options?: array<string, string>}>
     */
    public function filters(string $resourceClass): array;

    /**
     * Apply owner-specific list filters. Return true when this hook owns the
     * filters for the resource, so the caller stops looking for another owner.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(\Illuminate\Database\Eloquent\Builder $query, string $resourceClass, array $filters): bool;

    /**
     * Owner-specific MCP image-upload descriptors, or [] when the owner does
     * not publish image upload tools for this resource.
     *
     * @param  class-string  $resourceClass
     * @return array<string, array<string, mixed>>
     */
    public function imageUploadMcpTools(string $resourceClass, string $key): array;

    /**
     * Owner-specific image upload tool slots this surface advertises.
     * Slots not supplied for a resource are emitted as null placeholders so
     * the manifest shape stays stable across resources.
     *
     * @return list<string>
     */
    public function imageUploadMcpToolKeys(): array;

    /**
     * @param  class-string  $resourceClass
     * @return array<string, mixed>|null
     */
    public function dateSemantics(string $resourceClass): ?array;

    /**
     * Owner-specific record payload, or null to use the generic serializer.
     *
     * @return array<string, mixed>|null
     */
    public function recordAttributes(\Illuminate\Database\Eloquent\Model $record): ?array;

    public function recordTitle(\Illuminate\Database\Eloquent\Model $record): ?string;

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function augmentAttributes(\Illuminate\Database\Eloquent\Model $record, array $attributes): array;
}
