<?php

namespace App\Features\Communications\EntryPoints\Livewire;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Communications\Api\NotificationInboxManagement;
use App\Features\Communications\Api\NotificationInboxMessageSnapshot;
use App\Features\Communications\Support\Normalization\NotificationInboxPresentation;
use App\Platform\Time\UserDateTimeFormatter;
use App\Platform\Time\UserTimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class NotificationBell extends Component
{
    public string $variant = 'light';

    public int $unreadCount = 0;

    public bool $loaded = false;

    public bool $loadFailed = false;

    public string $filter = 'all';

    /**
     * @var list<array{
     *     id: string,
     *     title: string,
     *     body: string|null,
     *     family_label: string,
     *     icon: string,
     *     action_url: string|null,
     *     read: bool,
     *     relative_time: string,
     *     absolute_time: string,
     *     created_at_iso: string,
     *     group: 'today'|'earlier'
     * }>
     */
    public array $notifications = [];

    public function mount(string $variant = 'light'): void
    {
        $this->variant = in_array($variant, ['light', 'glass'], true) ? $variant : 'light';

        $user = auth()->user();

        if ($user instanceof Authenticatable) {
            $this->unreadCount = $this->resolveUnreadCount((string) $user->getAuthIdentifier());
        }
    }

    public function loadNotifications(): void
    {
        $accountId = $this->currentAccountId();

        $this->loadFailed = false;

        try {
            $this->unreadCount = $this->resolveUnreadCount($accountId);
            $this->notifications = $this->resolveNotifications($accountId);
            $this->loaded = true;
        } catch (Throwable $exception) {
            report($exception);
            $this->loadFailed = true;
            $this->loaded = true;
        }
    }

    #[On('notification-count-updated')]
    public function refreshUnreadCount(): void
    {
        $user = auth()->user();

        if (! $user instanceof Authenticatable) {
            return;
        }

        if ($this->loaded) {
            $this->loadNotifications();

            return;
        }

        $this->unreadCount = $this->resolveUnreadCount((string) $user->getAuthIdentifier());
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'unread'], true) ? $filter : 'all';

        $this->loadNotifications();
    }

    public function markAllAsRead(): void
    {
        $accountId = $this->currentAccountId();

        app(NotificationInboxManagement::class)->markAllAsRead($accountId);

        if ($this->loaded) {
            $this->loadNotifications();
        } else {
            $this->unreadCount = $this->resolveUnreadCount($accountId);
        }

        $this->dispatch('notification-inbox-updated');
    }

    public function openNotification(string $messageId): void
    {
        $accountId = $this->currentAccountId();

        $message = app(NotificationInboxManagement::class)
            ->markAsRead($accountId, $messageId);

        $actionUrl = $message->actionUrl;

        if (is_string($actionUrl) && $actionUrl !== '') {
            $this->redirect($actionUrl, navigate: true);

            return;
        }

        if ($this->loaded) {
            $this->loadNotifications();
        } else {
            $this->unreadCount = $this->resolveUnreadCount($accountId);
        }

        $this->dispatch('notification-inbox-updated');
    }

    public function render(): View
    {
        return view('communications::livewire.components.notification-bell');
    }

    private function currentAccountId(): string
    {
        $user = auth()->user();

        abort_unless($user instanceof Authenticatable, 403);

        return (string) $user->getAuthIdentifier();
    }

    private function resolveUnreadCount(string $accountId): int
    {
        return OwnerContext::withOwner(null, fn (): int => app(NotificationInboxManagement::class)
            ->unreadCountForAccount($accountId));
    }

    /**
     * @return list<array{
     *     id: string,
     *     title: string,
     *     body: string|null,
     *     family_label: string,
     *     icon: string,
     *     action_url: string|null,
     *     read: bool,
     *     relative_time: string,
     *     absolute_time: string,
     *     created_at_iso: string,
     *     group: 'today'|'earlier'
     * }>
     */
    private function resolveNotifications(string $accountId): array
    {
        $timezone = UserTimezoneResolver::resolve(request());

        $page = OwnerContext::withOwner(null, fn () => app(NotificationInboxManagement::class)
            ->pageForAccount(
                $accountId,
                familyValue: null,
                status: $this->filter === 'unread' ? 'unread' : 'all',
                perPage: 8,
                page: 1,
                newestFirst: true,
            ));

        /** @var list<NotificationInboxMessageSnapshot> $messages */
        $messages = $page->messages;

        return collect($messages)
            ->map(function (NotificationInboxMessageSnapshot $message) use ($timezone): array {
                $createdAt = CarbonImmutable::parse($message->createdAt ?? CarbonImmutable::now());
                $localCreatedAt = $createdAt->setTimezone($timezone);
                return [
                    'id' => $message->id,
                    'title' => $message->title,
                    'body' => $message->body,
                    'family_label' => NotificationInboxPresentation::familyLabel($message->family),
                    'icon' => NotificationInboxPresentation::familyIcon($message->family),
                    'action_url' => $message->actionUrl,
                    'read' => $message->readAt !== null,
                    'relative_time' => $localCreatedAt->locale(app()->getLocale())->shortRelativeDiffForHumans(),
                    'absolute_time' => UserDateTimeFormatter::translatedFormat($createdAt, 'j M Y, g:i A', timezone: $timezone),
                    'created_at_iso' => $createdAt->toIso8601String(),
                    'group' => $localCreatedAt->isToday() ? 'today' : 'earlier',
                ];
            })
            ->values()
            ->all();
    }
}
