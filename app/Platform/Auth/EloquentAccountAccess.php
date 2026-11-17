<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Models\User;
use App\Platform\Auth\Fortify\CreateNewUser;
use App\Shared\Auth\AccountAccess;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountProfile;
use App\Shared\Auth\AccountSession;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class EloquentAccountAccess implements AccountAccess
{
    public function __construct(
        private CreateNewUser $createNewUser,
        private AccountDirectory $accounts,
        private SignalRecorder $signals,
    ) {}

    public function register(array $input): AccountProfile
    {
        return $this->profileOrFail((string) $this->createNewUser->create($input)->getKey());
    }

    public function create(array $attributes): AccountProfile
    {
        return $this->profileOrFail((string) User::query()->create($attributes)->getKey());
    }

    public function findByEmail(string $email): ?AccountProfile
    {
        $user = User::query()->where('email', $email)->first();

        return $user instanceof User
            ? $this->accounts->profile((string) $user->getKey())
            : null;
    }

    public function authenticate(string $login, string $password, string $deviceName): AccountSession
    {
        $user = $this->resolveUser($login);

        if (! $user instanceof User || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'login' => [__('auth.failed')],
            ]);
        }

        return new AccountSession(
            accountId: (string) $user->getKey(),
            accessToken: $user->createToken($deviceName)->plainTextToken,
        );
    }

    public function issueToken(string $accountId, string $deviceName): string
    {
        return $this->user($accountId)->createToken($deviceName)->plainTextToken;
    }

    public function dispatchRegistered(string $accountId): void
    {
        event(new Registered($this->user($accountId)));
    }

    public function revokeCurrentToken(Authenticatable $account): void
    {
        if ($account instanceof User) {
            $this->revokeCurrentTokenForUser($account);
        }
    }

    private function revokeCurrentTokenForUser(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    public function markEmailVerified(string $accountId): void
    {
        $this->user($accountId)->markEmailAsVerified();
    }

    public function sendEmailVerification(string $accountId): void
    {
        $this->user($accountId)->sendEmailVerificationNotification();
    }

    public function persistResetPassword(string $accountId, string $password, ?Request $request = null): void
    {
        $this->persistResetPasswordForUser($this->user($accountId), $password, $request);
    }

    public function resetPassword(string $accountId, string $password, ?Request $request = null): void
    {
        $user = $this->user($accountId);

        $this->persistResetPasswordForUser($user, $password, $request);

        $user->forceFill([
            'remember_token' => Str::random(60),
        ])->save();

        event(new PasswordReset($user));
    }

    private function persistResetPasswordForUser(User $user, string $password, ?Request $request): void
    {
        $user->forceFill([
            'password' => $password,
        ])->save();

        $this->signals->record(
            eventName: 'auth.password_reset.completed',
            eventCategory: 'auth',
            actorId: (string) $user->getKey(),
            request: $request ?? request(),
        );
    }

    private function resolveUser(string $login): ?User
    {
        $normalizedLogin = trim($login);

        if ($normalizedLogin === '') {
            return null;
        }

        $query = User::query();

        if (filter_var($normalizedLogin, FILTER_VALIDATE_EMAIL) !== false) {
            return $query->where('email', $normalizedLogin)->first();
        }

        return $query->where('phone', $normalizedLogin)->first();
    }

    private function user(string $accountId): User
    {
        return User::query()->findOrFail($accountId);
    }

    private function profileOrFail(string $accountId): AccountProfile
    {
        return $this->accounts->profile($accountId)
            ?? throw new \RuntimeException('The registered account could not be loaded.');
    }
}
