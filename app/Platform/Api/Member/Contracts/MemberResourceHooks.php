<?php

declare(strict_types=1);

namespace App\Platform\Api\Member\Contracts;

use App\Features\Membership\Api\MemberPermissions;
use App\Features\Membership\Api\MembershipDirectory;
use App\Shared\Auth\AccountActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface MemberResourceHooks
{
    /** @return list<class-string> */
    public function resources(): array;

    /** @param class-string $resourceClass */
    public function supports(string $resourceClass): bool;

    /** @param class-string $resourceClass */
    public function canAccess(string $resourceClass, AccountActor $user, MembershipDirectory $memberships): ?bool;

    /**
     * @param  class-string  $resourceClass
     * @return class-string|null
     */
    public function adminResourceClass(string $resourceClass): ?string;

    /** @param class-string $resourceClass */
    public function canWriteResource(string $resourceClass, AccountActor $user, MemberPermissions $permissions): ?bool;

    /** @param class-string $resourceClass */
    public function canWriteRecord(string $resourceClass, AccountActor $user, Model $record): ?bool;

    /**
     * @param  class-string  $resourceClass
     * @return list<string>
     */
    public function extraRelations(string $resourceClass): array;

    /**
     * @param  class-string  $resourceClass
     * @return list<array{key: string, label: string, type: string, options?: array<string, string>}>
     */
    public function filters(string $resourceClass): array;

    /**
     * Apply owner-specific list filters. Return true when this hook owns the
     * filters for the resource, so the caller stops looking for another owner.
     *
     * @param  Builder<Model>  $query
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, string $resourceClass, array $filters): bool;

    /**
     * Owner-specific MCP image-upload descriptors, or [] when the owner does
     * not publish image upload tools for this resource.
     *
     * @param  class-string  $resourceClass
     * @return array<string, array<string, mixed>>
     */
    public function imageUploadMcpTools(string $resourceClass, string $key): array;

    /**
     * Owner-specific image upload tool slots this surface advertises. Slots
     * not supplied for a resource are emitted as null placeholders so the
     * manifest shape stays stable across resources.
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
     * @param  class-string  $resourceClass
     * @return Builder<Model>|null
     */
    public function queryFor(string $resourceClass): ?Builder;

    /** @return array<string, mixed>|null */
    public function recordAttributes(Model $record): ?array;

    public function recordTitle(Model $record): ?string;

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function augmentAttributes(Model $record, array $attributes): array;
}
