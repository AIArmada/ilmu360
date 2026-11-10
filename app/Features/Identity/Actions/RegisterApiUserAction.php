<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Shared\Auth\AccountAccess;
use App\Shared\Auth\AccountSession;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RegisterApiUserAction
{
    use AsAction;

    public function __construct(
        private AccountAccess $accounts,
        private SignalRecorder $signals,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input, string $deviceName, Request $request): AccountSession
    {
        $profile = $this->accounts->register($input);

        $this->accounts->dispatchRegistered($profile->id);

        $accessToken = $this->accounts->issueToken($profile->id, $deviceName);

        $this->signals->record(
            eventName: 'auth.login',
            eventCategory: 'auth',
            actorId: $profile->id,
            properties: [
                'method' => 'api_token',
                'created_account' => true,
            ],
            request: $request,
        );

        return new AccountSession(
            accountId: $profile->id,
            accessToken: $accessToken,
        );
    }
}
