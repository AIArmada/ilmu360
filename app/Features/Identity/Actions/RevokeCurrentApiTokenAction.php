<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Api\AccountAccess;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\PersonalAccessToken;
use Lorisleiva\Actions\Concerns\AsAction;

final class RevokeCurrentApiTokenAction
{
    use AsAction;

    public function __construct(private AccountAccess $accounts) {}

    public function handle(Authenticatable $account): void
    {
        $this->accounts->revokeCurrentToken(
            (string) $account->getAuthIdentifier(),
            $this->currentTokenId($account),
        );
    }

    private function currentTokenId(Authenticatable $account): int|string|null
    {
        if (! $account instanceof User) {
            return null;
        }

        $token = $account->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $key = $token->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
