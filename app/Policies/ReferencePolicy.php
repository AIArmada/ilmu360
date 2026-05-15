<?php

namespace App\Policies;

use App\Models\Reference;
use App\Models\User;
use App\Support\Authz\MemberPermissionGate;

class ReferencePolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Reference $reference): bool
    {
        if ($reference->status === 'verified' && $reference->is_active) {
            return true;
        }

        if (! $user instanceof User) {
            return false;
        }

        if ($user->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return true;
        }

        return app(MemberPermissionGate::class)->canReference($user, 'reference.view', $reference);
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

        return app(MemberPermissionGate::class)->canReference($user, 'reference.update', $reference);
    }

    public function delete(User $user, Reference $reference): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return app(MemberPermissionGate::class)->canReference($user, 'reference.delete', $reference);
    }

    public function manageMembers(User $user, Reference $reference): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return app(MemberPermissionGate::class)->canReference($user, 'reference.manage-members', $reference);
    }

    public function approve(User $user, Reference $reference): bool
    {
        if ($user->hasAnyRole(['super_admin', 'moderator'])) {
            return true;
        }

        return app(MemberPermissionGate::class)->canReference($user, 'reference.approve', $reference);
    }
}
