<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Enums\NotificationFamily;
use AIArmada\Communications\Enums\NotificationPriority;
use AIArmada\Communications\Enums\NotificationTrigger;
use AIArmada\Communications\Models\NotificationInbox;
use App\Models\NotificationDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('requires authentication for notification center api endpoints', function () {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
    $this->getJson('/api/v1/notification-settings')->assertUnauthorized();
});

it('returns notification settings catalog and current state', function () {
    $user = User::factory()->create([
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/notification-settings/catalog')
        ->assertOk()
        ->assertJsonPath('data.families.0.key', 'followed_content')
        ->assertJsonPath('data.triggers.0.family', 'followed_content');

    $this->getJson('/api/v1/notification-settings')
        ->assertOk()
        ->assertJsonPath('data.settings.timezone', 'Asia/Kuala_Lumpur')
        ->assertJsonPath('data.families.followed_content.scope_key', 'followed_content');
});

it('updates notification settings through the api', function () {
    $user = User::factory()->create([
        'timezone' => 'Asia/Kuala_Lumpur',
        'phone' => '+60123456789',
        'phone_verified_at' => now(),
    ]);

    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/notification-settings', [
        'settings' => [
            'locale' => 'en',
            'quiet_hours_start' => '21:30',
            'quiet_hours_end' => '06:30',
            'preferred_channels' => ['push', 'in_app'],
        ],
        'families' => [
            'submission_workflow' => [
                'enabled' => true,
                'cadence' => 'instant',
                'channels' => ['whatsapp', 'email'],
            ],
        ],
        'triggers' => [
            'submission_cancelled' => [
                'enabled' => true,
                'inherits_family' => false,
                'cadence' => 'instant',
                'channels' => ['whatsapp'],
                'urgent_override' => true,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.settings.locale', 'en')
        ->assertJsonPath('data.settings.quiet_hours_start', '21:30:00')
        ->assertJsonPath('data.families.submission_workflow.channels.0', 'whatsapp')
        ->assertJsonPath('data.triggers.submission_cancelled.channels.0', 'whatsapp')
        ->assertJsonPath('data.triggers.submission_cancelled.inherits_family', false);
});

it('lists notifications and marks them as read through the api', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $message = OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'trigger' => NotificationTrigger::EventCancelled->value,
        'priority' => NotificationPriority::Normal->value,
        'title' => 'Cancelled event',
        'body' => 'Cancelled body',
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
        'trigger' => NotificationTrigger::EventCancelled->value,
        'priority' => NotificationPriority::Normal->value,
        'title' => 'Hidden email-only event',
        'body' => 'Hidden body',
        'data' => [
            'channels_attempted' => ['email'],
            'meta' => ['inbox_visible' => false],
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
        ],
        'read_at' => null,
        'archived_at' => now(),
    ]));

    $this->getJson('/api/v1/notifications?family=event_update&status=unread')
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data.0.id', $message->id);

    $this->postJson("/api/v1/notifications/{$message->id}/read")
        ->assertOk()
        ->assertJsonPath('data.read_at', fn (string $readAt) => filled($readAt));

    $this->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.updated_count', 0);
});

it('registers updates and removes push destinations through the api', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $createResponse = $this->postJson('/api/v1/notification-destinations/push', [
        'installation_id' => 'iphone-1',
        'platform' => 'ios',
        'fcm_token' => 'token-1',
        'device_label' => 'Aiman iPhone',
        'locale' => 'ms',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('data.installation_id', 'iphone-1')
        ->assertJsonPath('data.platform', 'ios');

    $this->putJson('/api/v1/notification-destinations/push/iphone-1', [
        'platform' => 'ios',
        'fcm_token' => 'token-2',
        'device_label' => 'Aiman iPhone Pro',
        'locale' => 'en',
        'timezone' => 'Asia/Jakarta',
    ])->assertOk()
        ->assertJsonPath('data.device_label', 'Aiman iPhone Pro')
        ->assertJsonPath('data.locale', 'en');

    expect(NotificationDestination::query()
        ->where('user_id', $user->id)
        ->where('address', 'iphone-1')
        ->value('external_id'))->toBe('token-2');

    $this->deleteJson('/api/v1/notification-destinations/push/iphone-1')
        ->assertNoContent();

    $this->assertDatabaseMissing('notification_destinations', [
        'user_id' => $user->id,
        'address' => 'iphone-1',
    ]);
});
