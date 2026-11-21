<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Fortify;

use App\Features\Identity\Actions\RegisterNewAccountAction;
use App\Models\User;
use App\Platform\Auth\PasswordValidationRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final readonly class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private RegisterNewAccountAction $registration) {}

    /** @param array<string, mixed> $input */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'required_without:phone',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'phone' => [
                'nullable',
                'required_without:email',
                'string',
                'max:20',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return $this->registration->handle($input);
    }
}
