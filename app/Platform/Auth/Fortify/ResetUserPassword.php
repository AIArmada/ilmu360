<?php

namespace App\Platform\Auth\Fortify;

use App\Features\Identity\Api\AccountAccess;
use App\Models\User;
use App\Platform\Auth\PasswordValidationRules;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private readonly AccountAccess $accounts) {}

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $this->accounts->persistResetPassword(
            (string) $user->getKey(),
            $input['password'],
        );

        $user->refresh();
    }
}
