<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Institution;
use App\Shared\Auth\AccountActor;
use App\Shared\Directory\Enums\InstitutionStatus;
use App\Shared\Membership\MembershipAuthorization;
use App\Shared\Membership\MembershipSubjectType;

class InstitutionPolicy
{
    public function __construct(private readonly MembershipAuthorization $authorization) {}

    public function viewAny(?AccountActor $user): bool
    {
        return true;
    }

    public function view(?AccountActor $user, Institution $institution): bool
    {
        if (in_array($institution->status, InstitutionStatus::publiclyVisible(), true)) {
            return true;
        }

        if (! $user instanceof AccountActor) {
            return false;
        }

        if ($user->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return true;
        }

        return $this->authorization->can(
            $user->actorId(),
            'institution.view',
            MembershipSubjectType::Institution,
            (string) $institution->getKey(),
        );
    }

    public function create(AccountActor $user): bool
    {
        return true;
    }

    public function update(AccountActor $user, Institution $institution): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return true;
        }

        return $this->authorization->can(
            $user->actorId(),
            'institution.update',
            MembershipSubjectType::Institution,
            (string) $institution->getKey(),
        );
    }

    public function delete(AccountActor $user, Institution $institution): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $this->authorization->can(
            $user->actorId(),
            'institution.delete',
            MembershipSubjectType::Institution,
            (string) $institution->getKey(),
        );
    }

    public function deleteAny(AccountActor $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function manageMembers(AccountActor $user, Institution $institution): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $this->authorization->can(
            $user->actorId(),
            'institution.manage-members',
            MembershipSubjectType::Institution,
            (string) $institution->getKey(),
        );
    }

    public function manageDonationChannels(AccountActor $user, Institution $institution): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $this->authorization->can(
            $user->actorId(),
            'institution.manage-donation-channels',
            MembershipSubjectType::Institution,
            (string) $institution->getKey(),
        );
    }
}
