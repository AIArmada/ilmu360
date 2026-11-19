<?php

declare(strict_types=1);

namespace App\Platform\Api\Admin\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

interface AdminMutationHooks
{
    /** @param class-string $resourceClass */
    public function supports(string $resourceClass): bool;

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>|null
     */
    public function schema(string $resourceClass, string $resourceKey, string $operation, ?Model $record, array $defaults): ?array;

    /**
     * @param  class-string  $resourceClass
     * @return array<string, mixed>|null
     */
    public function rules(string $resourceClass, bool $updating): ?array;

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalize(string $resourceClass, array $validated, ?Model $record): array;

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $validated
     */
    public function store(string $resourceClass, array $validated, User $actor): ?Model;

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $validated
     */
    public function update(string $resourceClass, Model $record, array $validated, User $actor): ?Model;

    /**
     * @param  class-string  $resourceClass
     * @return array<string, mixed>|null
     */
    public function defaultsForCreate(string $resourceClass): ?array;

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public function augmentDefaultsForRecord(Model $record, array $defaults): array;
}
