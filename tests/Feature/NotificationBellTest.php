<?php

use AIArmada\Communications\Enums\NotificationFamily;
use AIArmada\Communications\Enums\NotificationPriority;
use AIArmada\Communications\Enums\NotificationTrigger;
use AIArmada\Communications\Models\NotificationInbox;
use App\Livewire\Components\NotificationBell;
use App\Livewire\Pages\Dashboard\NotificationsIndex;
use App\Models\User;
use App\Support\Communications\AccountCommunications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->setLocale('en');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createBellTestNotification(User $user, array $overrides = []): NotificationInbox
{
    /** @var NotificationInbox $notification */
    $notification = createTestRecord(NotificationInbox::class, array_merge([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'trigger' => NotificationTrigger::EventCancelled->value,
        'priority' => NotificationPriority::Normal->value,
        'title' => 'Event update',
        'body' => 'Details changed.',
        'data' => [
            'channels_attempted' => ['in_app'],
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
        ],
        'read_at' => null,
    ], $overrides));

    return $notification;
}

it('does not render the notification bell for guests', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSeeHtml('data-testid="notification-bell"');
});

it('renders the bell with the unread count for the authenticated user', function () {
    $user = User::factory()->create();

    createBellTestNotification($user);
    createBellTestNotification($user, ['read_at' => now()]);
    createBellTestNotification($user, ['archived_at' => now()]);

    $otherUser = User::factory()->create();
    createBellTestNotification($otherUser);

    $this->actingAs($user)
        ->withSession(['locale' => 'en'])
        ->get(route('home'))
        ->assertOk()
        ->assertSeeHtml('data-testid="notification-bell"')
        ->assertSeeHtml('data-testid="notification-unread-count"')
        ->assertSee('Notifications, 1 unread');
});

it('loads the latest eight notifications for the current recipient only', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    foreach (range(1, 10) as $index) {
        createBellTestNotification($user, [
            'title' => "Notification {$index}",
            'created_at' => now()->subMinutes(10 - $index),
        ]);
    }

    createBellTestNotification($user, [
        'title' => 'Archived notification',
        'archived_at' => now(),
    ]);

    createBellTestNotification($otherUser, [
        'title' => 'Other recipient notification',
    ]);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSet('unreadCount', 10)
        ->call('loadNotifications')
        ->assertSet('loaded', true)
        ->assertSee('Notification 10')
        ->assertSee('Notification 3')
        ->assertDontSeeHtml('>Notification 2<')
        ->assertDontSeeHtml('>Notification 1<')
        ->assertDontSee('Archived notification')
        ->assertDontSee('Other recipient notification');
});

it('filters the panel to unread notifications', function () {
    $user = User::factory()->create();

    createBellTestNotification($user, ['title' => 'Unread notification']);
    createBellTestNotification($user, ['title' => 'Read notification', 'read_at' => now()]);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->call('loadNotifications')
        ->assertSee('Unread notification')
        ->assertSee('Read notification')
        ->call('setFilter', 'unread')
        ->assertSet('filter', 'unread')
        ->assertSee('Unread notification')
        ->assertDontSee('Read notification');
});

it('marks a notification read and follows its action url', function () {
    $user = User::factory()->create();
    $notification = createBellTestNotification($user, [
        'title' => 'Linked notification',
        'data' => [
            'channels_attempted' => ['in_app'],
            'action_url' => route('dashboard'),
        ],
    ]);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->call('loadNotifications')
        ->call('openNotification', $notification->getKey())
        ->assertRedirect(route('dashboard'));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('marks a notification read without redirecting when it has no action url', function () {
    $user = User::factory()->create();
    $notification = createBellTestNotification($user, [
        'title' => 'Unlinked notification',
    ]);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->call('loadNotifications')
        ->assertSet('unreadCount', 1)
        ->call('openNotification', $notification->getKey())
        ->assertNoRedirect()
        ->assertSet('unreadCount', 0)
        ->assertDispatched('notification-inbox-updated');

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('marks every unread notification as read', function () {
    $user = User::factory()->create();

    createBellTestNotification($user);
    createBellTestNotification($user);
    createBellTestNotification($user, ['read_at' => now()]);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSet('unreadCount', 2)
        ->call('markAllAsRead')
        ->assertSet('unreadCount', 0)
        ->assertDispatched('notification-inbox-updated');

    expect(app(AccountCommunications::class)->unreadInboxCount((string) $user->getKey()))->toBe(0);
});

it('notifies the bell when the full inbox marks a notification read', function () {
    $user = User::factory()->create();
    $notification = createBellTestNotification($user);

    Livewire::actingAs($user)
        ->test(NotificationsIndex::class)
        ->call('markAsRead', $notification->getKey())
        ->assertDispatched('notification-count-updated');

    Livewire::actingAs($user)
        ->test(NotificationsIndex::class)
        ->call('markAllAsRead')
        ->assertDispatched('notification-count-updated');
});

it('refreshes the bell count after an inbox read event', function () {
    $user = User::factory()->create();
    $notification = createBellTestNotification($user);

    $component = Livewire::actingAs($user)->test(NotificationBell::class);

    $notification->forceFill(['read_at' => now()])->save();

    $component->call('refreshUnreadCount')
        ->assertSet('unreadCount', 0);
});

it('shows the retry state when notifications fail to load', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->set('loaded', true)
        ->set('loadFailed', true)
        ->assertSee('Could not load notifications.')
        ->assertSee('Try again');
});

it('shows the empty state when there are no notifications', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->call('loadNotifications')
        ->assertSee('No notifications yet')
        ->assertSee('Updates about events');
});

it('shows the unread empty state when every notification is read', function () {
    $user = User::factory()->create();

    createBellTestNotification($user, ['read_at' => now()]);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->call('loadNotifications')
        ->call('setFilter', 'unread')
        ->assertSee("You're all caught up")
        ->assertSee('You have no unread notifications.');
});

it('groups notifications by the viewer timezone', function () {
    Carbon::setTestNow('2026-11-02 17:30:00');

    $user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);

    createBellTestNotification($user, [
        'title' => 'Today in Kuala Lumpur',
        'created_at' => '2026-11-02 16:30:00',
    ]);

    createBellTestNotification($user, [
        'title' => 'Earlier in Kuala Lumpur',
        'created_at' => '2026-11-02 15:30:00',
    ]);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->call('loadNotifications')
        ->assertSee('Today')
        ->assertSee('Earlier');
});

it('links see all notifications to the all view of the inbox', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSeeHtml('href="'.route('notifications', ['status' => 'all']).'"');
});
