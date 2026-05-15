<?php

use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Models\NotificationDestination;
use App\Models\NotificationMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('registers a push destination through the api', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $lastSeenAt = now()->subMinute()->toIso8601String();

    $response = $this->postJson(route('api.notification-destinations.push.store'), [
        'installation_id' => 'installation-123',
        'platform' => 'ios',
        'fcm_token' => 'token-abc',
        'app_version' => '1.2.3',
        'device_label' => 'My iPhone',
        'locale' => 'en',
        'timezone' => 'Asia/Kuala_Lumpur',
        'last_seen_at' => $lastSeenAt,
    ]);

    $destination = NotificationDestination::query()
        ->where('user_id', $user->id)
        ->where('address', 'installation-123')
        ->firstOrFail();

    $response->assertCreated()
        ->assertJsonPath('message', __('notifications.api.push_registered'))
        ->assertJsonPath('data.id', $destination->id)
        ->assertJsonPath('data.installation_id', 'installation-123')
        ->assertJsonPath('data.platform', 'ios')
        ->assertJsonPath('data.device_label', 'My iPhone')
        ->assertJsonPath('data.app_version', '1.2.3')
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonPath('data.timezone', 'Asia/Kuala_Lumpur')
        ->assertJsonPath('data.last_seen_at', $lastSeenAt)
        ->assertJsonPath('data.verified_at', $destination->verified_at?->toIso8601String());
});

it('updates an existing push destination through the api', function () {
    $user = User::factory()->create();
    $destination = NotificationDestination::factory()->for($user)->create([
        'address' => 'installation-abc',
        'meta' => [
            'platform' => 'ios',
            'app_version' => '1.0.0',
            'device_label' => 'Old Device',
            'locale' => 'en',
            'timezone' => 'UTC',
            'last_seen_at' => now()->subDay()->toIso8601String(),
        ],
    ]);

    Sanctum::actingAs($user);

    $lastSeenAt = now()->toIso8601String();

    $response = $this->putJson(route('api.notification-destinations.push.update', 'installation-abc'), [
        'platform' => 'android',
        'fcm_token' => 'updated-token',
        'app_version' => '2.0.0',
        'device_label' => 'Pixel 9',
        'locale' => 'ms',
        'timezone' => 'Asia/Kuala_Lumpur',
        'last_seen_at' => $lastSeenAt,
    ]);

    $destination->refresh();

    $response->assertOk()
        ->assertJsonPath('message', __('notifications.api.push_updated'))
        ->assertJsonPath('data.id', $destination->id)
        ->assertJsonPath('data.installation_id', 'installation-abc')
        ->assertJsonPath('data.platform', 'android')
        ->assertJsonPath('data.device_label', 'Pixel 9')
        ->assertJsonPath('data.app_version', '2.0.0')
        ->assertJsonPath('data.locale', 'ms')
        ->assertJsonPath('data.timezone', 'Asia/Kuala_Lumpur')
        ->assertJsonPath('data.last_seen_at', $lastSeenAt)
        ->assertJsonPath('data.verified_at', $destination->verified_at?->toIso8601String());
});

it('lists serialized notification messages for the current user', function () {
    $user = User::factory()->create();

    NotificationMessage::factory()->for($user, 'notifiable')->create([
        'family' => NotificationFamily::EventUpdates->value,
        'trigger' => NotificationTrigger::EventScheduleChanged->value,
        'priority' => NotificationPriority::Medium->value,
        'data' => [
            'family' => NotificationFamily::EventUpdates->value,
            'trigger' => NotificationTrigger::EventScheduleChanged->value,
            'title' => 'Schedule changed',
            'body' => 'Event timing has changed.',
            'action_url' => '/events/schedule-changed',
            'entity_type' => 'event',
            'entity_id' => 'event-123',
            'priority' => NotificationPriority::Medium->value,
            'occurred_at' => now()->subHour()->toIso8601String(),
            'channels_attempted' => ['in_app', 'email'],
            'meta' => ['source' => 'system'],
        ],
        'action_url' => '/events/schedule-changed',
        'entity_type' => 'event',
        'entity_id' => 'event-123',
        'read_at' => null,
        'inbox_visible' => true,
    ]);

    NotificationMessage::factory()->for($user, 'notifiable')->create([
        'read_at' => now(),
        'inbox_visible' => false,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.notifications.index'));

    $response->assertOk()
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.family', NotificationFamily::EventUpdates->value)
        ->assertJsonPath('data.0.trigger', NotificationTrigger::EventScheduleChanged->value)
        ->assertJsonPath('data.0.title', 'Schedule changed')
        ->assertJsonPath('data.0.body', 'Event timing has changed.')
        ->assertJsonPath('data.0.action_url', '/events/schedule-changed')
        ->assertJsonPath('data.0.entity_type', 'event')
        ->assertJsonPath('data.0.entity_id', 'event-123')
        ->assertJsonPath('data.0.priority', NotificationPriority::Medium->value)
        ->assertJsonPath('data.0.read_at', null)
        ->assertJsonPath('data.0.channels_attempted.0', 'in_app')
        ->assertJsonPath('data.0.channels_attempted.1', 'email')
        ->assertJsonPath('data.0.meta.source', 'system');
});

it('marks a notification as read through the api', function () {
    $user = User::factory()->create();
    $message = NotificationMessage::factory()->for($user, 'notifiable')->create([
        'read_at' => null,
    ]);

    Sanctum::actingAs($user);

    $response = $this->postJson(route('api.notifications.read', $message->id));

    $message->refresh();

    $response->assertOk()
        ->assertJsonPath('message', __('notifications.api.read_success'))
        ->assertJsonPath('data.id', $message->id)
        ->assertJsonPath('data.read_at', $message->read_at?->toIso8601String());
});

it('marks all unread notifications as read through the api', function () {
    $user = User::factory()->create();

    NotificationMessage::factory()->count(2)->for($user, 'notifiable')->create([
        'read_at' => null,
        'inbox_visible' => true,
    ]);

    NotificationMessage::factory()->for($user, 'notifiable')->create([
        'read_at' => now(),
        'inbox_visible' => true,
    ]);

    Sanctum::actingAs($user);

    $response = $this->postJson(route('api.notifications.read-all'));

    $response->assertOk()
        ->assertJsonPath('message', __('notifications.api.read_all_success'))
        ->assertJsonPath('data.updated_count', 2);

    expect(NotificationMessage::query()
        ->where('notifiable_id', $user->id)
        ->whereNull('read_at')
        ->count())->toBe(0);
});
