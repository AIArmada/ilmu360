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
