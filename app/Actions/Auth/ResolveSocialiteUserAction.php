<?php

namespace App\Actions\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Socialite\AbstractUser;
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
        $providerEmailIsVerified = $this->providerEmailIsVerified($socialUser);

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
            $user = $providerEmailIsVerified && filled($socialUser->getEmail())
                ? User::query()->where('email', $socialUser->getEmail())->first()
                : null;
            $createdAccount = false;

            if (! $user instanceof User) {
                $user = User::query()->create([
                    'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
                    'email' => $providerEmailIsVerified ? $socialUser->getEmail() : null,
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

        if ($providerEmailIsVerified && ! $user->hasVerifiedEmail() && filled($user->email)) {
            $user->markEmailAsVerified();
        }

        return [
            'user' => $user,
            'created_account' => $createdAccount,
        ];
    }

    private function providerEmailIsVerified(SocialiteUser $socialUser): bool
    {
        if (! $socialUser instanceof AbstractUser) {
            return false;
        }

        $rawUser = $socialUser->getRaw();

        return ($rawUser['email_verified'] ?? null) === true
            || ($rawUser['verified_email'] ?? null) === true;
    }
}
