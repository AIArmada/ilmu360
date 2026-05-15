<?php

use App\Models\NotificationMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns the notification settings catalog and bootstrapped user state', function () {
    $user = User::factory()->create([
        'email' => 'notify@example.test',
        'phone' => '+60128889999',
        'phone_verified_at' => now(),
    ]);

    Sanctum::actingAs($user);

    $catalogResponse = $this->getJson('/api/v1/notification-settings/catalog');
    $stateResponse = $this->getJson('/api/v1/notification-settings');

    $catalogResponse->assertOk()
        ->assertJsonPath('data.families.0.key', 'followed_content')
        ->assertJsonPath('data.triggers.0.key', 'followed_speaker_event')
        ->assertJsonPath('data.options.cadences.instant', __('notifications.options.cadence.instant'));

    $stateResponse->assertOk()
        ->assertJsonPath('data.settings.locale', config('app.locale'))
        ->assertJsonPath('data.destinations.email.address', 'notify@example.test')
        ->assertJsonPath('data.destinations.whatsapp.address', '+60128889999')
        ->assertJsonPath('data.families.event_updates.scope_key', 'event_updates')
        ->assertJsonPath('data.triggers.event_cancelled.scope_key', 'event_cancelled');
});

it('updates notification settings through the authenticated api', function () {
    $user = User::factory()->create([
        'email' => 'notify@example.test',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/notification-settings', [
        'settings' => [
            'locale' => 'en',
            'timezone' => 'Asia/Kuala_Lumpur',
            'quiet_hours_start' => '22:30',
            'quiet_hours_end' => '06:30',
            'digest_delivery_time' => '09:15',
            'digest_weekly_day' => 4,
            'preferred_channels' => ['push', 'in_app', 'email'],
            'fallback_strategy' => 'in_app_only',
            'urgent_override' => true,
        ],
        'families' => [
            'event_updates' => [
                'enabled' => true,
                'cadence' => 'instant',
                'channels' => ['in_app', 'email', 'push'],
            ],
        ],
        'triggers' => [
            'event_cancelled' => [
                'inherits_family' => false,
                'enabled' => true,
                'cadence' => 'instant',
                'channels' => ['in_app', 'email', 'whatsapp'],
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.settings.locale', 'en')
        ->assertJsonPath('data.settings.digest_weekly_day', 4)
        ->assertJsonPath('data.settings.preferred_channels.0', 'push')
        ->assertJsonPath('data.settings.fallback_strategy', 'in_app_only')
        ->assertJsonPath('data.families.event_updates.channels.2', 'push')
        ->assertJsonPath('data.triggers.event_cancelled.channels.2', 'whatsapp');
});

it('registers, updates, and removes push destinations through the api', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $storeResponse = $this->postJson('/api/v1/notification-destinations/push', [
        'installation_id' => 'ios-primary',
        'fcm_token' => 'token-one',
        'platform' => 'ios',
        'app_version' => '1.2.3',
        'device_label' => 'Aiman iPhone',
        'locale' => 'ms',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $storeResponse->assertCreated()
        ->assertJsonPath('data.installation_id', 'ios-primary')
        ->assertJsonPath('data.platform', 'ios')
        ->assertJsonPath('data.device_label', 'Aiman iPhone');

    $this->assertDatabaseHas('notification_destinations', [
        'user_id' => $user->id,
        'channel' => 'push',
        'address' => 'ios-primary',
        'external_id' => 'token-one',
    ]);

    $updateResponse = $this->putJson('/api/v1/notification-destinations/push/ios-primary', [
        'fcm_token' => 'token-two',
        'platform' => 'android',
        'device_label' => 'Aiman Android',
        'locale' => 'en',
        'timezone' => 'Asia/Jakarta',
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('data.platform', 'android')
        ->assertJsonPath('data.device_label', 'Aiman Android');

    $this->assertDatabaseHas('notification_destinations', [
        'user_id' => $user->id,
        'channel' => 'push',
        'address' => 'ios-primary',
        'external_id' => 'token-two',
    ]);

    $deleteResponse = $this->deleteJson('/api/v1/notification-destinations/push/ios-primary');

    $deleteResponse->assertNoContent();

    $this->assertDatabaseMissing('notification_destinations', [
        'user_id' => $user->id,
        'channel' => 'push',
        'address' => 'ios-primary',
    ]);
});

it('lists notifications and marks them as read through the api', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $unread = NotificationMessage::factory()->for($user, 'notifiable')->create([
        'read_at' => null,
        'data' => [
            'title' => 'Unread notification',
            'body' => 'Unread body',
            'channels_attempted' => ['in_app'],
            'meta' => ['inbox_visible' => true],
        ],
        'inbox_visible' => true,
    ]);
    $read = NotificationMessage::factory()->for($user, 'notifiable')->create([
        'read_at' => now(),
        'data' => [
            'title' => 'Read notification',
            'body' => 'Read body',
            'channels_attempted' => ['in_app'],
            'meta' => ['inbox_visible' => true],
        ],
        'inbox_visible' => true,
    ]);
    NotificationMessage::factory()->for($user, 'notifiable')->create([
        'read_at' => null,
        'data' => [
            'title' => 'Hidden email-only notification',
            'body' => 'Hidden body',
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

    Sanctum::actingAs($user);

    $indexResponse = $this->getJson('/api/v1/notifications?status=unread&per_page=5');

    $indexResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $unread->id)
        ->assertJsonPath('meta.unread_count', 1);

    $markReadResponse = $this->postJson('/api/v1/notifications/'.$unread->id.'/read');

    $markReadResponse->assertOk()
        ->assertJsonPath('message', __('notifications.api.read_success'));

    $markAllResponse = $this->postJson('/api/v1/notifications/read-all');

    $markAllResponse->assertOk()
        ->assertJsonPath('data.updated_count', 0);

    expect($unread->fresh()->read_at)->not->toBeNull()
        ->and($read->fresh()->read_at)->not->toBeNull();
});
