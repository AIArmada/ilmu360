<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Auth\AccountActor;

interface MemberPermissions
{
    public function hasAnyInstitutionPermission(AccountActor $user, string $permission): bool;

    public function hasAnyEventPermission(AccountActor $user, string $permission): bool;

    public function hasAnyPersonPermission(AccountActor $user, string $permission): bool;

    public function hasAnyOrganizationPermission(AccountActor $user, string $permission): bool;

    public function hasAnyReferencePermission(AccountActor $user, string $permission): bool;
}
