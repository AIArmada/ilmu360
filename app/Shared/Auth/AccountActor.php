<?php

declare(strict_types=1);

namespace App\Shared\Auth;

interface AccountActor
{
    /**
     * Stable account identifier used for ownership and audit attribution.
     */
    public function actorId(): string;

    public function hasGlobalAdminAccess(): bool;

    public function hasRole(string $role): bool;

    /**
     * @param  array<int, string>|string  ...$roles
     */
    public function hasAnyRole(array|string ...$roles): bool;
}
