<?php

namespace App\Policies;

use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\States\EventStatus\Draft;
use App\Support\Authz\MemberPermissionGate;

class EventPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(?User $user): bool
    {
        return true; // Public can view events list
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(?User $user, Event $event): bool
    {
        // Public events are viewable by anyone
        if ($event->visibility === EventVisibility::Public && $event->isPubliclyReachable()) {
            return true;
        }

        // Unlisted events are viewable by direct link
        if ($event->visibility === EventVisibility::Unlisted && $event->isPubliclyReachable()) {
            return true;
        }

        // Private events require authorization
        if (! $user instanceof User) {
            return false;
        }

        // Moderators and admins can view all
        if ($user->hasAnyRole(['super_admin', 'moderator'])) {
            return true;
        }

        // Submitter can view their own submissions
        if ($event->submissions()->where('submitter_type', $user->getMorphClass())->where('submitter_id', $user->id)->exists()) {
            return true;
        }

        // A managed-event creator can continue working on their own draft.
        if ($this->isDraftCreatedBy($user, $event)) {
            return true;
        }

        // Use hybrid permission check (event members + organizer/institution scope)
        return $event->userCanView($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true; // Anyone can submit an event
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Event $event): bool
    {
        // Admins and moderators can update any event
        if ($user->hasAnyRole(['super_admin', 'moderator'])) {
            return true;
        }

        // Submitter can update their draft/pending submissions
        if ($event->submissions()->where('submitter_type', $user->getMorphClass())->where('submitter_id', $user->id)->exists() && in_array((string) $event->status, ['draft', 'pending', 'needs_changes'])) {
            return true;
        }

        // A managed-event creator can continue working on their own draft.
        if ($this->isDraftCreatedBy($user, $event)) {
            return true;
        }

        // Responsible institution/person approvers may access pending public submissions.
        if ($event->userCanApprovePublicSubmission($user)) {
            return true;
        }

        // Use hybrid permission check (event members + organizer/institution scope)
        return $event->userCanManage($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Event $event): bool
    {
        // Super admins can delete anything
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Members of the organizing institution or person can delete events scoped to them
        if ($event->userHasScopedEventPermission($user, 'event.delete', includeEventScope: false)) {
            return true;
        }

        // For other users, only draft events can be deleted
        if ($event->status === null || ! $event->status->equals(Draft::class)) {
            return false;
        }

        // Use hybrid permission check for draft events (only organizer role, not co-organizer)
        return $event->userCanDelete($user);
    }

    /**
     * Determine whether the user can moderate the event.
     */
    public function moderate(User $user, Event $event): bool
    {
        return $user->hasAnyRole(['super_admin', 'moderator']);
    }

    /**
     * Determine whether the user can approve a pending public-submitted event.
     */
    public function approve(User $user, Event $event): bool
    {
        return $event->userCanApprovePublicSubmission($user);
    }

    /**
     * Determine whether the user can export registrations.
     */
    public function exportRegistrations(User $user, Event $event): bool
    {
        // Per B9d: exports require institution owner/admin role
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $event->userHasScopedEventPermission($user, 'event.export-registrations');
    }

    /**
     * Determine whether the user can manage event members.
     */
    public function manageMembers(User $user, Event $event): bool
    {
        if ($user->hasAnyRole(['super_admin', 'moderator'])) {
            return true;
        }

        return $event->userHasScopedEventPermission($user, 'event.manage-members');
    }

    public function publishChange(User $user, Event $event): bool
    {
        if ($user->hasAnyRole(['super_admin', 'moderator'])) {
            return true;
        }

        if ($event->userCanManage($user)) {
            return true;
        }

        $event->loadMissing('persons');
        $memberPermissionGate = app(MemberPermissionGate::class);

        return $event->persons->contains(
            fn (Person $person): bool => $memberPermissionGate->canPerson($user, 'event.update', $person)
        );
    }

    private function isDraftCreatedBy(User $user, Event $event): bool
    {
        return (string) $event->status === 'draft'
            && $event->created_by_type === $user->getMorphClass()
            && (string) $event->created_by_id === (string) $user->getKey();
    }
}
