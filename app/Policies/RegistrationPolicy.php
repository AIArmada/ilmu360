<?php

namespace App\Policies;

use App\Models\Registration;
use App\Models\User;
use App\Support\Authz\MemberPermissionGate;

class RegistrationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Admins, moderators can view all registrations
        if ($user->hasAnyRole(['super_admin', 'moderator'])) {
            return true;
        }

        $memberPermissions = app(MemberPermissionGate::class);

        return $memberPermissions->hasAnyInstitutionPermission($user, 'event.view-registrations')
            || $memberPermissions->hasAnyEventPermission($user, 'event.view-registrations');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Registration $registration): bool
    {
        // Admins can view any registration
        if ($user->hasAnyRole(['super_admin', 'moderator'])) {
            return true;
        }

        // User can view their own registration
        if ($registration->user_id === $user->id) {
            return true;
        }

        $memberPermissions = app(MemberPermissionGate::class);

        $event = $registration->event;
        if ($event && $memberPermissions->canEvent($user, 'event.view-registrations', $event)) {
            return true;
        }

        // Institution members can view registrations for their events
        if ($event?->institution) {
            return $memberPermissions->canInstitution($user, 'event.view-registrations', $event->institution);
        }

        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Registration $registration): bool
    {
        // Admins can update any
        if ($user->hasAnyRole(['super_admin', 'moderator'])) {
            return true;
        }

        // User can cancel their own registration
        if ($registration->user_id === $user->id) {
            return true;
        }

        $memberPermissions = app(MemberPermissionGate::class);

        $event = $registration->event;
        if ($event && $memberPermissions->canEvent($user, 'event.export-registrations', $event)) {
            return true;
        }

        // Institution admins can update registrations
        if ($event?->institution) {
            return $memberPermissions->canInstitution($user, 'event.export-registrations', $event->institution);
        }

        return false;
    }

    /**
     * Determine whether the user can export registrations.
     * Per B9d: exports require institution owner/admin role and are audit logged.
     */
    public function export(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        $memberPermissions = app(MemberPermissionGate::class);

        return $memberPermissions->hasAnyInstitutionPermission($user, 'event.export-registrations')
            || $memberPermissions->hasAnyEventPermission($user, 'event.export-registrations');
    }
}
