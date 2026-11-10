<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Shared\Auth\AccountAccess;
use App\Shared\Auth\AccountSession;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class AuthenticateApiUserAction
{
    use AsAction;

    public function __construct(
        private AccountAccess $accounts,
        private SignalRecorder $signals,
    ) {}

    public function handle(string $login, string $password, string $deviceName, Request $request): AccountSession
    {
        $session = $this->accounts->authenticate($login, $password, $deviceName);

        $this->signals->record(
            eventName: 'auth.login',
            eventCategory: 'auth',
            actorId: $session->accountId,
            properties: [
                'method' => 'api_token',
                'created_account' => false,
            ],
            request: $request,
        );

        return $session;
    }
}
