<?php

declare(strict_types=1);

namespace App\Policies;

use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardProgram;
use App\Models\User;

/**
 * Program authorization: staff manage, members read active/enrolled.
 *
 * - Staff (`super_admin`, `admin`) see and manage every program.
 * - Members see active programs plus any program they are enrolled in
 *   (paused history stays readable for accrued obligations).
 * - Guests see nothing: the catalog is a member surface.
 */
class RewardProgramPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user instanceof User;
    }

    public function view(?User $user, RewardProgram $program): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        if ($program->status === RewardProgramStatus::Active) {
            return true;
        }

        return RewardEnrollment::query()
            ->where('program_id', $program->getKey())
            ->where('member_type', $user->getMorphClass())
            ->where('member_id', $user->getKey())
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function update(User $user, RewardProgram $program): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function publishVersion(User $user, RewardProgram $program): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function transitionStatus(User $user, RewardProgram $program): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function enroll(User $user, RewardProgram $program): bool
    {
        return $program->status === RewardProgramStatus::Active;
    }

    public function leave(User $user, RewardProgram $program): bool
    {
        return RewardEnrollment::query()
            ->where('program_id', $program->getKey())
            ->where('member_type', $user->getMorphClass())
            ->where('member_id', $user->getKey())
            ->exists();
    }
}
