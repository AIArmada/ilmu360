<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Shared\Auth\AccountAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsAction;

final class RevokeCurrentApiTokenAction
{
    use AsAction;

    public function __construct(private AccountAccess $accounts) {}

    public function handle(Authenticatable $account): void
    {
        $this->accounts->revokeCurrentToken($account);
    }
}
