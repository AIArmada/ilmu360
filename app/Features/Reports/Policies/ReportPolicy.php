<?php

declare(strict_types=1);

namespace App\Features\Reports\Policies;

use App\Features\Reports\Models\Report;
use App\Shared\Auth\AccountActor;
use App\Shared\Feedback\FeedbackActor;

class ReportPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(AccountActor $user, Report $report): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return true;
        }

        return $report->reporter_id === $user->actorId();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(?FeedbackActor $user): bool
    {
        return $user instanceof FeedbackActor
            && ($user->hasAnyRole(['super_admin', 'admin', 'moderator']) || $user->canSubmitDirectoryFeedback());
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(AccountActor $user, Report $report): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    /**
     * Determine whether the user can resolve/dismiss the report.
     */
    public function resolve(AccountActor $user, Report $report): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(AccountActor $user, Report $report): bool
    {
        return $user->hasAnyRole(['super_admin']);
    }

    public function deleteAny(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin']);
    }
}
