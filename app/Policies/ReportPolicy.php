<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

class ReportPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Report $report): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return true;
        }

        return $report->reporter_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(?User $user): bool
    {
        return $user instanceof User
            && ($user->hasAnyRole(['super_admin', 'admin', 'moderator']) || $user->canSubmitDirectoryFeedback());
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Report $report): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    /**
     * Determine whether the user can resolve/dismiss the report.
     */
    public function resolve(User $user, Report $report): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Report $report): bool
    {
        return $user->hasRole('super_admin');
    }
}
