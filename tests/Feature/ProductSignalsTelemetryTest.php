<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Enums\NotificationFamily;
use AIArmada\Communications\Enums\NotificationPriority;
use AIArmada\Communications\Enums\NotificationTrigger;
use AIArmada\Communications\Models\NotificationInbox;
use AIArmada\Signals\Models\SignalEvent;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Enums\EventVisibility;
use App\Livewire\Pages\Dashboard\NotificationsIndex;
use App\Models\Event;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\Signals\SignalEventRecorder;
use Illuminate\Auth\Events\Verified;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Mockery\MockInterface;

it('records a signals event for successful password login', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $event = SignalEvent::query()->where('event_name', 'auth.login')->latest('occurred_at')->first();

    expect($event)->not->toBeNull();
    expect($event?->event_category)->toBe('auth');
    expect(data_get($event?->properties, 'method'))->toBe('password');
    expect(data_get($event?->properties, 'client_origin'))->toBe('web');
    expect(data_get($event?->properties, 'client_transport'))->toBe('web');
    expect($event?->identity?->external_id)->toBe($user->id);
});

it('stitches login telemetry to browser identity cookies when present', function () {
    $user = User::factory()->create();

    $this->withCookie((string) config('product-signals.identity.anonymous_cookie'), 'sig_anon_browser')
        ->withCookie((string) config('product-signals.identity.session_cookie'), 'sig_session_browser')
        ->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertRedirect('/dashboard');

    $event = SignalEvent::query()->where('event_name', 'auth.login')->latest('occurred_at')->first();

    expect($event)->not->toBeNull();
    expect($event?->identity?->anonymous_id)->toBe('sig_anon_browser');
    expect($event?->session?->session_identifier)->toBe('sig_session_browser');
});

it('does not break password login when signals ingestion fails', function () {
    $this->mock(SignalEventRecorder::class, function (MockInterface $mock): void {
        $mock->shouldReceive('ingest')->andThrow(new RuntimeException('Signals ingestion failed.'));
    });

    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
    expect(SignalEvent::query()->where('event_name', 'auth.login')->exists())->toBeFalse();
});

it('records a signals event when a report is submitted', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/reports', [
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'category' => 'wrong_info',
        ])
        ->assertCreated();

    $signalEvent = SignalEvent::query()->where('event_name', 'report.submitted')->latest('occurred_at')->first();

    expect($signalEvent)->not->toBeNull();
    expect(data_get($signalEvent?->properties, 'entity_type'))->toBe('event');
    expect(data_get($signalEvent?->properties, 'entity_id'))->toBe($event->id);
});

it('records a signals event when signup completes', function () {
    $user = app(CreateNewUser::class)->create([
        'name' => 'Signals Signup',
        'email' => 'signals-signup@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $signalEvent = SignalEvent::query()->where('event_name', 'auth.signup.completed')->latest('occurred_at')->first();

    expect($user->email)->toBe('signals-signup@example.com');
    expect($signalEvent)->not->toBeNull();
    expect($signalEvent?->identity?->external_id)->toBe($user->id);
    expect(data_get($signalEvent?->properties, 'has_email'))->toBeTrue();
});

it('records a signals event when a password reset completes', function () {
    $user = User::factory()->create();

    app(ResetUserPassword::class)->reset($user, [
        'password' => 'ResetPassword123!',
        'password_confirmation' => 'ResetPassword123!',
    ]);

    $signalEvent = SignalEvent::query()->where('event_name', 'auth.password_reset.completed')->latest('occurred_at')->first();

    expect($signalEvent)->not->toBeNull();
    expect($signalEvent?->identity?->external_id)->toBe($user->id);
});

it('records a signals event when email verification completes', function () {
    $user = User::factory()->create();

    event(new Verified($user));

    $signalEvent = SignalEvent::query()->where('event_name', 'auth.email_verified')->latest('occurred_at')->first();

    expect($signalEvent)->not->toBeNull();
    expect($signalEvent?->identity?->external_id)->toBe($user->id);
});

it('does not break report submission when signals ingestion fails', function () {
    $this->mock(SignalEventRecorder::class, function (MockInterface $mock): void {
        $mock->shouldReceive('ingest')->andThrow(new RuntimeException('Signals ingestion failed.'));
    });

    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/reports', [
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'category' => 'wrong_info',
        ])
        ->assertCreated();

    expect(SignalEvent::query()->where('event_name', 'report.submitted')->exists())->toBeFalse();
});

it('records a signals event when a notification is read via the api', function () {
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

    $this->postJson("/api/v1/notifications/{$message->id}/read")
        ->assertOk();

    $signalEvent = SignalEvent::query()->where('event_name', 'notification.read')->latest('occurred_at')->first();

    expect($signalEvent)->not->toBeNull();
    expect(data_get($signalEvent?->properties, 'notification_id'))->toBe($message->id);
});

it('does not break notification reads when signals ingestion fails', function () {
    $this->mock(SignalEventRecorder::class, function (MockInterface $mock): void {
        $mock->shouldReceive('ingest')->andThrow(new RuntimeException('Signals ingestion failed.'));
    });

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

    $this->postJson("/api/v1/notifications/{$message->id}/read")
        ->assertOk();

    expect($message->fresh()?->read_at)->not->toBeNull();
    expect(SignalEvent::query()->where('event_name', 'notification.read')->exists())->toBeFalse();
});

it('records a signals event when all notifications are marked as read from the inbox page', function () {
    $user = User::factory()->create();

    OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventUpdated->value,
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

    OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventUpdated->value,
        'title' => 'Unread only 2',
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

    Livewire::actingAs($user)
        ->test(NotificationsIndex::class)
        ->call('markAllAsRead')
        ->assertHasNoErrors();

    $signalEvent = SignalEvent::query()->where('event_name', 'notification.read_all')->latest('occurred_at')->first();

    expect($signalEvent)->not->toBeNull();
    expect(data_get($signalEvent?->properties, 'updated_count'))->toBe(2);
});

it('records signals events for api and saved search executions', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'title' => 'Signals Search Event',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'is_active' => true,
    ]);
    $savedSearch = SavedSearch::factory()->create([
        'user_id' => $user->id,
        'query' => 'Signals',
        'filters' => ['language_codes' => ['ms']],
    ]);

    $this->getJson('/api/v1/events?filter[search]=Signals')
        ->assertOk();

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/saved-searches/{$savedSearch->id}/execute")
        ->assertOk();

    $searchEvents = SignalEvent::query()
        ->where('event_name', 'search.executed')
        ->orderBy('occurred_at')
        ->get();
    $surfaces = $searchEvents
        ->map(fn (SignalEvent $signalEvent): mixed => data_get($signalEvent->properties, 'surface'))
        ->filter(fn (mixed $surface): bool => is_string($surface) && $surface !== '')
        ->values()
        ->all();

    expect($searchEvents)->toHaveCount(2);
    expect($surfaces)->toContain('api.events.index', 'saved_search.execute');
    expect($event->title)->toBe('Signals Search Event');
});

it('records normalized client context for api and mobile search telemetry', function () {
    Event::factory()->create([
        'title' => 'Signals Client Context Event',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'is_active' => true,
    ]);

    $this->getJson('/api/v1/events?filter[search]=Default%20API')
        ->assertOk();

    $this->getJson('/api/v1/events?filter[search]=Mobile%20Android&origin=android')
        ->assertOk();

    $this->withHeaders([
        'X-Ilmu360-Client-Origin' => 'iosapp',
        'X-Ilmu360-Client-Name' => 'ilmu360 iOS',
        'X-Ilmu360-Client-Version' => '1.2.3',
        'X-Ilmu360-Client-Build' => '456',
    ])->getJson('/api/v1/events?filter[search]=Mobile%20iOS')
        ->assertOk();

    $signalEvents = SignalEvent::query()
        ->where('event_name', 'search.executed')
        ->get();

    $defaultApiEvent = $signalEvents->first(
        fn (SignalEvent $signalEvent): bool => data_get($signalEvent->properties, 'query') === 'Default API',
    );
    $iosEvent = $signalEvents->first(
        fn (SignalEvent $signalEvent): bool => data_get($signalEvent->properties, 'query') === 'Mobile iOS',
    );
    $androidEvent = $signalEvents->first(
        fn (SignalEvent $signalEvent): bool => data_get($signalEvent->properties, 'query') === 'Mobile Android',
    );

    expect($defaultApiEvent)->not->toBeNull();
    expect(data_get($defaultApiEvent?->properties, 'surface'))->toBe('api.events.index');
    expect(data_get($defaultApiEvent?->properties, 'client_origin'))->toBe('api');
    expect(data_get($defaultApiEvent?->properties, 'client_family'))->toBe('api');
    expect(data_get($defaultApiEvent?->properties, 'client_transport'))->toBe('api');

    expect($iosEvent)->not->toBeNull();
    expect(data_get($iosEvent?->properties, 'client_origin'))->toBe('ios');
    expect(data_get($iosEvent?->properties, 'client_origin_source'))->toBe('header:X-Ilmu360-Client-Origin');
    expect(data_get($iosEvent?->properties, 'client_platform'))->toBe('ios');
    expect(data_get($iosEvent?->properties, 'client_family'))->toBe('mobile');
    expect(data_get($iosEvent?->properties, 'client_transport'))->toBe('api');
    expect(data_get($iosEvent?->properties, 'client_name'))->toBe('ilmu360 iOS');
    expect(data_get($iosEvent?->properties, 'client_version'))->toBe('1.2.3');
    expect(data_get($iosEvent?->properties, 'client_build'))->toBe('456');

    expect($androidEvent)->not->toBeNull();
    expect(data_get($androidEvent?->properties, 'client_origin'))->toBe('android');
    expect(data_get($androidEvent?->properties, 'client_origin_source'))->toBe('query:origin');
    expect(data_get($androidEvent?->properties, 'client_platform'))->toBe('android');
    expect(data_get($androidEvent?->properties, 'client_family'))->toBe('mobile');
    expect(data_get($androidEvent?->properties, 'client_transport'))->toBe('api');
});

it('records listing filtered events for filter-only discovery traffic', function () {
    Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'is_active' => true,
    ]);

    $this->getJson('/api/v1/events?filter[status]=approved')
        ->assertOk();

    $signalEvent = SignalEvent::query()->where('event_name', 'listing.filtered')->latest('occurred_at')->first();

    expect($signalEvent)->not->toBeNull();
    expect($signalEvent?->event_category)->toBe('discovery');
    expect(data_get($signalEvent?->properties, 'interaction_type'))->toBe('filter');
});

it('does not break search execution when signals ingestion fails', function () {
    $this->mock(SignalEventRecorder::class, function (MockInterface $mock): void {
        $mock->shouldReceive('ingest')->andThrow(new RuntimeException('Signals ingestion failed.'));
    });

    $user = User::factory()->create();
    $savedSearch = SavedSearch::factory()->create([
        'user_id' => $user->id,
        'query' => 'Signals',
        'filters' => ['language_codes' => ['ms']],
    ]);

    $this->getJson('/api/v1/events?filter[search]=Signals')
        ->assertOk();

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/saved-searches/{$savedSearch->id}/execute")
        ->assertOk();

    expect(SignalEvent::query()->where('event_name', 'search.executed')->exists())->toBeFalse();
});
