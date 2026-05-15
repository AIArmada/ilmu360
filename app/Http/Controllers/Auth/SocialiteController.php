<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResolveSocialiteUserAction;
use App\Http\Controllers\Controller;
use App\Services\ShareTrackingService;
use App\Services\Signals\ProductSignalsService;
use App\Support\Auth\IntendedRedirect;
use App\Support\Auth\SocialiteProviderConfiguration;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider as OAuthTwoProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SocialiteController extends Controller
{
    /**
     * Redirect to the OAuth provider.
     */
    public function redirect(string $provider): RedirectResponse
    {
        IntendedRedirect::captureFromRequest(request());

        if (! SocialiteProviderConfiguration::isConfigured($provider)) {
            return redirect()->route('login')->with('toast', [
                'type' => 'error',
                'message' => __('Google sign-in is not configured right now. Please use email and password instead.'),
            ]);
        }

        $socialiteProvider = Socialite::driver($provider);

        if ($provider === 'google' && $socialiteProvider instanceof OAuthTwoProvider) {
            $socialiteProvider->with(['prompt' => 'select_account']);
        }

        return $socialiteProvider->redirect();
    }

    /**
     * Handle the OAuth callback.
     */
    public function callback(string $provider, ResolveSocialiteUserAction $resolveSocialiteUserAction): RedirectResponse
    {
        if (! SocialiteProviderConfiguration::isConfigured($provider)) {
            return redirect()->route('login')->with('toast', [
                'type' => 'error',
                'message' => __('Google sign-in is not configured right now. Please use email and password instead.'),
            ]);
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable) {
            return redirect()->route('login')->with('toast', [
                'type' => 'error',
                'message' => __('Unable to authenticate. Please try again.'),
            ]);
        }

        $result = $resolveSocialiteUserAction->handle($provider, $socialUser);
        $user = $result['user'];
        $createdFromShare = $result['created_account'];

        Auth::login($user, remember: true);
        app(ProductSignalsService::class)->recordLogin($user, request(), $provider, $createdFromShare);

        if ($createdFromShare) {
            app(ShareTrackingService::class)->recordSignup($user, request());
        }

        return redirect()->intended(route('home'));
    }
}
