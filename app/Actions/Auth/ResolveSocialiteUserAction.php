<?php

namespace App\Actions\Auth;

use App\Features\Identity\Api\SocialAccounts;
use App\Models\User;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class ResolveSocialiteUserAction
{
    use AsAction;

    public function __construct(private SocialAccounts $socialAccounts) {}

    /**
     * @return array{user: User, created_account: bool}
     */
    public function handle(string $provider, SocialiteUser $socialUser): array
    {
        $providerEmailIsVerified = $this->providerEmailIsVerified($socialUser);

        $account = $this->socialAccounts->findByProvider($provider, (string) $socialUser->getId());

        if ($account !== null) {
            $this->socialAccounts->link(
                $account->accountId,
                $provider,
                (string) $socialUser->getId(),
                $socialUser->getAvatar(),
            );

            $user = User::query()->findOrFail($account->accountId);
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

            $this->socialAccounts->link(
                (string) $user->getKey(),
                $provider,
                (string) $socialUser->getId(),
                $socialUser->getAvatar(),
            );
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
