<?php

use AIArmada\Communications\Models\CommunicationDestination;
use App\Features\Communications\Api\PushDestinationManagement;
use App\Features\Communications\Api\PushDestinationSnapshot;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('routes only active account push installations as ordered immutable snapshots', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $secondary = createTestRecord(CommunicationDestination::class, [
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'channel' => 'push',
        'address' => 'secondary-installation',
        'external_id' => 'secondary-token',
        'status' => 'active',
        'is_primary' => false,
    ]);
    $primary = createTestRecord(CommunicationDestination::class, [
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'channel' => 'push',
        'address' => 'primary-installation',
        'external_id' => 'primary-token',
        'status' => 'active',
        'is_primary' => true,
    ]);
    createTestRecord(CommunicationDestination::class, [
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'channel' => 'push',
        'address' => 'inactive-installation',
        'external_id' => 'inactive-token',
        'status' => 'inactive',
        'is_primary' => true,
    ]);
    createTestRecord(CommunicationDestination::class, [
        'recipient_type' => $otherUser->getMorphClass(),
        'recipient_id' => $otherUser->getKey(),
        'channel' => 'push',
        'address' => 'other-installation',
        'external_id' => 'other-token',
        'status' => 'active',
        'is_primary' => true,
    ]);

    $destinations = $user->routeNotificationForPush();

    expect($destinations)->toHaveCount(2)
        ->and($destinations[0])->toBeInstanceOf(PushDestinationSnapshot::class)
        ->and($destinations[0]->id)->toBe((string) $primary->getKey())
        ->and($destinations[0]->fcmToken)->toBe('primary-token')
        ->and($destinations[1]->id)->toBe((string) $secondary->getKey());

    expect($destinations[0])->not->toBeInstanceOf(CommunicationDestination::class);
});

it('sends push notifications using tokens from destination snapshots', function (): void {
    $user = User::factory()->create();
    createTestRecord(CommunicationDestination::class, [
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'channel' => 'push',
        'address' => 'push-installation',
        'external_id' => 'push-token',
        'status' => 'active',
        'is_primary' => true,
    ]);

    config([
        'notification-center.push.project_id' => 'test-project',
        'notification-center.push.credentials' => 'credentials',
        'notification-center.push.endpoint' => 'https://push.test/v1/projects/{project}/messages:send',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://push.test/v1/projects/test-project/messages:send' => Http::response([
            'name' => 'projects/test-project/messages/1',
        ]),
    ]);

    $notification = new class extends Notification
    {
        /** @return array{title: string, body: string, family: string, trigger: string, action_url: string, notification_id: string} */
        public function toPush(object $notifiable): array
        {
            return [
                'title' => 'A new update',
                'body' => 'The schedule changed.',
                'family' => 'event_updates',
                'trigger' => 'event_updated',
                'action_url' => '/events/changed',
                'notification_id' => 'notification-1',
            ];
        }
    };

    $result = app(PushChannel::class)->send($user, $notification);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'message.token') === 'push-token');

    expect($result['results'][0]['destination_id'])->toBe((string) CommunicationDestination::query()
        ->where('recipient_id', $user->getKey())
        ->where('channel', 'push')
        ->value('id'))
        ->and($result['results'][0]['status'])->toBe('delivered');
});

it('does not remove another account push destination by id', function (): void {
    $account = User::factory()->create();
    $owner = User::factory()->create();
    $destination = createTestRecord(CommunicationDestination::class, [
        'recipient_type' => $owner->getMorphClass(),
        'recipient_id' => $owner->getKey(),
        'channel' => 'push',
        'address' => 'owner-installation',
        'external_id' => 'owner-token',
        'status' => 'active',
    ]);

    $removed = app(PushDestinationManagement::class)->removeById(
        (string) $account->getKey(),
        (string) $destination->getKey(),
    );

    expect($removed)->toBeFalse();
    $this->assertModelExists($destination);
});
