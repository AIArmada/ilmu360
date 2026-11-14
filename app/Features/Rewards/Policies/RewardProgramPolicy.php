<?php

declare(strict_types=1);

namespace App\Features\Rewards\Policies;

use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardProgram;
use App\Shared\Auth\AccountActor;

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
    public function viewAny(?AccountActor $user): bool
    {
        return $user instanceof AccountActor;
    }

    public function view(?AccountActor $user, RewardProgram $program): bool
    {
        if (! $user instanceof AccountActor) {
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
            ->where('member_type', $user->actorType())
            ->where('member_id', $user->actorId())
            ->exists();
    }

    public function create(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function update(AccountActor $user, RewardProgram $program): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function publishVersion(AccountActor $user, RewardProgram $program): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function transitionStatus(AccountActor $user, RewardProgram $program): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function enroll(AccountActor $user, RewardProgram $program): bool
    {
        return $program->status === RewardProgramStatus::Active;
    }

    public function leave(AccountActor $user, RewardProgram $program): bool
    {
        return RewardEnrollment::query()
            ->where('program_id', $program->getKey())
            ->where('member_type', $user->actorType())
            ->where('member_id', $user->actorId())
            ->exists();
    }
}
