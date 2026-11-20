<?php

namespace App\Features\References\Policies;

use App\Features\Membership\Api\MemberPermissions;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\References\Models\Reference;
use App\Models\User;

class ReferencePolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Reference $reference): bool
    {
        if ($reference->isPubliclyVisible()) {
            return true;
        }

        if (! $user instanceof User) {
            return false;
        }

        if ($user->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return true;
        }

        return app(MemberPermissions::class)->canSubject($user, 'reference.view', MemberSubjectType::Reference, (string) $reference->getKey());
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Reference $reference): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return true;
        }

        return app(MemberPermissions::class)->canSubject($user, 'reference.update', MemberSubjectType::Reference, (string) $reference->getKey());
    }

    public function delete(User $user, Reference $reference): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return app(MemberPermissions::class)->canSubject($user, 'reference.delete', MemberSubjectType::Reference, (string) $reference->getKey());
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function manageMembers(User $user, Reference $reference): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return app(MemberPermissions::class)->canSubject($user, 'reference.manage-members', MemberSubjectType::Reference, (string) $reference->getKey());
    }

    public function approve(User $user, Reference $reference): bool
    {
        if ($user->hasAnyRole(['super_admin', 'moderator'])) {
            return true;
        }

        return app(MemberPermissions::class)->canSubject($user, 'reference.approve', MemberSubjectType::Reference, (string) $reference->getKey());
    }
}
