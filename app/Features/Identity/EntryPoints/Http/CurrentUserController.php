<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Http;

use App\Features\Identity\Api\AccountPreferences;
use App\Features\Identity\EntryPoints\Http\Data\CurrentUserData;
use App\Shared\Api\MessageResponseData;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountRecord;
use App\Shared\Auth\AccountSettingsStore;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Group('User', 'Authenticated current-user profile endpoint.')]
class CurrentUserController extends Controller
{
    #[Endpoint(
        title: 'Get current authenticated user',
        description: 'Returns the current authenticated user profile and the request trace metadata for the bearer token that was used.',
    )]
    public function __invoke(Request $request, AccountDirectory $accounts, AccountPreferences $preferences): JsonResponse
    {
        $record = $this->record($request, $accounts);

        return response()->json([
            'data' => CurrentUserData::fromRecord($record, $preferences->read($record->profile->id))->toArray(),
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    #[Endpoint(
        title: 'Delete current authenticated user',
        description: 'Deletes the authenticated account, revokes all personal access tokens, and retains a restorable account snapshot for the admin grace-period restore flow.',
    )]
    #[Response(status: 200, description: 'Successful account deletion response.', type: MessageResponseData::class)]
    public function destroy(Request $request, AccountSettingsStore $accounts): JsonResponse
    {
        $accountId = $this->accountId($request);

        DB::transaction(static function () use ($accounts, $accountId): void {
            $accounts->delete($accountId);
        });

        return response()->json([
            'message' => 'Account deleted successfully.',
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    private function record(Request $request, AccountDirectory $accounts): AccountRecord
    {
        return $accounts->record($this->accountId($request))
            ?? abort(403);
    }

    private function accountId(Request $request): string
    {
        $user = $request->user();

        abort_unless($user instanceof Authenticatable, 403);

        return (string) $user->getAuthIdentifier();
    }

    private function requestId(Request $request): string
    {
        return $request->header('X-Request-ID', (string) Str::uuid());
    }
}
