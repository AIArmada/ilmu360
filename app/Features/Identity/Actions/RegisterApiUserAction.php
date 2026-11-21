<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Api\AccountAccess;
use App\Features\Identity\Api\AccountSession;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RegisterApiUserAction
{
    use AsAction;

    public function __construct(
        private AccountAccess $accounts,
        private RegisterNewAccountAction $registration,
        private SignalRecorder $signals,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input, string $deviceName, Request $request): AccountSession
    {
        $accountId = (string) $this->registration->handle($input)->getKey();

        $this->accounts->dispatchRegistered($accountId);

        $accessToken = $this->accounts->issueToken($accountId, $deviceName);

        $this->signals->record(
            eventName: 'auth.login',
            eventCategory: 'auth',
            actorId: $accountId,
            properties: [
                'method' => 'api_token',
                'created_account' => true,
            ],
            request: $request,
        );

        return new AccountSession(
            accountId: $accountId,
            accessToken: $accessToken,
        );
    }
}
