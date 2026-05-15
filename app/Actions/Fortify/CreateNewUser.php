<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Services\Signals\ProductSignalsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
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

        return DB::transaction(function () use ($input) {
            $user = new User;

            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'password' => $input['password'],
            ])->save();

            app(CreateTeam::class)->handle($user, $user->name."'s Team", isPersonal: true);

            app(ShareTrackingService::class)->recordSignup($user, request());
            app(ProductSignalsService::class)->recordSignup($user, request());

            return $user;
        });
    }
}
