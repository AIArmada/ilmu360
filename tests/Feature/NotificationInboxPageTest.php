<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
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

    OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
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
    ]));

    OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
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
    ]));

    OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
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
    ]));

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('dashboard.notifications'));

    $response->assertOk()
        ->assertSee('Notifications')
        ->assertSee('Inbox notification')
        ->assertDontSee('Hidden email-only notification')
        ->assertDontSee('Other user notification')
        ->assertSee('Mark all as read');
});

it('filters unread notifications and marks them as read in the inbox component', function () {
    $user = User::factory()->create();

    $unread = OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
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
    ]));

    $read = OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
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
    ]));

    Livewire::actingAs($user)
        ->test(NotificationsIndex::class)
        ->set('status', 'unread')
        ->assertSee('Unread only')
        ->assertDontSee('Read already')
        ->call('markAsRead', $unread->id)
        ->assertHasNoErrors();

    expect($unread->fresh()->read_at)->not->toBeNull();
});
