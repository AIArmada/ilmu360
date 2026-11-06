<?php

use AIArmada\Communications\Enums\NotificationFamily;
use AIArmada\Communications\Enums\NotificationPriority;
use AIArmada\Communications\Enums\NotificationTrigger;
use AIArmada\Communications\Models\NotificationInbox;
use App\Livewire\Pages\Dashboard\NotificationsIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the notifications inbox for authenticated users', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    createTestRecord(NotificationInbox::class, [
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'trigger' => NotificationTrigger::EventCancelled->value,
        'priority' => NotificationPriority::Urgent->value,
        'title' => 'Inbox notification',
        'body' => 'There is an update to your tracked event.',
        'data' => [
            'channels_attempted' => ['in_app'],
            'meta' => ['inbox_visible' => true],
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
        ],
        'read_at' => null,
    ]);

    createTestRecord(NotificationInbox::class, [
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventCancelled->value,
        'title' => 'Hidden email-only notification',
        'body' => 'Email-only content',
        'data' => [
            'channels_attempted' => ['email'],
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
        ],
        'read_at' => null,
        'archived_at' => now(),
    ]);

    createTestRecord(NotificationInbox::class, [
        'recipient_type' => $otherUser->getMorphClass(),
        'recipient_id' => $otherUser->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventCancelled->value,
        'title' => 'Other user notification',
        'body' => 'Other body',
        'data' => [
            'channels_attempted' => ['in_app'],
            'meta' => ['inbox_visible' => true],
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
        ],
        'read_at' => null,
    ]);

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('notifications'));

    $response->assertOk()
        ->assertSee('Notifications')
        ->assertSee('Inbox notification')
        ->assertDontSee('Hidden email-only notification')
        ->assertDontSee('Other user notification')
        ->assertSee('Mark all as read')
        ->assertSee('shadow-[0_24px_55px_-30px_rgba(63,71,47,0.48)]', false);
});

it('filters unread notifications and marks them as read in the inbox component', function () {
    $user = User::factory()->create();

    $unread = createTestRecord(NotificationInbox::class, [
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventCancelled->value,
        'title' => 'Unread only',
        'body' => 'Unread body',
        'data' => [
            'channels_attempted' => ['in_app'],
            'meta' => ['inbox_visible' => true],
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
        ],
        'read_at' => null,
    ]);

    $read = createTestRecord(NotificationInbox::class, [
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventCancelled->value,
        'title' => 'Read already',
        'body' => 'Read body',
        'data' => [
            'channels_attempted' => ['in_app'],
            'meta' => ['inbox_visible' => true],
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
        ],
        'read_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(NotificationsIndex::class)
        ->set('status', 'unread')
        ->assertSee('Unread only')
        ->assertDontSee('Read already')
        ->call('markAsRead', $unread->id)
        ->assertHasNoErrors();

    expect($unread->fresh()->read_at)->not->toBeNull();
});

it('filters by the inbox family stored on the notification row', function () {
    $user = User::factory()->create();

    $makeNotification = function (NotificationFamily $family, string $title) use ($user): NotificationInbox {
        return createTestRecord(NotificationInbox::class, [
            'recipient_type' => $user->getMorphClass(),
            'recipient_id' => $user->getKey(),
            'family' => $family->value,
            'priority' => NotificationPriority::Normal->value,
            'trigger' => NotificationTrigger::EventCancelled->value,
            'title' => $title,
            'body' => 'Body',
            'data' => ['action_url' => null],
            'read_at' => null,
        ]);
    };

    $makeNotification(NotificationFamily::EventUpdate, 'Tracked event update');
    $makeNotification(NotificationFamily::FollowUp, 'Followed content update');

    Livewire::actingAs($user)
        ->test(NotificationsIndex::class)
        ->set('family', 'event_updates')
        ->assertSee('Tracked event update')
        ->assertDontSee('Followed content update');
});

it('renders readable family, priority, channel, and timestamp labels', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);

    createTestRecord(NotificationInbox::class, [
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventCancelled->value,
        'title' => 'Readable labels',
        'body' => 'Body',
        'data' => [
            'channels_attempted' => ['in_app'],
            'occurred_at' => '2026-11-05T03:30:00+00:00',
            'action_url' => null,
        ],
        'read_at' => null,
    ]);

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('notifications'));

    $response
        ->assertOk()
        ->assertSee('Event Updates')
        ->assertSee('Medium')
        ->assertSee('In-app')
        ->assertSee('5 Nov 2026, 3:30 AM');
});

it('shows a helpful empty state when the inbox has no matches', function () {
    $user = User::factory()->create();

    $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('notifications'))
        ->assertOk()
        ->assertSee('Nothing to review yet')
        ->assertSee('Browse events')
        ->assertSee('View all notifications');
});

it('paginates the inbox with readable page controls', function () {
    $user = User::factory()->create();

    foreach (range(1, 13) as $index) {
        createTestRecord(NotificationInbox::class, [
            'recipient_type' => $user->getMorphClass(),
            'recipient_id' => $user->getKey(),
            'family' => NotificationFamily::EventUpdate->value,
            'priority' => NotificationPriority::Normal->value,
            'trigger' => NotificationTrigger::EventCancelled->value,
            'title' => "Notification {$index}",
            'body' => 'Body',
            'data' => ['action_url' => null],
            'read_at' => null,
        ]);
    }

    $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('notifications'))
        ->assertOk()
        ->assertSee('Showing 1–12 of 13 notifications')
        ->assertSee('1 / 2')
        ->assertSee('Next');
});
