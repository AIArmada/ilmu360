<?php

namespace App\Livewire\Components;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Communications\Api\EnumMapper;
use App\Features\Communications\Api\NotificationCatalog;
use App\Features\Communications\Api\NotificationInboxManagement;
use App\Features\Communications\Api\NotificationInboxMessageSnapshot;
use App\Models\User;
use App\Platform\Time\UserDateTimeFormatter;
use App\Platform\Time\UserTimezoneResolver;
use Carbon\CarbonImmutable;
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

        app(NotificationInboxManagement::class)->markAllAsRead((string) $user->getKey());

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

        $message = app(NotificationInboxManagement::class)
            ->markAsRead((string) $user->getKey(), $messageId);

        $actionUrl = $message->actionUrl;

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
        return OwnerContext::withOwner(null, fn (): int => app(NotificationInboxManagement::class)
            ->unreadCountForAccount((string) $user->getKey()));
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

        /** @var list<NotificationInboxMessageSnapshot> $messages */
        $messages = OwnerContext::withOwner(null, fn (): array => app(NotificationInboxManagement::class)
            ->recentForAccount(
                (string) $user->getKey(),
                limit: 8,
                unreadOnly: $this->filter === 'unread',
            ));

        return collect($messages)
            ->map(function (NotificationInboxMessageSnapshot $message) use ($timezone): array {
                $createdAt = CarbonImmutable::parse($message->createdAt ?? CarbonImmutable::now());
                $localCreatedAt = $createdAt->setTimezone($timezone);
                $packageFamily = \AIArmada\Communications\Enums\NotificationFamily::from($message->family);
                $appFamily = EnumMapper::toAppFamily($packageFamily);

                return [
                    'id' => $message->id,
                    'title' => $message->title,
                    'body' => $message->body,
                    'family_label' => $appFamily instanceof \App\Features\Communications\Api\NotificationFamily
                        ? NotificationCatalog::familyDefinition($appFamily)['label']
                        : $packageFamily->label(),
                    'icon' => NotificationCatalog::familyIcon($packageFamily),
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
