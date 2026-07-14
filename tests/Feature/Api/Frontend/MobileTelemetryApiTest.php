<?php

use AIArmada\Signals\Contracts\SignalEventIngestor;
use AIArmada\Signals\Models\SignalEvent;
use App\Models\User;
use Mockery\MockInterface;

it('records batched native mobile telemetry events through the dedicated api', function () {
    $response = $this->withHeaders([
        'X-Ilmu360-Client-Origin' => 'iosapp',
        'X-Ilmu360-Client-Name' => 'ilmu360 iOS',
        'X-Ilmu360-Client-Version' => '1.2.3',
        'X-Ilmu360-Client-Build' => '456',
    ])->postJson('/api/v1/mobile/telemetry/events', [
        'anonymous_id' => 'ios-installation-123',
        'session_identifier' => 'ios-session-123',
        'session_started_at' => '2026-04-22T10:00:00Z',
        'events' => [
            [
                'event_name' => 'screen.viewed',
                'event_category' => 'navigation',
                'occurred_at' => '2026-04-22T10:00:05Z',
                'path' => '/home',
                'screen_name' => 'home',
                'properties' => [
                    'entrypoint' => 'push_notification',
                ],
            ],
            [
                'event_name' => 'ui.clicked',
                'event_category' => 'engagement',
                'occurred_at' => '2026-04-22T10:00:18Z',
                'path' => '/events/weekly-kuliah',
                'screen_name' => 'event_detail',
                'component' => 'register_button',
                'action' => 'tap',
            ],
        ],
    ]);

    $response->assertAccepted()
        ->assertJsonPath('message', 'Mobile telemetry accepted.')
        ->assertJsonPath('data.received_events', 2)
        ->assertJsonPath('data.recorded_events', 2)
        ->assertJsonPath('data.dropped_events', 0)
        ->assertJsonPath('data.authenticated', false)
        ->assertJsonPath('data.client.client_origin', 'ios')
        ->assertJsonPath('data.client.client_family', 'mobile')
        ->assertJsonPath('data.client.client_transport', 'api')
        ->assertJsonPath('data.client.client_name', 'ilmu360 iOS')
        ->assertJsonPath('data.client.client_version', '1.2.3')
        ->assertJsonPath('data.client.client_build', '456');

    $screenEvent = SignalEvent::query()->where('event_name', 'screen.viewed')->first();
    $clickEvent = SignalEvent::query()->where('event_name', 'ui.clicked')->first();

    expect($screenEvent)->not->toBeNull()
        ->and($clickEvent)->not->toBeNull()
        ->and($screenEvent?->identity?->anonymous_id)->toBe('ios-installation-123')
        ->and($screenEvent?->session?->session_identifier)->toBe('ios-session-123')
        ->and(data_get($screenEvent?->properties, 'screen_name'))->toBe('home')
        ->and(data_get($screenEvent?->properties, 'entrypoint'))->toBe('push_notification')
        ->and(data_get($screenEvent?->properties, 'client_origin'))->toBe('ios')
        ->and(data_get($screenEvent?->properties, 'client_name'))->toBe('ilmu360 iOS')
        ->and(data_get($clickEvent?->properties, 'component'))->toBe('register_button')
        ->and(data_get($clickEvent?->properties, 'action'))->toBe('tap')
        ->and(data_get($clickEvent?->properties, 'telemetry_channel'))->toBe('native_mobile_api');
});

it('attaches the authenticated user to native mobile telemetry events when a bearer token is present', function () {
    $user = User::factory()->create();
    $token = $user->createToken('ios-device')->plainTextToken;

    $this->withToken($token)
        ->withHeaders([
            'X-Ilmu360-Client-Origin' => 'androidapp',
            'X-Ilmu360-Client-Name' => 'ilmu360 Android',
        ])
        ->postJson('/api/v1/mobile/telemetry/events', [
            'anonymous_id' => 'android-installation-123',
            'events' => [
                [
                    'event_name' => 'screen.viewed',
                    'screen_name' => 'discover',
                ],
            ],
        ])
        ->assertAccepted()
        ->assertJsonPath('data.authenticated', true)
        ->assertJsonPath('data.client.client_origin', 'android');

    $event = SignalEvent::query()->where('event_name', 'screen.viewed')->first();

    expect($event)->not->toBeNull()
        ->and($event?->identity?->external_id)->toBe($user->id)
        ->and($event?->identity?->anonymous_id)->toBe('android-installation-123')
        ->and(data_get($event?->properties, 'client_origin'))->toBe('android');
});

it('requires an anonymous or session identifier when native mobile telemetry is anonymous', function () {
    $this->withHeaders([
        'X-Ilmu360-Client-Origin' => 'iosapp',
    ])->postJson('/api/v1/mobile/telemetry/events', [
        'events' => [
            [
                'event_name' => 'screen.viewed',
                'screen_name' => 'home',
            ],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['anonymous_id']);
});

it('rejects non-native origins on the dedicated mobile telemetry endpoint', function () {
    $this->withHeaders([
        'X-Ilmu360-Client-Origin' => 'web',
    ])->postJson('/api/v1/mobile/telemetry/events', [
        'anonymous_id' => 'browser-installation-123',
        'events' => [
            [
                'event_name' => 'screen.viewed',
                'screen_name' => 'home',
            ],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['client_origin']);
});

it('does not allow query origins to replace the required native mobile origin header', function () {
    $this->postJson('/api/v1/mobile/telemetry/events?origin=android', [
        'anonymous_id' => 'android-installation-123',
        'events' => [
            [
                'event_name' => 'screen.viewed',
                'screen_name' => 'home',
            ],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['client_origin']);
});

it('prefers the explicit native header origin over conflicting query metadata', function () {
    $this->withHeaders([
        'X-Ilmu360-Client-Origin' => 'iosapp',
    ])->postJson('/api/v1/mobile/telemetry/events?origin=web', [
        'anonymous_id' => 'ios-installation-123',
        'events' => [
            [
                'event_name' => 'screen.viewed',
                'screen_name' => 'discover',
            ],
        ],
    ])
        ->assertAccepted()
        ->assertJsonPath('data.client.client_origin', 'ios');

    $event = SignalEvent::query()->where('event_name', 'screen.viewed')->first();

    expect($event)->not->toBeNull()
        ->and(data_get($event?->properties, 'client_origin'))->toBe('ios')
        ->and(data_get($event?->properties, 'client_origin_source'))->toBe('header:X-Ilmu360-Client-Origin');
});

it('accepts native mobile telemetry even when ingestion fails and reports dropped events', function () {
    $this->mock(SignalEventIngestor::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('Signals ingestion failed.'));
    });

    $this->withHeaders([
        'X-Ilmu360-Client-Origin' => 'iosapp',
    ])->postJson('/api/v1/mobile/telemetry/events', [
        'anonymous_id' => 'ios-installation-123',
        'events' => [
            [
                'event_name' => 'screen.viewed',
                'screen_name' => 'home',
            ],
        ],
    ])
        ->assertAccepted()
        ->assertJsonPath('data.received_events', 1)
        ->assertJsonPath('data.recorded_events', 0)
        ->assertJsonPath('data.dropped_events', 1);

    expect(SignalEvent::query()->where('event_name', 'screen.viewed')->exists())->toBeFalse();
});
