<?php

namespace App\Livewire\Pages\Dashboard;

use App\Actions\Notifications\MarkAllNotificationMessagesReadAction;
use App\Actions\Notifications\MarkNotificationMessageReadAction;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\Notifications\NotificationCatalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class NotificationsIndex extends Component
{
    use WithPagination;

    #[Url(as: 'family')]
    public string $family = 'all';

    #[Url(as: 'status')]
    public string $status = 'unread';

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
    }

    public function markAllAsRead(): void
    {
        app(MarkAllNotificationMessagesReadAction::class)->handle($this->currentUser(), request());
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
        return $this->currentUser()
            ->notificationMessages()
            ->visibleInInbox()
            ->whereNull('read_at')
            ->count();
    }

    /**
     * @return LengthAwarePaginator<int, NotificationMessage>
     */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        $query = $this->currentUser()
            ->notificationMessages()
            ->visibleInInbox()
            ->when($this->family !== 'all', fn ($builder) => $builder->where('family', $this->family))
            ->when($this->status === 'unread', fn ($builder) => $builder->whereNull('read_at'))
            ->when($this->status === 'read', fn ($builder) => $builder->whereNotNull('read_at'));

        return $query->paginate(perPage: 12, pageName: 'notifications_page');
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
