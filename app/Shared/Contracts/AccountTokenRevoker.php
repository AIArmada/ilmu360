<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

interface AccountTokenRevoker
{
    public function revokeToken(string $accountId, int|string $tokenId): bool;
}
