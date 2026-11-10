<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/**
 * Raw account attributes plus profile facts for transport presentation.
 *
 * The attribute map keeps the established authenticated-user payload shape
 * without exporting the mutable user model across the Identity boundary.
 */
final readonly class AccountRecord
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $roles
     */
    public function __construct(
        public AccountProfile $profile,
        public array $attributes,
        public array $roles,
    ) {}
}
