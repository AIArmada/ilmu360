<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

interface PersonMemberRoleChanges
{
    public function change(string $personId, string $accountId, string $role): void;
}
