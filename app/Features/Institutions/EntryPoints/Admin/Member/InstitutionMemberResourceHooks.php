<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Admin\Member;

use App\Features\Membership\Api\MemberSubjectType;
use App\Platform\Api\Member\Contracts\MemberResourceHooks;
use App\Shared\Auth\AccountActor;
use App\Shared\Membership\MembershipDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class InstitutionMemberResourceHooks implements MemberResourceHooks
{
    public function resources(): array
    {
        return [\App\Features\Institutions\EntryPoints\Filament\Ahli\Resources\Institutions\InstitutionResource::class];
    }

    public function canAccess(string $resourceClass, AccountActor $user, MembershipDirectory $memberships): ?bool
    {
        if (! in_array($resourceClass, $this->resources(), true)) {
            return null;
        }

        return $memberships->subjectIdsFor($user->actorId(), MemberSubjectType::Institution) !== [];
    }

    public function adminResourceClass(string $resourceClass): ?string
    {
        return in_array($resourceClass, $this->resources(), true) ? \App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\InstitutionResource::class : null;
    }

    public function canWriteResource(string $resourceClass, AccountActor $user, \App\Features\Membership\Api\MemberPermissions $permissions): ?bool
    {
        if (! in_array($resourceClass, $this->resources(), true)) {
            return null;
        }

        return $permissions->hasAnyInstitutionPermission($user, 'institution.update');
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
