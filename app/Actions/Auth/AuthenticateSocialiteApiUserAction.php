<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\ShareTrackingService;
use App\Services\Signals\ProductSignalsService;
use App\Support\Auth\SocialiteProviderConfiguration;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class AuthenticateSocialiteApiUserAction
{
    use AsAction;

    public function __construct(
        private ResolveSocialiteUserAction $resolveSocialiteUserAction,
        private ProductSignalsService $productSignalsService,
        private ShareTrackingService $shareTrackingService,
    ) {}

    /**
     * @return array{user: User, access_token: string}
     */
    public function handle(string $provider, string $providerAccessToken, string $deviceName, Request $request): array
    {
        if (! SocialiteProviderConfiguration::supportsTokenExchange($provider)) {
            throw ValidationException::withMessages([
                'provider' => [__('Google sign-in is not configured right now. Please use email and password instead.')],
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

        $result = $this->resolveSocialiteUserAction->handle($provider, $socialUser);
        $user = $result['user'];
        $createdAccount = $result['created_account'];

        $accessToken = $user->createToken($deviceName)->plainTextToken;

        $this->productSignalsService->recordLogin($user, $request, $provider.'_api_token', $createdAccount);

        if ($createdAccount) {
            $this->shareTrackingService->recordSignup($user, $request);
        }

        return [
            'user' => $user,
            'access_token' => $accessToken,
        ];
    }
}
