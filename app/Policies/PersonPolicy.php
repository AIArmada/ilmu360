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

        return app(MemberPermissionGate::class)->canSpeaker($user, 'speaker.view', $person);
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

        return app(MemberPermissionGate::class)->canSpeaker($user, 'speaker.update', $person);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Person $person): bool
    {
        // Only super admins and person owners can delete
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return app(MemberPermissionGate::class)->canSpeaker($user, 'speaker.delete', $person);
    }

    /**
     * Determine whether the user can manage person members.
     */
    public function manageMembers(User $user, Person $person): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return app(MemberPermissionGate::class)->canSpeaker($user, 'speaker.manage-members', $person);
    }
}
