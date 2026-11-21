<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Api\AccountAccess;
use App\Features\Identity\Api\NewAccountAttribution;
use App\Features\Identity\Api\SignupAttributionContextResolver;
use App\Features\ShareTracking\Api\SignupRecorder;
use App\Models\User;
use App\Shared\ShareTracking\ShareRequestContext;
use App\Shared\Signals\SignalRecorder;
use App\Platform\Auth\PasswordValidationRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

/**
 * Owns the atomic signup, attribution and share-credit workflow.
 */
final readonly class RegisterNewAccountAction
{
    use PasswordValidationRules;
    public function __construct(
        private AccountAccess $accounts,
        private NewAccountAttribution $attribution,
        private SignupAttributionContextResolver $attributionContext,
        private SignupRecorder $shareSignups,
        private SignalRecorder $signals,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable', 'required_without:phone', 'string', 'email', 'max:255',
                Rule::unique(User::class),
            ],
            'phone' => [
                'nullable', 'required_without:email', 'string', 'max:20',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $profile = $this->accounts->create([
                'name' => $input['name'],
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'password' => $input['password'],
            ]);
            $user = User::query()->findOrFail($profile->id);

            $this->attribution->recordForNewAccount(
                (string) $user->getKey(),
                $this->attributionContext->resolve(),
                true,
            );
            $this->shareSignups->recordSignup((string) $user->getKey(), ShareRequestContext::current());
            $this->signals->record(
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
