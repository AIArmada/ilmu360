<?php

namespace App\Features\Registration\Policies;

use AIArmada\Events\Contracts\EventRefundEligibility;
use App\Features\Events\Models\Event;
use App\Features\Membership\Api\MemberPermissions;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Registration\Models\Registration;
use App\Models\User;

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

        $memberPermissions = app(MemberPermissions::class);

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
        if ($registration->isForUser($user)) {
            return true;
        }

        $memberPermissions = app(MemberPermissions::class);

        $event = $registration->event;
        if ($event instanceof Event && $memberPermissions->canSubject($user, 'event.view-registrations', MemberSubjectType::Event, (string) $event->getKey())) {
            return true;
        }

        // Institution members can view registrations for their events
        if ($event instanceof Event && $event->institution) {
            return $memberPermissions->canSubject($user, 'event.view-registrations', MemberSubjectType::Institution, (string) $event->institution->getKey());
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
        if ($registration->isForUser($user)) {
            return true;
        }

        $memberPermissions = app(MemberPermissions::class);

        $event = $registration->event;
        if ($event instanceof Event && $memberPermissions->canSubject($user, 'event.export-registrations', MemberSubjectType::Event, (string) $event->getKey())) {
            return true;
        }

        // Institution admins can update registrations
        if ($event instanceof Event && $event->institution) {
            return $memberPermissions->canSubject($user, 'event.export-registrations', MemberSubjectType::Institution, (string) $event->institution->getKey());
        }

        return false;
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Only the purchaser may request a normal event refund. Organizers use
     * the event-level manageAdmissions permission for exceptional refunds.
     */
    public function requestRefund(User $user, Registration $registration): bool
    {
        $event = $registration->event;

        return $event instanceof Event
            && app(EventRefundEligibility::class)->canSelfServiceRefund(
                $user,
                (string) $event->getKey(),
                (string) $registration->getKey(),
            );
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

        $memberPermissions = app(MemberPermissions::class);

        return $memberPermissions->hasAnyInstitutionPermission($user, 'event.export-registrations')
            || $memberPermissions->hasAnyEventPermission($user, 'event.export-registrations');
    }
}
