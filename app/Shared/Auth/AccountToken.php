<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use DateTimeImmutable;

/** Immutable API token summary for account administration screens. */
final readonly class AccountToken
{
    public function __construct(
        public int|string $id,
        public string $name,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastUsedAt,
    ) {}
}
