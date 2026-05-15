<?php

namespace App\Http\Controllers\Api;

use App\Actions\Notifications\MarkAllNotificationMessagesReadAction;
use App\Actions\Notifications\MarkNotificationMessageReadAction;
use App\Data\Api\Notification\NotificationMessageData as NotificationMessagePayloadData;
use App\Data\Api\Notification\NotificationReadAllResultData;
use App\Http\Controllers\Controller;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\Api\ApiPagination;
use App\Support\Notifications\NotificationCatalog;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Notification Inbox', 'Authenticated inbox endpoints for listing notification messages and marking them as read.')]
class NotificationMessageController extends Controller
{
    #[Endpoint(
        title: 'List notification messages',
        description: 'Returns the authenticated user\'s inbox-visible notification messages with filtering and pagination support.',
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $family = $request->string('family', 'all')->toString();
        $status = $request->string('status', 'unread')->toString();
        $perPage = ApiPagination::normalizePerPage($request->integer('per_page', 20), default: 20, max: 100);

        $query = $user->notificationMessages()
            ->visibleInInbox()
            ->when($family !== 'all' && array_key_exists($family, NotificationCatalog::families()), fn ($builder) => $builder->where('family', $family))
            ->when($status === 'unread', fn ($builder) => $builder->whereNull('read_at'))
            ->when($status === 'read', fn ($builder) => $builder->whereNotNull('read_at'));

        $notifications = $query->paginate($perPage);
        $unreadCount = $status === 'unread'
            ? $notifications->total()
            : $user->notificationMessages()->visibleInInbox()->whereNull('read_at')->count();

        return response()->json([
            'data' => collect($notifications->items())
                ->map(fn (NotificationMessage $message): array => NotificationMessagePayloadData::fromModel($message)->toArray())
                ->all(),
            'meta' => [
                'unread_count' => $unreadCount,
                'pagination' => [
                    'page' => $notifications->currentPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
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
        MarkNotificationMessageReadAction $markNotificationMessageReadAction,
    ): JsonResponse {
        $notification = $markNotificationMessageReadAction->handle($this->currentUser($request), $message, $request);

        return response()->json([
            'message' => __('notifications.api.read_success'),
            'data' => NotificationMessagePayloadData::fromModel($notification)->toArray(),
        ]);
    }

    #[Endpoint(
        title: 'Mark all notification messages as read',
        description: 'Marks every inbox-visible notification message as read for the current authenticated user.',
    )]
    public function readAll(Request $request, MarkAllNotificationMessagesReadAction $markAllNotificationMessagesReadAction): JsonResponse
    {
        $updated = $markAllNotificationMessagesReadAction->handle($this->currentUser($request), $request);

        return response()->json([
            'message' => __('notifications.api.read_all_success'),
            'data' => NotificationReadAllResultData::fromCount($updated)->toArray(),
        ]);
    }

    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
