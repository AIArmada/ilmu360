<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

use App\Shared\Auth\AccountToken;

/**
 * Administrative account mutations for the Filament user admin surface.
 * Transport adapters pass Form data; Identity owns the account write.
 */
interface AccountAdministration
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): string;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $accountId, array $attributes): void;

    /**
     * @return list<AccountToken>
     */
    public function tokens(string $accountId): array;

    public function createToken(string $accountId, string $name): string;

    public function revokeToken(string $accountId, int|string $tokenId): bool;
}
