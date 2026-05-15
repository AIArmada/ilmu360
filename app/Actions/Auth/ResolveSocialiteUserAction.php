<?php

namespace App\Actions\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveSocialiteUserAction
{
    use AsAction;

    /**
     * @return array{user: User, created_account: bool}
     */
    public function handle(string $provider, SocialiteUser $socialUser): array
    {
        $account = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($account) {
            $account->update([
                'avatar_url' => $socialUser->getAvatar(),
            ]);

            $user = $account->user;
            $createdAccount = false;
        } else {
            $user = User::query()->where('email', $socialUser->getEmail())->first();
            $createdAccount = false;

            if (! $user instanceof User) {
                $user = User::query()->create([
                    'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
                    'email' => $socialUser->getEmail(),
                    'email_verified_at' => now(),
                ]);
                $createdAccount = true;
            }

            SocialAccount::query()->updateOrCreate([
                'user_id' => $user->id,
                'provider' => $provider,
            ], [
                'provider_id' => $socialUser->getId(),
                'avatar_url' => $socialUser->getAvatar(),
            ]);
        }

        if (! $user->hasVerifiedEmail() && filled($socialUser->getEmail())) {
            $user->markEmailAsVerified();
        }

        return [
            'user' => $user,
            'created_account' => $createdAccount,
        ];
    }
}
