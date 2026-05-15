<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Signals\ProductSignalsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class AuthenticateApiUserAction
{
    use AsAction;

    public function __construct(
        private ProductSignalsService $productSignalsService,
    ) {}

    /**
     * @return array{user: User, access_token: string}
     */
    public function handle(string $login, string $password, string $deviceName, Request $request): array
    {
        $user = $this->resolveUser($login);

        if (! $user instanceof User || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'login' => [__('auth.failed')],
            ]);
        }

        $accessToken = $user->createToken($deviceName)->plainTextToken;

        $this->productSignalsService->recordLogin($user, $request, 'api_token');

        return [
            'user' => $user,
            'access_token' => $accessToken,
        ];
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
}
