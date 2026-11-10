<?php

namespace App\Livewire\Pages\Dashboard;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Models\NotificationInbox;
use App\Actions\Notifications\MarkAllNotificationMessagesReadAction;
use App\Actions\Notifications\MarkNotificationMessageReadAction;
use App\Models\User;
use App\Support\Communications\AccountCommunications;
use App\Support\Communications\EnumMapper;
use App\Support\Notifications\NotificationCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
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
        app(MarkNotificationMessageReadAction::class)->handle($this->currentUser(), $messageId, request());

        $this->dispatch('notification-count-updated');
    }

    public function markAllAsRead(): void
    {
        app(MarkAllNotificationMessagesReadAction::class)->handle($this->currentUser(), request());

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
        return app(AccountCommunications::class)->unreadInboxCount((string) $this->currentUser()->getKey());
    }

    /**
     * @return LengthAwarePaginator<int, NotificationInbox>
     */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        $query = app(AccountCommunications::class)->inboxQuery((string) $this->currentUser()->getKey())
            ->whereNull('archived_at')
            ->orderByDesc('created_at')
            ->when($this->family !== 'all', function ($builder): void {
                $familyValue = NotificationCatalog::inboxFamilyValue($this->family);

                if ($familyValue !== null) {
                    $builder->where('family', $familyValue);
                }
            })
            ->when($this->status === 'unread', fn ($builder) => $builder->whereNull('read_at'))
            ->when($this->status === 'read', fn ($builder) => $builder->whereNotNull('read_at'));

        return $query->paginate(perPage: 12, pageName: 'notifications_page');
    }

    public function familyLabel(NotificationInbox $message): string
    {
        $appFamily = EnumMapper::toAppFamily($message->family);

        return $appFamily !== null
            ? NotificationCatalog::familyDefinition($appFamily)['label']
            : $message->family->label();
    }

    public function familyIcon(NotificationInbox $message): string
    {
        return NotificationCatalog::familyIcon($message->family);
    }

    public function priorityLabel(NotificationInbox $message): string
    {
        return __('notifications.options.priority.'.EnumMapper::toAppPriority($message->priority)->value);
    }

    public function priorityTone(NotificationInbox $message): string
    {
        return match (EnumMapper::toAppPriority($message->priority)->value) {
            'urgent' => 'red',
            'high' => 'amber',
            'medium' => 'sky',
            default => 'zinc',
        };
    }

    public function notificationDate(NotificationInbox $message): CarbonImmutable
    {
        $occurredAt = data_get($message->data, 'occurred_at');

        if (is_string($occurredAt) && $occurredAt !== '') {
            try {
                return CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                // Fall back to the inbox row timestamp below.
            }
        }

        return CarbonImmutable::parse($message->created_at ?? CarbonImmutable::now());
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
