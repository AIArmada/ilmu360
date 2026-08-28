<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;
use App\Support\Authz\MemberPermissionGate;

class PersonPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(?User $user): bool
    {
        return true; // Public can view persons list
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(?User $user, Person $person): bool
    {
        // Verified persons are publicly viewable
        if ($person->status === 'verified') {
            return true;
        }

        if (! $user instanceof User) {
            return false;
        }

        // Admins can view any person
        if ($user->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return true;
        }

        return app(MemberPermissionGate::class)->canPerson($user, 'person.view', $person);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Any authenticated user can create a person profile
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Person $person): bool
    {
        // Admins can update any person
        if ($user->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return true;
        }

        // Owners, admins, and editors of the person record can update it directly.
        if ($person->members()->whereKey($user->getKey())->wherePivotIn('role', ['owner', 'admin', 'editor'])->exists()) {
            return true;
        }

        return app(MemberPermissionGate::class)->canPerson($user, 'person.update', $person);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Person $person): bool
    {
        // Super admins, person owners, and person admins can delete
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return app(MemberPermissionGate::class)->canPerson($user, 'person.delete', $person);
    }

    /**
     * Determine whether the user can manage person members.
     */
    public function manageMembers(User $user, Person $person): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return app(MemberPermissionGate::class)->canPerson($user, 'person.manage-members', $person);
    }

    /**
     * Determine whether the user can transfer ownership of the model.
     */
    public function transferOwnership(User $user, Person $person): bool
    {
        return app(MemberPermissionGate::class)->canPerson($user, 'person.transfer-ownership', $person);
    }
}
