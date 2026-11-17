<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Features\Identity\Api\SignupAttributionWorkflow;
use App\Models\User;
use App\Shared\Auth\AccountAccess;
use App\Shared\Auth\SignupAttributionContextResolver;
use App\Shared\ShareTracking\SignupRecorder;
use App\Shared\Signals\SignalRecorder;
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
            // Identity's canonical account access adapter owns the user write.
            // Keep the surrounding transaction for attribution and signal atomicity.
            $profile = app(AccountAccess::class)->create([
                'name' => $input['name'],
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'password' => $input['password'],
            ]);
            $user = User::query()->findOrFail($profile->id);

            app(SignupAttributionWorkflow::class)->recordForNewAccount(
                (string) $user->getKey(),
                app(SignupAttributionContextResolver::class)->resolve(request()),
                true,
            );
            app(SignupRecorder::class)->recordSignup((string) $user->getKey(), request());
            app(SignalRecorder::class)->record(
                eventName: 'auth.signup.completed',
                eventCategory: 'auth',
                actorId: (string) $user->getKey(),
                properties: [
                    'has_email' => filled($user->email),
                    'has_phone' => filled($user->phone),
                ],
                request: request(),
            );

            return $user;
        });
    }
}
