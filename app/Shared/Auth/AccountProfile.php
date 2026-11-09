<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use DateTimeImmutable;

/**
 * Immutable read model for an account profile.
 *
 * Consumers outside Identity use this value instead of importing the mutable
 * user model when they only need identity facts.
 */
final readonly class AccountProfile
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public bool $emailVerified,
        public bool $phoneVerified,
        public string $locale,
        public string $timezone,
        public DateTimeImmutable $createdAt,
    ) {}
}
