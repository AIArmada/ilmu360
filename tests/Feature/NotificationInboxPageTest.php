<?php

use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Livewire\Pages\Dashboard\NotificationsIndex;
use App\Models\NotificationMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the notifications inbox for authenticated users', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    NotificationMessage::factory()->for($user, 'notifiable')->create([
        'family' => NotificationFamily::EventUpdates->value,
        'trigger' => NotificationTrigger::EventCancelled->value,
        'priority' => NotificationPriority::Urgent->value,
        'read_at' => null,
        'data' => [
            'title' => 'Inbox notification',
            'body' => 'There is an update to your tracked event.',
            'channels_attempted' => ['in_app'],
            'meta' => ['inbox_visible' => true],
        ],
        'inbox_visible' => true,
    ]);

    NotificationMessage::factory()->for($user, 'notifiable')->create([
        'read_at' => null,
        'data' => [
            'title' => 'Hidden email-only notification',
            'body' => 'Email-only content',
            'channels_attempted' => ['email'],
            'meta' => ['inbox_visible' => false],
        ],
        'inbox_visible' => false,
    ]);

    NotificationMessage::factory()->for($otherUser, 'notifiable')->create([
        'data' => [
            'title' => 'Other user notification',
            'body' => 'Other body',
            'channels_attempted' => ['in_app'],
            'meta' => ['inbox_visible' => true],
        ],
    ]);

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

    $unread = NotificationMessage::factory()->for($user, 'notifiable')->create([
        'read_at' => null,
        'data' => [
            'title' => 'Unread only',
            'body' => 'Unread body',
            'channels_attempted' => ['in_app'],
            'meta' => ['inbox_visible' => true],
        ],
        'inbox_visible' => true,
    ]);

    $read = NotificationMessage::factory()->for($user, 'notifiable')->create([
        'read_at' => now(),
        'data' => [
            'title' => 'Read already',
            'body' => 'Read body',
            'channels_attempted' => ['in_app'],
            'meta' => ['inbox_visible' => true],
        ],
        'inbox_visible' => true,
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
