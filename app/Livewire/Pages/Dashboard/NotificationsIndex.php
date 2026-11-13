<?php

namespace App\Livewire\Pages\Dashboard;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Communications\Api\EnumMapper;
use App\Features\Communications\Api\NotificationCatalog;
use App\Features\Communications\Api\NotificationInboxManagement;
use App\Features\Communications\Api\NotificationInboxMessageSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Pagination\Paginator as CurrentPageResolver;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('layouts.app')]
class NotificationsIndex extends Component
{
    use WithPagination;

    #[Url(as: 'family')]
    public string $family = 'all';

    #[Url(as: 'status')]
    public string $status = 'unread';

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(): void
    {
        $this->family = $this->normalizeFamily($this->family);
        $this->status = $this->normalizeStatus($this->status);
    }

    public function updatedFamily(string $family): void
    {
        $this->family = $this->normalizeFamily($family);
        $this->resetPage('notifications_page');
    }

    public function updatedStatus(string $status): void
    {
        $this->status = $this->normalizeStatus($status);
        $this->resetPage('notifications_page');
    }

    public function markAsRead(string $messageId): void
    {
        app(NotificationInboxManagement::class)->markAsRead((string) $this->currentUser()->getKey(), $messageId);

        $this->dispatch('notification-count-updated');
    }

    public function markAllAsRead(): void
    {
        app(NotificationInboxManagement::class)->markAllAsRead((string) $this->currentUser()->getKey());

        $this->dispatch('notification-count-updated');
    }

    #[On('notification-inbox-updated')]
    public function refreshInbox(): void
    {
        unset($this->notifications);
        unset($this->unreadCount);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function familyOptions(): array
    {
        return collect(NotificationCatalog::families())
            ->mapWithKeys(fn (array $definition, string $key): array => [$key => $definition['label']])
            ->all();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return app(NotificationInboxManagement::class)->unreadCountForAccount((string) $this->currentUser()->getKey());
    }

    /**
     * @return LengthAwarePaginator<int, NotificationInboxMessageSnapshot>
     */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        $snapshot = app(NotificationInboxManagement::class)->pageForAccount(
            (string) $this->currentUser()->getKey(),
            $this->family === 'all' ? null : $this->family,
            $this->status,
            perPage: 12,
            page: max(1, CurrentPageResolver::resolveCurrentPage('notifications_page')),
            newestFirst: true,
        );

        return (new Paginator(
            $snapshot->messages,
            $snapshot->total,
            $snapshot->perPage,
            $snapshot->page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'notifications_page',
            ],
        ))->withQueryString();
    }

    public function familyLabel(NotificationInboxMessageSnapshot $message): string
    {
        $packageFamily = \AIArmada\Communications\Enums\NotificationFamily::from($message->family);
        $appFamily = EnumMapper::toAppFamily($packageFamily);

        return $appFamily instanceof \App\Features\Communications\Api\NotificationFamily
            ? NotificationCatalog::familyDefinition($appFamily)['label']
            : $packageFamily->label();
    }

    public function familyIcon(NotificationInboxMessageSnapshot $message): string
    {
        return NotificationCatalog::familyIcon(\AIArmada\Communications\Enums\NotificationFamily::from($message->family));
    }

    public function priorityLabel(NotificationInboxMessageSnapshot $message): string
    {
        $priority = \AIArmada\Communications\Enums\NotificationPriority::from($message->priority);

        return __('notifications.options.priority.'.EnumMapper::toAppPriority($priority)->value);
    }

    public function priorityTone(NotificationInboxMessageSnapshot $message): string
    {
        $priority = \AIArmada\Communications\Enums\NotificationPriority::from($message->priority);

        return match (EnumMapper::toAppPriority($priority)->value) {
            'urgent' => 'red',
            'high' => 'amber',
            'medium' => 'sky',
            default => 'zinc',
        };
    }

    public function notificationDate(NotificationInboxMessageSnapshot $message): CarbonImmutable
    {
        $occurredAt = $message->occurredAt;

        if (is_string($occurredAt) && $occurredAt !== '') {
            try {
                return CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                // Fall back to the inbox row timestamp below.
            }
        }

        return CarbonImmutable::parse($message->createdAt ?? CarbonImmutable::now());
    }

    protected function currentUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function normalizeFamily(string $family): string
    {
        return $family === 'all' || array_key_exists($family, NotificationCatalog::families())
            ? $family
            : 'all';
    }

    protected function normalizeStatus(string $status): string
    {
        return in_array($status, ['all', 'unread', 'read'], true) ? $status : 'unread';
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.notifications-index');
    }
}
