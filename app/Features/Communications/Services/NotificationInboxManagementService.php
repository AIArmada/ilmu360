<?php

declare(strict_types=1);

namespace App\Features\Communications\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Models\NotificationInbox;
use App\Features\Communications\Actions\Inbox\MarkAllNotificationInboxMessagesReadAction;
use App\Features\Communications\Actions\Inbox\MarkNotificationInboxMessageReadAction;
use App\Features\Communications\Api\NotificationInboxManagement;
use App\Features\Communications\Api\NotificationInboxMessageSnapshot;
use App\Features\Communications\Api\NotificationInboxPageSnapshot;
use App\Features\Communications\Support\Normalization\NotificationInboxPresentation;
use App\Features\Communications\Support\NotificationInbox\NotificationInboxMessageSnapshotFactory;
use App\Shared\Auth\UserMorphType;
use Illuminate\Database\Eloquent\Builder;

final readonly class NotificationInboxManagementService implements NotificationInboxManagement
{
    public function __construct(
        private NotificationInboxMessageSnapshotFactory $snapshots,
        private MarkNotificationInboxMessageReadAction $markMessageRead,
        private MarkAllNotificationInboxMessagesReadAction $markAllMessagesRead,
        private UserMorphType $userMorphType,
    ) {}

    public function pageForAccount(
        string $accountId,
        ?string $familyValue,
        string $status,
        int $perPage,
        int $page,
        bool $newestFirst = false,
    ): NotificationInboxPageSnapshot {
        OwnerContext::setForRequest(null);

        $storedFamily = $familyValue !== null
            ? NotificationInboxPresentation::storedFamilyValue($familyValue)
            : null;
        $query = $this->visibleInboxQuery($accountId)
            ->when($storedFamily !== null, fn (Builder $builder) => $builder->where('family', $storedFamily))
            ->when($status === 'unread', fn (Builder $builder) => $builder->whereNull('read_at'))
            ->when($status === 'read', fn (Builder $builder) => $builder->whereNotNull('read_at'));

        if ($newestFirst) {
            $query->orderByDesc('created_at');
        }

        $paginator = $query->paginate(max(1, $perPage), ['*'], 'page', max(1, $page));

        /** @var list<NotificationInboxMessageSnapshot> $messages */
        $messages = collect($paginator->items())
            ->map(fn (NotificationInbox $message): NotificationInboxMessageSnapshot => $this->snapshots->fromModel($message))
            ->values()
            ->all();

        return new NotificationInboxPageSnapshot(
            messages: $messages,
            page: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
        );
    }

    public function unreadCountForAccount(string $accountId): int
    {
        OwnerContext::setForRequest(null);

        return $this->visibleInboxQuery($accountId)
            ->whereNull('read_at')
            ->count();
    }

    public function markAsRead(string $accountId, string $messageId): NotificationInboxMessageSnapshot
    {
        return $this->markMessageRead->handle($accountId, $messageId);
    }

    public function markAllAsRead(string $accountId): int
    {
        return $this->markAllMessagesRead->handle($accountId);
    }

    /**
     * @return Builder<NotificationInbox>
     */
    private function visibleInboxQuery(string $accountId): Builder
    {
        return NotificationInbox::query()
            ->where('recipient_type', $this->userMorphType->morphClass())
            ->where('recipient_id', $accountId)
            ->whereNull('archived_at');
    }
}
