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
use Illuminate\Support\Facades\DB;

/**
 * Owns the atomic signup, attribution and share-credit workflow.
 */
final readonly class RegisterNewAccountAction
{
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
