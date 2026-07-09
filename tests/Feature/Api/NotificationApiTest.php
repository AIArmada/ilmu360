<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Enums\NotificationFamily;
use AIArmada\Communications\Enums\NotificationPriority;
use AIArmada\Communications\Enums\NotificationTrigger;
use AIArmada\Communications\Models\CommunicationDestination;
use AIArmada\Communications\Models\NotificationInbox;
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

    $destination = CommunicationDestination::query()
        ->where('recipient_id', $user->id)
        ->where('recipient_type', $user->getMorphClass())
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
    $destination = OwnerContext::withOwner(null, fn () => CommunicationDestination::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'channel' => 'push',
        'address' => 'installation-abc',
        'external_id' => 'old-token',
        'status' => 'active',
        'is_primary' => false,
        'verified_at' => now()->subDay(),
        'metadata' => [
            'platform' => 'ios',
            'app_version' => '1.0.0',
            'device_label' => 'Old Device',
            'locale' => 'en',
            'timezone' => 'UTC',
            'last_seen_at' => now()->subDay()->toIso8601String(),
        ],
    ]));

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

    OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'trigger' => NotificationTrigger::EventUpdated->value,
        'priority' => NotificationPriority::Normal->value,
        'title' => 'Schedule changed',
        'body' => 'Event timing has changed.',
        'data' => [
            'action_url' => '/events/schedule-changed',
            'entity_type' => 'event',
            'entity_id' => 'event-123',
            'occurred_at' => now()->subHour()->toIso8601String(),
            'channels_attempted' => ['in_app', 'email'],
            'meta' => ['source' => 'system'],
        ],
        'read_at' => null,
    ]));

    OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventUpdated->value,
        'title' => 'Read notification',
        'body' => 'Already read.',
        'data' => ['action_url' => null, 'entity_type' => null, 'entity_id' => null],
        'read_at' => now(),
    ]));

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.notifications.index'));

    $response->assertOk()
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.family', NotificationFamily::EventUpdate->value)
        ->assertJsonPath('data.0.trigger', NotificationTrigger::EventUpdated->value)
        ->assertJsonPath('data.0.title', 'Schedule changed')
        ->assertJsonPath('data.0.body', 'Event timing has changed.')
        ->assertJsonPath('data.0.action_url', '/events/schedule-changed')
        ->assertJsonPath('data.0.entity_type', 'event')
        ->assertJsonPath('data.0.entity_id', 'event-123')
        ->assertJsonPath('data.0.priority', NotificationPriority::Normal->value)
        ->assertJsonPath('data.0.read_at', null)
        ->assertJsonPath('data.0.channels_attempted.0', 'in_app')
        ->assertJsonPath('data.0.channels_attempted.1', 'email')
        ->assertJsonPath('data.0.meta.source', 'system');
});

it('marks a notification as read through the api', function () {
    $user = User::factory()->create();
    $message = OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventUpdated->value,
        'title' => 'Schedule changed',
        'body' => 'The event schedule has changed.',
        'data' => ['action_url' => null, 'entity_type' => null, 'entity_id' => null],
        'read_at' => null,
    ]));

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

    OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventUpdated->value,
        'title' => 'Unread 1',
        'body' => 'First unread notification.',
        'data' => ['action_url' => null, 'entity_type' => null, 'entity_id' => null],
        'read_at' => null,
    ]));

    OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventUpdated->value,
        'title' => 'Unread 2',
        'body' => 'Second unread notification.',
        'data' => ['action_url' => null, 'entity_type' => null, 'entity_id' => null],
        'read_at' => null,
    ]));

    OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventUpdated->value,
        'title' => 'Read notification',
        'body' => 'Already read.',
        'data' => ['action_url' => null, 'entity_type' => null, 'entity_id' => null],
        'read_at' => now(),
    ]));

    Sanctum::actingAs($user);

    $response = $this->postJson(route('api.notifications.read-all'));

    $response->assertOk()
        ->assertJsonPath('message', __('notifications.api.read_all_success'))
        ->assertJsonPath('data.updated_count', 2);

    $unreadCount = OwnerContext::withOwner(null, fn () => NotificationInbox::query()
        ->where('recipient_type', $user->getMorphClass())
        ->where('recipient_id', $user->getKey())
        ->whereNull('read_at')
        ->count());

    expect($unreadCount)->toBe(0);
});
