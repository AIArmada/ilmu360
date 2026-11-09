<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/**
 * Canonical vocabulary for global (team-less) application roles.
 *
 * Scoped membership roles are owned by the Membership capability and are not
 * part of this vocabulary.
 */
final class GlobalRole
{
    public const string SUPER_ADMIN = 'super_admin';

    public const string ADMIN = 'admin';

    public const string MODERATOR = 'moderator';
}
