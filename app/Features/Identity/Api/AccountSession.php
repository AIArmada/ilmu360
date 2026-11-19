<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

/** Issued bearer-token session for an authenticated API account. */
final readonly class AccountSession
{
    public function __construct(
        public string $accountId,
        public string $accessToken,
    ) {}
}
