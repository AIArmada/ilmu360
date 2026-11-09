<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AppFeedback;
use App\Shared\Auth\AccountActor;

class AppFeedbackPolicy
{
    public function viewAny(AccountActor $user): bool
    {
        return $user->hasGlobalAdminAccess();
    }

    public function view(AccountActor $user, AppFeedback $feedback): bool
    {
        return $user->hasGlobalAdminAccess();
    }

    public function viewOwn(AccountActor $user, AppFeedback $feedback): bool
    {
        return $feedback->reporter_id === $user->actorId();
    }

    public function create(?AccountActor $user): bool
    {
        return $user !== null && $user->canSubmitAppFeedback();
    }

    public function downloadEvidence(AccountActor $user, AppFeedback $feedback): bool
    {
        return $this->viewOwn($user, $feedback) || $this->view($user, $feedback);
    }

    public function reply(AccountActor $user, AppFeedback $feedback): bool
    {
        return $this->viewOwn($user, $feedback) || $user->hasGlobalAdminAccess();
    }

    public function transition(AccountActor $user, AppFeedback $feedback): bool
    {
        return $user->hasGlobalAdminAccess();
    }

    public function updateInternalNotes(AccountActor $user, AppFeedback $feedback): bool
    {
        return $user->hasGlobalAdminAccess();
    }

    public function escalateToGitHub(AccountActor $user, AppFeedback $feedback): bool
    {
        return $user->hasGlobalAdminAccess();
    }

    public function linkGitHubIssue(AccountActor $user, AppFeedback $feedback): bool
    {
        return $user->hasGlobalAdminAccess();
    }

    public function update(AccountActor $user, AppFeedback $feedback): bool
    {
        return false;
    }

    public function delete(AccountActor $user, AppFeedback $feedback): bool
    {
        return false;
    }

    public function deleteAny(AccountActor $user): bool
    {
        return false;
    }

    public function restore(AccountActor $user, AppFeedback $feedback): bool
    {
        return false;
    }

    public function forceDelete(AccountActor $user, AppFeedback $feedback): bool
    {
        return false;
    }

    public function forceDeleteAny(AccountActor $user): bool
    {
        return false;
    }
}
