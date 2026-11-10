<?php

declare(strict_types=1);

namespace App\Features\Identity\Services;

use App\Features\Identity\Api\AccountAdministration;
use App\Shared\Auth\AccountSettingsStore;

final readonly class AccountAdministrationService implements AccountAdministration
{
    public function __construct(private AccountSettingsStore $accounts) {}

    public function create(array $attributes): string
    {
        return $this->accounts->createAdministrative($attributes);
    }

    public function update(string $accountId, array $attributes): void
    {
        $this->accounts->updateAdministrative($accountId, $attributes);
    }

    public function tokens(string $accountId): array
    {
        return $this->accounts->tokens($accountId);
    }

    public function createToken(string $accountId, string $name): string
    {
        return $this->accounts->createToken($accountId, $name);
    }

    public function revokeToken(string $accountId, int|string $tokenId): bool
    {
        return $this->accounts->revokeToken($accountId, $tokenId);
    }
}
