<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AppFeedback;
use App\Models\User;

class AppFeedbackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasGlobalAdminAccess();
    }

    public function view(User $user, AppFeedback $feedback): bool
    {
        return $user->hasGlobalAdminAccess();
    }

    public function viewOwn(User $user, AppFeedback $feedback): bool
    {
        return $feedback->reporter_id === $user->getKey();
    }

    public function create(?User $user): bool
    {
        return $user instanceof User && $user->canSubmitAppFeedback();
    }

    public function downloadEvidence(User $user, AppFeedback $feedback): bool
    {
        return $this->viewOwn($user, $feedback) || $this->view($user, $feedback);
    }

    public function reply(User $user, AppFeedback $feedback): bool
    {
        return $this->viewOwn($user, $feedback) || $user->hasGlobalAdminAccess();
    }

    public function transition(User $user, AppFeedback $feedback): bool
    {
        return $user->hasGlobalAdminAccess();
    }

    public function updateInternalNotes(User $user, AppFeedback $feedback): bool
    {
        return $user->hasGlobalAdminAccess();
    }

    public function escalateToGitHub(User $user, AppFeedback $feedback): bool
    {
        return $user->hasGlobalAdminAccess();
    }

    public function linkGitHubIssue(User $user, AppFeedback $feedback): bool
    {
        return $user->hasGlobalAdminAccess();
    }

    public function update(User $user, AppFeedback $feedback): bool
    {
        return false;
    }

    public function delete(User $user, AppFeedback $feedback): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, AppFeedback $feedback): bool
    {
        return false;
    }

    public function forceDelete(User $user, AppFeedback $feedback): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
