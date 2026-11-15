<?php

declare(strict_types=1);

namespace App\Shared\Auth;

interface AccountActor
{
    /**
     * Stable account identifier used for ownership and audit attribution.
     */
    public function actorId(): string;

    /**
     * Morph type used for account ownership and attribution.
     */
    public function actorType(): string;

    public function hasGlobalAdminAccess(): bool;

    /** Whether the account has a global role assignment that grants app-admin API entry. */
    public function hasApplicationAdminAccess(): bool;

    public function hasRole(string $role): bool;

    /**
     * @param  array<int, string>|string  ...$roles
     */
    public function hasAnyRole(array|string ...$roles): bool;
}
