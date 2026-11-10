<?php

namespace App\Features\Identity\EntryPoints\Http;

use App\Features\Identity\Actions\AuthenticateApiUserAction;
use App\Features\Identity\Actions\AuthenticateSocialiteApiUserAction;
use App\Features\Identity\Actions\RegisterApiUserAction;
use App\Features\Identity\Actions\RevokeCurrentApiTokenAction;
use App\Features\Identity\EntryPoints\Http\Data\AuthTokenData;
use App\Features\Identity\EntryPoints\Http\Data\AuthTokenResponseData;
use App\Shared\Api\MessageResponseData;
use App\Shared\Auth\AccountAccess;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountProfile;
use App\Shared\Auth\AccountSession;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    /**
     * Register a new user account and immediately return a Sanctum bearer token.
     *
     * The returned `access_token` should be sent on future requests using
     * `Authorization: Bearer {token}`.
     */
    #[Group('Authentication', 'Bearer-token issuance, password recovery, and verification endpoints for API clients.')]
    #[BodyParameter('name', 'Display name for the new account.', type: 'string', infer: false, example: 'Mobile User')]
    #[BodyParameter('email', 'Unique email address. Required when `phone` is omitted.', required: false, type: 'string', infer: false, example: 'mobile@example.com')]
    #[BodyParameter('phone', 'Unique phone number. Required when `email` is omitted.', required: false, type: 'string', infer: false, example: '+60111222333')]
    #[BodyParameter('password', 'Account password.', type: 'string', infer: false, example: 'password')]
    #[BodyParameter('password_confirmation', 'Password confirmation matching `password`.', type: 'string', infer: false, example: 'password')]
    #[BodyParameter('device_name', 'Client-defined Sanctum token name.', type: 'string', infer: false, example: 'iPhone 15 Pro')]
    #[Endpoint(
        title: 'Register and issue a bearer token',
        description: 'Creates a new account and immediately returns a Sanctum bearer token. Supply either `email` or `phone`, plus `password`, `password_confirmation`, and `device_name`.',
    )]
    #[Response(status: 201, description: 'Successful registration response.', type: AuthTokenResponseData::class)]
    public function register(
        Request $request,
        RegisterApiUserAction $registerApiUserAction,
        AccountDirectory $accounts,
    ): JsonResponse {
        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $session = $registerApiUserAction->handle($request->all(), (string) $validated['device_name'], $request);

        return response()->json([
            'message' => 'API account registered successfully.',
            'data' => $this->tokenData($session, $accounts),
        ], 201);
    }

    /**
     * Exchange account credentials for a Sanctum bearer token.
     *
     * Send either an email address or phone number in `login`, plus the user's
     * password and a client-defined `device_name`. Long-lived manual tokens are
     * issued and revoked by super admins from Admin > Authz > User > API Access
     * for the selected user.
     */
    #[Group('Authentication', 'Bearer-token issuance, password recovery, and verification endpoints for API clients.')]
    #[Endpoint(
        title: 'Log in and issue a bearer token',
        description: 'Authenticates an existing account using either an email address or phone number in `login`, then returns a Sanctum bearer token. Long-lived manual tokens are managed by super admins from Admin > Authz > User > API Access for the selected user.',
    )]
    #[Response(status: 200, description: 'Successful login response.', type: AuthTokenResponseData::class)]
    public function login(
        Request $request,
        AuthenticateApiUserAction $authenticateApiUserAction,
        AccountDirectory $accounts,
    ): JsonResponse {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $session = $authenticateApiUserAction->handle(
            (string) $validated['login'],
            (string) $validated['password'],
            (string) $validated['device_name'],
            $request,
        );

        return response()->json([
            'message' => 'API login successful.',
            'data' => $this->tokenData($session, $accounts),
        ]);
    }

    /**
     * Exchange a Google provider token for a Sanctum bearer token.
     */
    #[Group('Authentication', 'Bearer-token issuance, password recovery, and verification endpoints for API clients.')]
    #[Endpoint(
        title: 'Exchange a Google token for a bearer token',
        description: 'Validates a Google access token, creates or links the local user, and returns a Sanctum bearer token for API use.',
    )]
    #[Response(status: 200, description: 'Successful Google token exchange response.', type: AuthTokenResponseData::class)]
    public function google(Request $request, AuthenticateSocialiteApiUserAction $authenticateSocialiteApiUserAction): JsonResponse
    {
        $validated = $request->validate([
            'access_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $result = $authenticateSocialiteApiUserAction->handle(
            'google',
            (string) $validated['access_token'],
            (string) $validated['device_name'],
            $request,
        );

        return response()->json([
            'message' => 'API Google login successful.',
            'data' => AuthTokenData::fromToken($result['session']->accessToken, $result['profile'])->toArray(),
        ]);
    }

    /**
     * Revoke the currently authenticated Sanctum bearer token.
     */
    #[Group('Authentication', 'Bearer-token issuance, password recovery, and verification endpoints for API clients.')]
    #[Endpoint(
        title: 'Revoke the current bearer token',
        description: 'Revokes the current Sanctum bearer token without affecting other device sessions.',
    )]
    #[Response(status: 200, description: 'Successful logout response.', type: MessageResponseData::class)]
    public function logout(Request $request, RevokeCurrentApiTokenAction $revokeCurrentApiTokenAction): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof Authenticatable, 403);

        $revokeCurrentApiTokenAction->handle($user);

        return response()->json([
            'message' => 'API token revoked successfully.',
        ]);
    }

    #[Group('Authentication', 'Bearer-token issuance, password recovery, and verification endpoints for API clients.')]
    #[Endpoint(
        title: 'Send a password reset link',
        description: 'Starts the password-broker reset flow. The response is intentionally the same whether or not the email exists.',
    )]
    #[Response(status: 200, description: 'Password reset link dispatch response.', type: MessageResponseData::class)]
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::broker($this->passwordBroker())->sendResetLink([
            'email' => (string) $validated['email'],
        ]);

        return response()->json([
            'message' => 'If we found an account with that email address, we have emailed a password reset link.',
        ]);
    }

    #[Group('Authentication', 'Bearer-token issuance, password recovery, and verification endpoints for API clients.')]
    #[Endpoint(
        title: 'Reset a password with a broker token',
        description: 'Consumes a password broker token plus the new password and returns a success message when the reset succeeds.',
    )]
    #[Response(status: 200, description: 'Successful password reset response.', type: MessageResponseData::class)]
    public function resetPassword(Request $request, AccountAccess $accounts): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker($this->passwordBroker())->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (mixed $user, string $password) use ($accounts, $request): void {
                abort_unless($user instanceof Authenticatable, 500);

                $accounts->resetPassword((string) $user->getAuthIdentifier(), $password, $request);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __($status),
                'errors' => [
                    'email' => [__($status)],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }

    #[Group('Authentication', 'Bearer-token issuance, password recovery, and verification endpoints for API clients.')]
    #[Endpoint(
        title: 'Resend the verification email',
        description: 'Resends the verification email for the current authenticated user when the address is still unverified.',
    )]
    #[Response(status: 200, description: 'Verification resend response.', type: MessageResponseData::class)]
    public function resendVerificationEmail(Request $request, AccountDirectory $accounts, AccountAccess $access): JsonResponse
    {
        $accountId = $this->currentAccountId($request);
        $profile = $accounts->profile($accountId);

        if (! $profile instanceof AccountProfile || ! is_string($profile->email) || trim($profile->email) === '') {
            return response()->json([
                'message' => 'An email address is required to send a verification email.',
            ], 422);
        }

        if ($profile->emailVerified) {
            return response()->json([
                'message' => 'Email address is already verified.',
            ], 422);
        }

        $access->sendEmailVerification($accountId);

        return response()->json([
            'message' => 'Verification email sent successfully.',
        ]);
    }

    protected function currentAccountId(Request $request): string
    {
        $user = $request->user();

        abort_unless($user instanceof Authenticatable, 403);

        return (string) $user->getAuthIdentifier();
    }

    protected function passwordBroker(): string
    {
        return (string) config('fortify.passwords', 'users');
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenData(AccountSession $session, AccountDirectory $accounts): array
    {
        $profile = $accounts->profile($session->accountId)
            ?? throw new \RuntimeException('The authenticated account could not be loaded.');

        return AuthTokenData::fromToken($session->accessToken, $profile)->toArray();
    }
}
