<?php

declare(strict_types=1);

namespace App\Features\Organizations\Api;

/**
 * Scalar snapshot of one organization member.
 */
final readonly class OrganizationMember
{
    public function __construct(
        public string $accountId,
        public string $name,
        public ?string $email,
        public string $role,
    ) {}
}
