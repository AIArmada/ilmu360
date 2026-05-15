<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Api\MessageResponseData;
use App\Data\Api\User\CurrentUserData;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponseFactory;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Group('User', 'Authenticated current-user profile endpoint.')]
class CurrentUserController extends Controller
{
    #[Endpoint(
        title: 'Get current authenticated user',
        description: 'Returns the current authenticated user profile and the request trace metadata for the bearer token that was used.',
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return response()->json([
            'data' => CurrentUserData::fromModel($user)->toArray(),
            'meta' => [
                'request_id' => ApiResponseFactory::requestId($request),
            ],
        ]);
    }

    #[Endpoint(
        title: 'Delete current authenticated user',
        description: 'Deletes the authenticated account, revokes all personal access tokens, and retains a restorable account snapshot for the admin grace-period restore flow.',
    )]
    #[Response(status: 200, description: 'Successful account deletion response.', type: MessageResponseData::class)]
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        DB::transaction(static function () use ($user): void {
            $user->delete();
        });

        return response()->json([
            'message' => 'Account deleted successfully.',
            'meta' => [
                'request_id' => ApiResponseFactory::requestId($request),
            ],
        ]);
    }
}
