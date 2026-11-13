<?php

namespace App\Features\Communications\EntryPoints\Http;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Communications\EntryPoints\Http\Data\NotificationMessageData as NotificationMessagePayloadData;
use App\Features\Communications\EntryPoints\Http\Data\NotificationReadAllResultData;
use App\Features\Communications\Api\NotificationCatalog;
use App\Features\Communications\Api\NotificationInboxManagement;
use App\Features\Communications\Api\NotificationInboxMessageSnapshot;
use App\Platform\Api\ApiPagination;
use Illuminate\Contracts\Auth\Authenticatable;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Notification Inbox', 'Authenticated inbox endpoints for listing notification messages and marking them as read.')]
class NotificationMessageController
{
    public function __construct()
    {
        OwnerContext::setForRequest(null);
    }

    #[Endpoint(
        title: 'List notification messages',
        description: 'Returns the authenticated user\'s inbox-visible notification messages with filtering and pagination support.',
    )]
    public function index(Request $request): JsonResponse
    {
        $accountId = $this->currentAccountId($request);
        $family = $request->string('family', 'all')->toString();
        $status = $request->string('status', 'unread')->toString();
        $perPage = ApiPagination::normalizePerPage($request->integer('per_page', 20), default: 20, max: 100);

        $familyValue = $family !== 'all' && array_key_exists($family, NotificationCatalog::families())
            ? $family
            : null;
        $communications = app(NotificationInboxManagement::class);
        $notifications = $communications->pageForAccount(
            $accountId,
            $familyValue,
            $status,
            $perPage,
            max(1, $request->integer('page', 1)),
        );
        $unreadCount = $status === 'unread'
            ? $notifications->total
            : $communications->unreadCountForAccount($accountId);

        return response()->json([
            'data' => collect($notifications->messages)
                ->map(fn (NotificationInboxMessageSnapshot $message): array => NotificationMessagePayloadData::fromSnapshot($message)->toArray())
                ->all(),
            'meta' => [
                'unread_count' => $unreadCount,
                'pagination' => [
                    'page' => $notifications->page,
                    'per_page' => $notifications->perPage,
                    'total' => $notifications->total,
                ],
            ],
        ]);
    }

    #[Endpoint(
        title: 'Mark a notification message as read',
        description: 'Marks one inbox notification message as read for the current authenticated user.',
    )]
    public function read(
        Request $request,
        string $message,
        NotificationInboxManagement $communications,
    ): JsonResponse {
        $notification = $communications->markAsRead($this->currentAccountId($request), $message);

        return response()->json([
            'message' => __('notifications.api.read_success'),
            'data' => NotificationMessagePayloadData::fromSnapshot($notification)->toArray(),
        ]);
    }

    #[Endpoint(
        title: 'Mark all notification messages as read',
        description: 'Marks every inbox-visible notification message as read for the current authenticated user.',
    )]
    public function readAll(Request $request, NotificationInboxManagement $communications): JsonResponse
    {
        $updated = $communications->markAllAsRead($this->currentAccountId($request));

        return response()->json([
            'message' => __('notifications.api.read_all_success'),
            'data' => NotificationReadAllResultData::fromCount($updated)->toArray(),
        ]);
    }

    protected function currentAccountId(Request $request): string
    {
        $user = $request->user();

        abort_unless($user instanceof Authenticatable, 403);

        return (string) $user->getAuthIdentifier();
    }
}
