<?php

declare(strict_types=1);

namespace App\Platform\Api\Member\Contracts;

use App\Features\Membership\Api\MemberPermissions;
use App\Shared\Auth\AccountActor;
use App\Shared\Membership\MembershipDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface MemberResourceHooks
{
    /** @return list<class-string> */
    public function resources(): array;

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
