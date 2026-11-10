<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Api\SocialAccounts;
use App\Shared\Auth\AccountAccess;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountProfile;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class ResolveSocialiteUserAction
{
    use AsAction;

    public function __construct(
        private SocialAccounts $socialAccounts,
        private AccountAccess $accounts,
        private AccountDirectory $directory,
    ) {}

    /**
     * @return array{profile: AccountProfile, created_account: bool}
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

            $profile = $this->directory->profile($account->accountId)
                ?? abort(404, 'The linked social account no longer exists.');
            $createdAccount = false;
        } else {
            $profile = $providerEmailIsVerified && filled($socialUser->getEmail())
                ? $this->accounts->findByEmail((string) $socialUser->getEmail())
                : null;
            $createdAccount = false;

            if (! $profile instanceof AccountProfile) {
                $profile = $this->accounts->create([
                    'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
                    'email' => $providerEmailIsVerified ? $socialUser->getEmail() : null,
                ]);
                $createdAccount = true;
            }

            $this->socialAccounts->link(
                $profile->id,
                $provider,
                (string) $socialUser->getId(),
                $socialUser->getAvatar(),
            );
        }

        if ($providerEmailIsVerified && ! $profile->emailVerified && is_string($profile->email) && $profile->email !== '') {
            $this->accounts->markEmailVerified($profile->id);
            $profile = $this->directory->profile($profile->id) ?? $profile;
        }

        return [
            'profile' => $profile,
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
