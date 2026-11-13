<?php

namespace App\Livewire\Components;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Models\NotificationInbox;
use App\Actions\Notifications\MarkAllNotificationMessagesReadAction;
use App\Actions\Notifications\MarkNotificationMessageReadAction;
use App\Models\User;
use App\Platform\Time\UserDateTimeFormatter;
use App\Platform\Time\UserTimezoneResolver;
use App\Support\Communications\AccountCommunications;
use App\Features\Communications\Api\EnumMapper;
use App\Features\Communications\Api\NotificationCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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

        if ($user instanceof User) {
            $this->unreadCount = $this->resolveUnreadCount($user);
        }
    }

    public function loadNotifications(): void
    {
        $user = $this->currentUser();

        $this->loadFailed = false;

        try {
            $this->unreadCount = $this->resolveUnreadCount($user);
            $this->notifications = $this->resolveNotifications($user);
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

        if (! $user instanceof User) {
            return;
        }

        if ($this->loaded) {
            $this->loadNotifications();

            return;
        }

        $this->unreadCount = $this->resolveUnreadCount($user);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'unread'], true) ? $filter : 'all';

        $this->loadNotifications();
    }

    public function markAllAsRead(): void
    {
        $user = $this->currentUser();

        app(MarkAllNotificationMessagesReadAction::class)->handle($user, request());

        if ($this->loaded) {
            $this->loadNotifications();
        } else {
            $this->unreadCount = $this->resolveUnreadCount($user);
        }

        $this->dispatch('notification-inbox-updated');
    }

    public function openNotification(string $messageId): void
    {
        $user = $this->currentUser();

        /** @var NotificationInbox $message */
        $message = app(MarkNotificationMessageReadAction::class)->handle($user, $messageId, request());

        $actionUrl = data_get($message->data, 'action_url');

        if (is_string($actionUrl) && $actionUrl !== '') {
            $this->redirect($actionUrl, navigate: true);

            return;
        }

        if ($this->loaded) {
            $this->loadNotifications();
        } else {
            $this->unreadCount = $this->resolveUnreadCount($user);
        }

        $this->dispatch('notification-inbox-updated');
    }

    public function render(): View
    {
        return view('livewire.components.notification-bell');
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function resolveUnreadCount(User $user): int
    {
        return OwnerContext::withOwner(null, fn (): int => app(AccountCommunications::class)
            ->unreadInboxCount((string) $user->getKey()));
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
    private function resolveNotifications(User $user): array
    {
        $timezone = UserTimezoneResolver::resolve(request());

        /** @var Collection<int, NotificationInbox> $messages */
        $messages = OwnerContext::withOwner(null, function () use ($user): Collection {
            $query = app(AccountCommunications::class)->inboxQuery((string) $user->getKey())
                ->whereNull('archived_at')
                ->orderByDesc('created_at')
                ->limit(8);

            if ($this->filter === 'unread') {
                $query->whereNull('read_at');
            }

            return $query->get();
        });

        return $messages
            ->map(function (NotificationInbox $message) use ($timezone): array {
                $createdAt = CarbonImmutable::parse($message->created_at ?? CarbonImmutable::now());
                $localCreatedAt = $createdAt->setTimezone($timezone);
                $appFamily = EnumMapper::toAppFamily($message->family);

                return [
                    'id' => (string) $message->getKey(),
                    'title' => $message->title,
                    'body' => $message->body,
                    'family_label' => $appFamily instanceof \App\Features\Communications\Api\NotificationFamily
                        ? NotificationCatalog::familyDefinition($appFamily)['label']
                        : $message->family->label(),
                    'icon' => NotificationCatalog::familyIcon($message->family),
                    'action_url' => data_get($message->data, 'action_url'),
                    'read' => $message->read_at !== null,
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
