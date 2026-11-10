<?php

namespace App\Features\Identity\EntryPoints\Http;

use App\Features\Events\Api\GuestSubmissionClaims;
use App\Features\Identity\Actions\ResolveSocialiteUserAction;
use App\Features\Identity\Api\SignupAttributionWorkflow;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountProfile;
use App\Shared\Auth\GuestSubmissionNotice;
use App\Shared\Auth\IntendedRedirect;
use App\Shared\Auth\OAuthTransactionStore;
use App\Shared\Auth\SignupAttributionContextResolver;
use App\Shared\Auth\SocialiteProviderConfiguration;
use App\Shared\ShareTracking\SignupRecorder;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider as OAuthTwoProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SocialiteController extends Controller
{
    /**
     * Redirect to the OAuth provider.
     */
    public function redirect(
        string $provider,
        SignupAttributionContextResolver $attributionContext,
    ): RedirectResponse {
        $intended = IntendedRedirect::captureFromRequest(request());

        if (! SocialiteProviderConfiguration::isConfigured($provider)) {
            return $this->loginErrorRedirect($provider, 'unconfigured');
        }

        // Reuse the in-flight verifier when the browser already holds one, so
        // concurrent sign-in attempts (two tabs) stay bound to the same
        // browser proof instead of the later attempt invalidating the
        // earlier one. The verifier only proves browser identity, which is
        // identical for both flows; single-use semantics live in the state.
        $existingVerifier = request()->cookie(OAuthTransactionStore::VERIFIER_COOKIE);

        $issued = OAuthTransactionStore::issueState(
            $provider,
            $intended,
            is_string($existingVerifier) && $existingVerifier !== '' ? $existingVerifier : null,
            $attributionContext->resolve(request()),
        );

        $socialiteProvider = Socialite::driver($provider);

        if ($socialiteProvider instanceof OAuthTwoProvider) {
            // Stateless driver: the OAuth state lives in the server-side
            // transaction store instead of the session, so the callback keeps
            // working when it arrives on a different hostname (the
            // dev.ilmu360.com tunnel bridge) than the one that started the flow.
            // The custom `state` parameter below is deliberate: with a stateless
            // driver Socialite does not set `state` itself, so the parameter
            // bag is the only way to send our opaque value to the provider.
            $socialiteProvider->stateless();
            $socialiteProvider->with(array_filter([
                'state' => $issued['state'],
                'prompt' => $provider === 'google' ? 'select_account' : null,
            ]));
        }

        // Bind the flow to the initiating browser: the callback (same host)
        // or the handoff redemption (bridge) must present this verifier back.
        $redirect = $socialiteProvider->redirect();
        $redirect->headers->setCookie(OAuthTransactionStore::verifierCookie($issued['verifier']));

        return $redirect;
    }

    /**
     * Handle the OAuth callback.
     */
    public function callback(
        string $provider,
        ResolveSocialiteUserAction $resolveSocialiteUserAction,
        SignupAttributionWorkflow $signupAttribution,
        SignupRecorder $signupRecorder,
    ): RedirectResponse {
        if (! SocialiteProviderConfiguration::isConfigured($provider)) {
            return $this->loginErrorRedirect($provider, 'unconfigured');
        }

        $transaction = OAuthTransactionStore::consumeState(
            (string) request()->input('state', ''),
            $provider
        );

        if ($transaction === null) {
            return $this->loginErrorRedirect($provider, 'expired', ['reason' => 'state_invalid']);
        }

        $bridge = SocialiteProviderConfiguration::usesCrossDomainCallback($provider);

        // Same host: the initiating browser must present its verifier cookie,
        // otherwise anyone forwarded a callback URL could log the victim into
        // the attacker's account (login CSRF). The bridge callback cannot see
        // the cookie (different host), so the check moves to the handoff.
        if (! $bridge && ! OAuthTransactionStore::verifierMatches(
            request()->cookie(OAuthTransactionStore::VERIFIER_COOKIE),
            $transaction['verifier_hash']
        )) {
            return $this->loginErrorRedirect($provider, 'expired', ['reason' => 'callback_verifier_mismatch']);
        }

        try {
            $socialiteProvider = Socialite::driver($provider);

            if ($socialiteProvider instanceof OAuthTwoProvider) {
                $socialiteProvider->stateless();
            }

            $socialUser = $socialiteProvider->user();
        } catch (\Throwable $exception) {
            return $this->loginErrorRedirect($provider, 'failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        // The signup source and its attributed-or-unattributed receipt
        // freeze in one transaction: a failure after user creation must
        // never leave a receipt-less account open to late credit.
        $result = DB::transaction(function () use ($resolveSocialiteUserAction, $provider, $socialUser, $transaction, $signupAttribution, $signupRecorder): array {
            $resolved = $resolveSocialiteUserAction->handle($provider, $socialUser);

            if ($resolved['created_account']) {
                $signupAttribution->recordForNewAccount(
                    $resolved['profile']->id,
                    $transaction['signup_attribution_context'],
                    true,
                );
                $signupRecorder->recordSignup($resolved['profile']->id, request());
            }

            return $resolved;
        });
        $profile = $result['profile'];
        $createdAccount = $result['created_account'];

        // Cross-domain bridge: the callback arrived on a host where the
        // browser has no application session, so hand the authenticated user
        // back to the canonical host with a one-time token instead of
        // logging in on this host.
        if ($bridge) {
            $token = OAuthTransactionStore::issueHandoff(
                $provider,
                $profile->id,
                $createdAccount,
                $transaction['intended'],
                $transaction['verifier_hash']
            );

            $completePath = route('socialite.complete', ['provider' => $provider], absolute: false);

            return redirect()->away(
                rtrim((string) config('app.url'), '/').$completePath.'?token='.urlencode($token)
            );
        }

        $this->loginUser($profile, $provider, $createdAccount);

        return redirect()->intended(route('home'));
    }

    /**
     * Complete a cross-domain OAuth login on the canonical host.
     *
     * Consumes the one-time handoff token issued by the callback, establishes
     * the normal application session, and redirects to the intended page.
     */
    public function complete(string $provider, AccountDirectory $accounts): RedirectResponse
    {
        if (! SocialiteProviderConfiguration::isConfigured($provider)) {
            return $this->loginErrorRedirect($provider, 'unconfigured');
        }

        $handoff = OAuthTransactionStore::consumeHandoff(
            (string) request()->input('token', ''),
            $provider
        );

        if ($handoff === null) {
            return $this->loginErrorRedirect($provider, 'expired', ['reason' => 'handoff_invalid']);
        }

        // Only the browser that started the flow holds the verifier cookie;
        // a forwarded handoff URL alone must never complete the login.
        if (! OAuthTransactionStore::verifierMatches(
            request()->cookie(OAuthTransactionStore::VERIFIER_COOKIE),
            $handoff['verifier_hash']
        )) {
            return $this->loginErrorRedirect($provider, 'expired', ['reason' => 'complete_verifier_mismatch']);
        }

        $profile = $accounts->profile($handoff['user_id']);

        if (! $profile instanceof AccountProfile) {
            return $this->loginErrorRedirect($provider, 'failed', ['reason' => 'user_missing']);
        }

        $this->loginUser($profile, $provider, $handoff['created_account']);

        $sessionIntended = session('url.intended');
        $sessionIntended = is_string($sessionIntended) ? $sessionIntended : null;

        // Prefer the flow's intended destination, fall back to a destination
        // the session already held (e.g. from a protected page), then home.
        $intended = IntendedRedirect::sanitize($handoff['intended'])
            ?? IntendedRedirect::sanitize($sessionIntended)
            ?? route('home');

        // Consume any intended URL left by the redirect step, mirroring
        // redirect()->intended() in the same-host flow.
        request()->session()->forget('url.intended');

        return redirect()->to($intended);
    }

    /**
     * Carry a bridge-mode error onto the canonical host, where the session
     * exists to flash it, then continue to login.
     */
    public function error(string $code): RedirectResponse
    {
        return redirect()->route('login')->with('toast', [
            'type' => 'error',
            'message' => $this->errorMessage($code),
        ]);
    }

    /**
     * @param  array<string, string>  $context
     */
    private function loginErrorRedirect(string $provider, string $error, array $context = []): RedirectResponse
    {
        // Single funnel for every OAuth failure: log the non-sensitive code
        // so silent-looking sign-in failures stay diagnosable server-side.
        Log::warning('Socialite sign-in failed', [
            'provider' => $provider,
            'code' => $error,
            'step' => request()->route()?->getName() ?? 'unknown',
            'host' => request()->getHost(),
            'verifier_present' => request()->hasCookie(OAuthTransactionStore::VERIFIER_COOKIE),
            ...$context,
        ]);

        // Off-host bridge errors cannot flash into a session the browser will
        // never send back (its cookies are rejected), so carry a non-sensitive
        // error code to the canonical host instead.
        if ($this->needsErrorCarry($provider)) {
            $errorPath = route('socialite.error', ['code' => $error], absolute: false);

            return redirect()->away(rtrim((string) config('app.url'), '/').$errorPath);
        }

        return redirect()->route('login')->with('toast', [
            'type' => 'error',
            'message' => $this->errorMessage($error),
        ]);
    }

    private function needsErrorCarry(string $provider): bool
    {
        if (! SocialiteProviderConfiguration::usesCrossDomainCallback($provider)) {
            return false;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return ! is_string($appHost)
            || strtolower(request()->getHost()) !== strtolower($appHost);
    }

    private function errorMessage(string $error): string
    {
        return match ($error) {
            'unconfigured' => __('Google sign-in is not configured right now. Please try again later.'),
            'expired' => __('Your sign-in session expired. Please try again.'),
            default => __('Unable to authenticate. Please try again.'),
        };
    }

    private function loginUser(AccountProfile $profile, string $provider, bool $createdAccount): void
    {
        Auth::loginUsingId($profile->id, remember: true);
        app(SignalRecorder::class)->record(
            eventName: 'auth.login',
            eventCategory: 'auth',
            actorId: $profile->id,
            properties: [
                'method' => $provider,
                'created_account' => $createdAccount,
            ],
            request: request(),
        );

        GuestSubmissionNotice::flash(
            app(GuestSubmissionClaims::class)->claimForAccount($profile->id)
        );

        Log::info('Socialite sign-in completed', [
            'provider' => $provider,
            'created_account' => $createdAccount,
        ]);
    }
}
