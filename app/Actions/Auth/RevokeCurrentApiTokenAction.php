<?php

namespace App\Actions\Auth;

use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

final class RevokeCurrentApiTokenAction
{
    use AsAction;

    public function handle(User $user): void
    {
        $token = $user->currentAccessToken();

        if (method_exists($token, 'delete')) {
            $token->delete();
        }
    }
}
