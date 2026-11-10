<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Api\SignupAttributionWorkflow;
use App\Shared\Auth\AccountAccess;
use App\Shared\Auth\AccountProfile;
use App\Shared\Auth\AccountSession;
use App\Shared\Auth\SignupAttributionContextResolver;
use App\Shared\Auth\SocialiteProviderConfiguration;
use App\Shared\ShareTracking\SignupRecorder;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class AuthenticateSocialiteApiUserAction
{
    use AsAction;

    public function __construct(
        private ResolveSocialiteUserAction $resolveSocialiteUserAction,
        private AccountAccess $accounts,
        private SignupAttributionWorkflow $signupAttribution,
        private SignupAttributionContextResolver $attributionContext,
        private SignupRecorder $signupRecorder,
        private SignalRecorder $signals,
    ) {}

    /**
     * @return array{profile: AccountProfile, session: AccountSession, created_account: bool}
     */
    public function handle(string $provider, string $providerAccessToken, string $deviceName, Request $request): array
    {
        if (! SocialiteProviderConfiguration::supportsTokenExchange($provider)) {
            throw ValidationException::withMessages([
                'provider' => [__('Google sign-in is not configured right now. Please try again later.')],
            ]);
        }

        try {
            /** @var AbstractProvider $socialiteProvider */
            $socialiteProvider = Socialite::driver($provider);

            $socialUser = $socialiteProvider
                ->stateless()
                ->userFromToken($providerAccessToken);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'access_token' => [__('Unable to authenticate. Please try again.')],
            ]);
        }

        // The signup source and its attributed-or-unattributed receipt
        // freeze in one transaction: a failure after user creation must
        // never leave a receipt-less account open to late credit.
        $result = DB::transaction(function () use ($provider, $socialUser, $request): array {
            $resolved = $this->resolveSocialiteUserAction->handle($provider, $socialUser);

            if ($resolved['created_account']) {
                $this->signupAttribution->recordForNewAccount(
                    $resolved['profile']->id,
                    $this->attributionContext->resolve($request),
                    true,
                );
                $this->signupRecorder->recordSignup($resolved['profile']->id, $request);
            }

            return $resolved;
        });

        $profile = $result['profile'];
        $session = new AccountSession(
            accountId: $profile->id,
            accessToken: $this->accounts->issueToken($profile->id, $deviceName),
        );

        $this->signals->record(
            eventName: 'auth.login',
            eventCategory: 'auth',
            actorId: $profile->id,
            properties: [
                'method' => $provider.'_api_token',
                'created_account' => $result['created_account'],
            ],
            request: $request,
        );

        return [
            'profile' => $profile,
            'session' => $session,
            'created_account' => $result['created_account'],
        ];
    }
}
