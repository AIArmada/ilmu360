<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use App\Models\PassportUser;
use App\Models\User;

final class McpAuthenticatedUserResolver
{
    public function resolve(mixed $actor): ?User
    {
        if ($actor instanceof User) {
            return $actor;
        }

        if (! $actor instanceof PassportUser) {
            return null;
        }

        $user = User::query()->find($actor->getAuthIdentifier());

        if (! $user instanceof User) {
            return null;
        }

        return $user;
    }
}
