<?php

declare(strict_types=1);

namespace App\Features\References\EntryPoints\Admin\Member;

use App\Features\Membership\Api\MembershipDirectory;
use App\Features\Membership\Api\MemberSubjectType;
use App\Platform\Api\Member\Contracts\MemberResourceHooks;
use App\Shared\Auth\AccountActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class ReferenceMemberResourceHooks implements MemberResourceHooks
{
    public function resources(): array
    {
        return [\App\Features\References\EntryPoints\Filament\Ahli\Resources\References\ReferenceResource::class];
    }

    public function canAccess(string $resourceClass, AccountActor $user, MembershipDirectory $memberships): ?bool
    {
        if (! in_array($resourceClass, $this->resources(), true)) {
            return null;
        }

        return $memberships->subjectIdsFor($user->actorId(), MemberSubjectType::Reference) !== [];
    }

    public function adminResourceClass(string $resourceClass): ?string
    {
        return in_array($resourceClass, $this->resources(), true) ? \App\Features\References\EntryPoints\Filament\Resources\References\ReferenceResource::class : null;
    }

    public function canWriteResource(string $resourceClass, AccountActor $user, \App\Features\Membership\Api\MemberPermissions $permissions): ?bool
    {
        if (! in_array($resourceClass, $this->resources(), true)) {
            return null;
        }

        return $permissions->hasAnyReferencePermission($user, 'reference.update');
    }

    public function canWriteRecord(string $resourceClass, AccountActor $user, Model $record): ?bool
    {
        return null;
    }

    /** @return list<string> */
    public function extraRelations(string $resourceClass): array
    {
        return [];
    }

    /** @return array<string, mixed>|null */
    public function dateSemantics(string $resourceClass): ?array
    {
        return null;
    }

    /** @return Builder<Model>|null */
    public function queryFor(string $resourceClass): ?Builder
    {
        return null;
    }

    public function recordAttributes(Model $record): ?array
    {
        return null;
    }

    public function recordTitle(Model $record): ?string
    {
        return null;
    }

    public function augmentAttributes(Model $record, array $attributes): array
    {
        return $attributes;
    }
}
