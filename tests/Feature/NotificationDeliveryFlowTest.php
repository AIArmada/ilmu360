<?php

use App\Enums\NotificationCadence;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Jobs\DispatchNotificationDigests;
use App\Models\Event;
use App\Models\Institution;
use App\Models\NotificationDestination;
use App\Models\PendingNotification;
use App\Models\Registration;
use App\Models\Speaker;
use App\Models\User;
use App\Notifications\Channels\Exceptions\ChannelDeliveryException;
use App\Notifications\NotificationCenterMessage;
use App\Services\Notifications\EventNotificationService;
use App\Services\Notifications\NotificationDeliveryLogger;
use App\Services\Notifications\NotificationEngine;
use App\Services\Notifications\NotificationSettingsManager;
use App\Support\Notifications\NotificationDispatchData;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

it('creates pending followed-content notifications when a public approved future event is published', function () {
    Http::fake();

    $user = User::factory()->create([
        'email' => 'follower@example.test',
    ]);
    $speaker = Speaker::factory()->create([
        'name' => 'Ustaz Aiman',
    ]);
    $institution = Institution::factory()->create();
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Tafsir Malam Jumaat',
        'status' => 'approved',
        'visibility' => 'public',
        'is_active' => true,
        'starts_at' => now()->addDays(5),
    ]);

    $event->speakers()->attach($speaker->id);
    $user->follow($speaker);

    app(EventNotificationService::class)->notifyPublication($event->fresh('speakers'));

    $pending = PendingNotification::query()
        ->where('user_id', $user->id)
        ->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->trigger->value)->toBe(NotificationTrigger::FollowedSpeakerEvent->value)
        ->and($pending->first()->title)->toContain('Majlis Tafsir Malam Jumaat');
});

it('localizes followed-content notifications per recipient locale and timezone', function () {
    Http::fake();

    $speaker = Speaker::factory()->create(['name' => 'Ustaz Aiman']);
    $institution = Institution::factory()->create();
    $startsAt = CarbonImmutable::now('UTC')->addDays(10)->setTime(12, 0);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Tafsir Malam Jumaat',
        'status' => 'approved',
        'visibility' => 'public',
        'is_active' => true,
        'starts_at' => $startsAt,
    ]);

    $event->speakers()->attach($speaker->id);

    $englishUser = User::factory()->create(['email' => 'english@example.test']);
    $malayUser = User::factory()->create(['email' => 'malay@example.test']);

    $englishUser->follow($speaker);
    $malayUser->follow($speaker);

    app(NotificationSettingsManager::class)->save($englishUser, [
        'settings' => [
            'locale' => 'en',
            'timezone' => 'UTC',
        ],
    ]);

    app(NotificationSettingsManager::class)->save($malayUser, [
        'settings' => [
            'locale' => 'ms',
            'timezone' => 'Asia/Kuala_Lumpur',
        ],
    ]);

    app(EventNotificationService::class)->notifyPublication($event->fresh('speakers'));

    $englishPending = PendingNotification::query()
        ->where('user_id', $englishUser->id)
        ->where('trigger', NotificationTrigger::FollowedSpeakerEvent->value)
        ->firstOrFail();

    $malayPending = PendingNotification::query()
        ->where('user_id', $malayUser->id)
        ->where('trigger', NotificationTrigger::FollowedSpeakerEvent->value)
        ->firstOrFail();

    expect($englishPending->title)->toBe('Majlis Tafsir Malam Jumaat matches something you follow')
        ->and($englishPending->body)->toContain('12:00 PM')
        ->and($malayPending->title)->toBe('Majlis Tafsir Malam Jumaat sepadan dengan sesuatu yang anda ikuti')
        ->and($malayPending->body)->toContain('08:00 PM');
});

it('renders queued notification content using the recipient locale and timezone at send time', function () {
    Http::fake();

    $speaker = Speaker::factory()->create(['name' => 'Ustaz Aiman']);
    $institution = Institution::factory()->create();
    $startsAt = CarbonImmutable::now('UTC')->addDays(10)->setTime(12, 0);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Tafsir Malam Jumaat',
        'status' => 'approved',
        'visibility' => 'public',
        'is_active' => true,
        'starts_at' => $startsAt,
    ]);

    $event->speakers()->attach($speaker->id);

    $user = User::factory()->create(['email' => 'queued-render@example.test']);
    $user->follow($speaker);

    app(NotificationSettingsManager::class)->save($user, [
        'settings' => [
            'locale' => 'en',
            'timezone' => 'UTC',
        ],
    ]);

    app(EventNotificationService::class)->notifyPublication($event->fresh('speakers'));

    $pending = PendingNotification::query()
        ->where('user_id', $user->id)
        ->where('trigger', NotificationTrigger::FollowedSpeakerEvent->value)
        ->firstOrFail();

    expect($pending->title)->toBe('Majlis Tafsir Malam Jumaat matches something you follow')
        ->and($pending->body)->toContain('12:00 PM');

    app(NotificationSettingsManager::class)->save($user->fresh(), [
        'settings' => [
            'locale' => 'ms',
            'timezone' => 'Asia/Kuala_Lumpur',
        ],
    ]);

    $notification = NotificationCenterMessage::fromPending(
        pending: $pending->fresh(),
        targetChannel: NotificationChannel::Email,
        family: NotificationFamily::FollowedContent,
        trigger: NotificationTrigger::FollowedSpeakerEvent,
        priority: NotificationPriority::Low,
        channelsAttempted: [NotificationChannel::Email->value],
        fallbackChannels: [],
        fallbackStrategy: 'skip',
        bypassQuietHours: false,
    );

    $user = $user->fresh()->withoutRelations();
    $originalLocale = app()->getLocale();
    app()->setLocale($user->preferredLocale());

    try {
        $mail = $notification->toMail($user);
    } finally {
        app()->setLocale($originalLocale);
    }

    $introLines = array_map(static fn (mixed $line): string => trim(strip_tags((string) $line)), $mail->introLines);

    expect($mail->subject)->toBe('Majlis Tafsir Malam Jumaat sepadan dengan sesuatu yang anda ikuti')
        ->and(implode(' ', $introLines))->toContain('Ustaz Aiman')
        ->and(implode(' ', $introLines))->toContain('08:00 PM');
});

it('creates reminder notifications only for going and registered users, not saved users', function () {
    Http::fake();

    $savedUser = User::factory()->create(['email' => 'saved@example.test']);
    $goingUser = User::factory()->create(['email' => 'going@example.test']);
    $registeredUser = User::factory()->create(['email' => 'registered@example.test']);
    $institution = Institution::factory()->create();
    $event = Event::factory()->for($institution)->create([
        'title' => 'Kuliah Subuh Khas',
        'status' => 'approved',
        'visibility' => 'public',
        'is_active' => true,
        'starts_at' => now()->addHours(24)->addMinutes(5),
    ]);

    $savedUser->savedEvents()->attach($event->id);
    $goingUser->goingEvents()->attach($event->id);
    Registration::factory()->for($event)->for($registeredUser)->create([
        'status' => 'registered',
    ]);

    app(EventNotificationService::class)->dispatchDueReminderNotifications(now()->toImmutable());

    expect(PendingNotification::query()->where('user_id', $savedUser->id)->count())->toBe(0)
        ->and(PendingNotification::query()->where('user_id', $goingUser->id)->where('trigger', NotificationTrigger::Reminder24Hours->value)->count())->toBe(1)
        ->and(PendingNotification::query()->where('user_id', $registeredUser->id)->where('trigger', NotificationTrigger::Reminder24Hours->value)->count())->toBe(1);
});

it('queues a delayed push notification during quiet hours when urgent override is disabled', function () {
    Queue::fake();
    config()->set('notification-center.push.project_id', 'majlis-test');
    config()->set('notification-center.push.credentials', 'token');

    $now = CarbonImmutable::parse('2026-03-09 23:15:00', 'Asia/Kuala_Lumpur');
    Carbon::setTestNow($now);
    CarbonImmutable::setTestNow($now);

    try {
        $user = User::factory()->create([
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        NotificationDestination::factory()->for($user)->create([
            'channel' => NotificationChannel::Push->value,
            'address' => 'device-1',
            'external_id' => 'push-token-1',
        ]);

        app(NotificationSettingsManager::class)->save($user, [
            'settings' => [
                'timezone' => 'Asia/Kuala_Lumpur',
                'quiet_hours_start' => '22:00',
                'quiet_hours_end' => '07:00',
                'preferred_channels' => ['push'],
                'fallback_channels' => ['email'],
                'urgent_override' => false,
            ],
            'families' => [
                'event_updates' => [
                    'enabled' => true,
                    'cadence' => 'instant',
                    'channels' => ['push'],
                ],
            ],
        ]);

        $pending = app(NotificationEngine::class)->dispatchToUser($user, new NotificationDispatchData(
            trigger: NotificationTrigger::EventCancelled,
            title: 'Event cancelled',
            body: 'This should wait until quiet hours end.',
            fingerprint: 'quiet-hours-without-override',
            bypassQuietHours: true,
        ));

        expect($pending)->toBeInstanceOf(PendingNotification::class);

        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($pending): bool {
            if (! $job->notification instanceof NotificationCenterMessage) {
                return false;
            }

            if ($job->notification->pendingNotificationId !== $pending->id || $job->notification->targetChannel !== NotificationChannel::Push) {
                return false;
            }

            if (! $job->delay instanceof DateTimeInterface) {
                return false;
            }

            return CarbonImmutable::instance($job->delay)
                ->timezone('Asia/Kuala_Lumpur')
                ->format('Y-m-d H:i') === '2026-03-10 07:00';
        });
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
});

it('uses configured fallback channels on queued notifications', function () {
    Queue::fake();
    config()->set('notification-center.push.project_id', 'majlis-test');
    config()->set('notification-center.push.credentials', 'token');

    $user = User::factory()->create([
        'email' => 'fallback@example.test',
        'phone' => '+60129990000',
        'phone_verified_at' => now(),
    ]);

    NotificationDestination::factory()->for($user)->create([
        'channel' => NotificationChannel::Push->value,
        'address' => 'device-2',
        'external_id' => 'push-token-2',
    ]);

    app(NotificationSettingsManager::class)->save($user, [
        'settings' => [
            'preferred_channels' => ['push', 'whatsapp', 'email'],
            'fallback_channels' => ['email'],
            'fallback_strategy' => 'next_available',
        ],
        'families' => [
            'event_updates' => [
                'enabled' => true,
                'cadence' => 'instant',
                'channels' => ['push', 'whatsapp', 'email'],
            ],
        ],
    ]);

    $pending = app(NotificationEngine::class)->dispatchToUser($user, new NotificationDispatchData(
        trigger: NotificationTrigger::EventCancelled,
        title: 'Fallback test',
        body: 'Push should use the configured fallback list.',
        fingerprint: 'configured-fallback-order',
    ));

    expect($pending)->toBeInstanceOf(PendingNotification::class);

    Queue::assertPushed(SendQueuedNotifications::class, 1);

    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof NotificationCenterMessage
        && $job->notification->pendingNotificationId === $pending->id
        && $job->notification->targetChannel === NotificationChannel::Push
        && $job->notification->fallbackChannels === [NotificationChannel::Email->value]);
});

it('defers overnight quiet hours only until the same morning boundary', function () {
    Queue::fake();
    config()->set('notification-center.push.project_id', 'majlis-test');
    config()->set('notification-center.push.credentials', 'token');

    $now = CarbonImmutable::parse('2026-03-10 06:00:00', 'Asia/Kuala_Lumpur');
    Carbon::setTestNow($now);
    CarbonImmutable::setTestNow($now);

    try {
        $user = User::factory()->create([
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        NotificationDestination::factory()->for($user)->create([
            'channel' => NotificationChannel::Push->value,
            'address' => 'device-overnight',
            'external_id' => 'push-token-overnight',
        ]);

        app(NotificationSettingsManager::class)->save($user, [
            'settings' => [
                'timezone' => 'Asia/Kuala_Lumpur',
                'quiet_hours_start' => '22:00',
                'quiet_hours_end' => '07:00',
                'preferred_channels' => ['push'],
            ],
            'families' => [
                'event_updates' => [
                    'enabled' => true,
                    'cadence' => 'instant',
                    'channels' => ['push'],
                ],
            ],
        ]);

        $pending = app(NotificationEngine::class)->dispatchToUser($user, new NotificationDispatchData(
            trigger: NotificationTrigger::EventScheduleChanged,
            title: 'Schedule changed',
            body: 'This should wait only until 7 AM today.',
            fingerprint: 'overnight-quiet-hours-boundary',
        ));

        expect($pending)->toBeInstanceOf(PendingNotification::class);

        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($pending): bool {
            if (! $job->notification instanceof NotificationCenterMessage) {
                return false;
            }

            if ($job->notification->pendingNotificationId !== $pending->id || $job->notification->targetChannel !== NotificationChannel::Push) {
                return false;
            }

            if (! $job->delay instanceof DateTimeInterface) {
                return false;
            }

            return CarbonImmutable::instance($job->delay)
                ->timezone('Asia/Kuala_Lumpur')
                ->format('Y-m-d H:i') === '2026-03-10 07:00';
        });
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
});

it('builds daily digests from pending notifications in the scheduled window', function () {
    Queue::fake();

    $now = CarbonImmutable::parse('2026-03-10 08:14:00', 'Asia/Kuala_Lumpur');
    Carbon::setTestNow($now);
    CarbonImmutable::setTestNow($now);

    try {
        $user = User::factory()->create([
            'email' => 'digest-window@example.test',
            'email_verified_at' => now(),
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        app(NotificationSettingsManager::class)->save($user, [
            'settings' => [
                'timezone' => 'Asia/Kuala_Lumpur',
                'digest_delivery_time' => '08:00',
                'preferred_channels' => ['email'],
            ],
            'families' => [
                'followed_content' => [
                    'enabled' => true,
                    'cadence' => 'daily',
                    'channels' => ['email'],
                ],
            ],
        ]);

        $earlyBoundaryMessage = PendingNotification::factory()->for($user)->create([
            'family' => NotificationFamily::FollowedContent->value,
            'trigger' => NotificationTrigger::FollowedSpeakerEvent->value,
            'title' => 'Boundary message',
            'delivery_cadence' => NotificationCadence::Daily->value,
            'occurred_at' => CarbonImmutable::parse('2026-03-09 08:05:00', 'Asia/Kuala_Lumpur')->utc(),
            'meta' => [
                'inbox_visible' => false,
            ],
        ]);
        $regularWindowMessage = PendingNotification::factory()->for($user)->create([
            'family' => NotificationFamily::FollowedContent->value,
            'trigger' => NotificationTrigger::FollowedSpeakerEvent->value,
            'title' => 'Regular message',
            'delivery_cadence' => NotificationCadence::Daily->value,
            'occurred_at' => CarbonImmutable::parse('2026-03-09 09:00:00', 'Asia/Kuala_Lumpur')->utc(),
            'meta' => [
                'inbox_visible' => false,
            ],
        ]);

        new DispatchNotificationDigests('daily')
            ->handle(app(NotificationEngine::class), app(NotificationSettingsManager::class));

        $digestPending = PendingNotification::query()
            ->where('user_id', $user->id)
            ->where('fingerprint', 'like', 'digest:daily:followed_speaker_event:'.$user->id.':%')
            ->firstOrFail();

        expect(data_get($digestPending->meta, 'source_message_ids'))->toBe([
            $earlyBoundaryMessage->id,
            $regularWindowMessage->id,
        ]);
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
});

it('localizes daily digest notifications per recipient locale', function () {
    Queue::fake();

    $now = CarbonImmutable::parse('2026-03-10 08:05:00', 'Asia/Kuala_Lumpur');
    Carbon::setTestNow($now);
    CarbonImmutable::setTestNow($now);

    try {
        $englishUser = User::factory()->create([
            'email' => 'digest-en@example.test',
            'email_verified_at' => now(),
        ]);
        $malayUser = User::factory()->create([
            'email' => 'digest-ms@example.test',
            'email_verified_at' => now(),
        ]);

        app(NotificationSettingsManager::class)->save($englishUser, [
            'settings' => [
                'locale' => 'en',
                'timezone' => 'Asia/Kuala_Lumpur',
                'digest_delivery_time' => '08:00',
                'preferred_channels' => ['email'],
            ],
            'families' => [
                'followed_content' => [
                    'enabled' => true,
                    'cadence' => 'daily',
                    'channels' => ['email'],
                ],
            ],
        ]);

        app(NotificationSettingsManager::class)->save($malayUser, [
            'settings' => [
                'locale' => 'ms',
                'timezone' => 'Asia/Kuala_Lumpur',
                'digest_delivery_time' => '08:00',
                'preferred_channels' => ['email'],
            ],
            'families' => [
                'followed_content' => [
                    'enabled' => true,
                    'cadence' => 'daily',
                    'channels' => ['email'],
                ],
            ],
        ]);

        PendingNotification::factory()->for($englishUser)->create([
            'family' => NotificationFamily::FollowedContent->value,
            'trigger' => NotificationTrigger::FollowedSpeakerEvent->value,
            'title' => 'English source',
            'delivery_cadence' => NotificationCadence::Daily->value,
            'occurred_at' => CarbonImmutable::parse('2026-03-09 09:00:00', 'Asia/Kuala_Lumpur')->utc(),
            'meta' => ['inbox_visible' => false],
        ]);

        PendingNotification::factory()->for($malayUser)->create([
            'family' => NotificationFamily::FollowedContent->value,
            'trigger' => NotificationTrigger::FollowedSpeakerEvent->value,
            'title' => 'Sumber Melayu',
            'delivery_cadence' => NotificationCadence::Daily->value,
            'occurred_at' => CarbonImmutable::parse('2026-03-09 09:00:00', 'Asia/Kuala_Lumpur')->utc(),
            'meta' => ['inbox_visible' => false],
        ]);

        new DispatchNotificationDigests('daily')
            ->handle(app(NotificationEngine::class), app(NotificationSettingsManager::class));

        $englishDigest = PendingNotification::query()
            ->where('user_id', $englishUser->id)
            ->where('fingerprint', 'like', 'digest:daily:followed_speaker_event:'.$englishUser->id.':%')
            ->firstOrFail();

        $malayDigest = PendingNotification::query()
            ->where('user_id', $malayUser->id)
            ->where('fingerprint', 'like', 'digest:daily:followed_speaker_event:'.$malayUser->id.':%')
            ->firstOrFail();

        expect($englishDigest->title)->toBe('1 updates ready for review')
            ->and($malayDigest->title)->toBe('1 kemas kini sedia untuk disemak');
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
});

it('marks digest source deliveries only after the digest delivery succeeds', function () {
    $user = User::factory()->create([
        'email' => 'digest@example.test',
        'email_verified_at' => now(),
    ]);

    NotificationDestination::factory()->for($user)->create([
        'channel' => NotificationChannel::Email->value,
        'address' => 'digest@example.test',
        'external_id' => null,
    ]);

    $sourcePending = PendingNotification::factory()->for($user)->create([
        'family' => NotificationFamily::EventUpdates->value,
        'trigger' => NotificationTrigger::EventScheduleChanged->value,
    ]);
    $digestPending = PendingNotification::factory()->for($user)->create([
        'family' => NotificationFamily::EventUpdates->value,
        'trigger' => NotificationTrigger::EventScheduleChanged->value,
        'meta' => [
            'digest' => true,
            'source_message_ids' => [$sourcePending->id],
            'inbox_visible' => false,
        ],
    ]);

    assertDatabaseMissing('notification_deliveries', [
        'notification_message_id' => $sourcePending->id,
        'channel' => NotificationChannel::Email->value,
        'status' => NotificationDeliveryStatus::Delivered->value,
    ]);

    $notification = NotificationCenterMessage::fromPending(
        pending: $digestPending,
        targetChannel: NotificationChannel::Email,
        family: NotificationFamily::EventUpdates,
        trigger: NotificationTrigger::EventScheduleChanged,
        priority: NotificationPriority::Medium,
        channelsAttempted: [NotificationChannel::Email->value],
        fallbackChannels: [],
        fallbackStrategy: 'skip',
        bypassQuietHours: false,
    );

    app(NotificationDeliveryLogger::class)->logMailSent($user, $notification);

    assertDatabaseHas('notification_deliveries', [
        'notification_message_id' => $sourcePending->id,
        'channel' => NotificationChannel::Email->value,
        'status' => NotificationDeliveryStatus::Delivered->value,
    ]);
});

it('formats notification mail timestamps with the notifiable timezone', function () {
    $user = User::factory()->create([
        'email' => 'mail-timezone@example.test',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    app(NotificationSettingsManager::class)->save($user, [
        'settings' => [
            'locale' => 'en',
            'timezone' => 'Asia/Kuala_Lumpur',
        ],
    ]);

    $pending = PendingNotification::factory()->for($user)->create([
        'occurred_at' => CarbonImmutable::parse('2026-03-09 00:30:00', 'UTC'),
    ]);

    $notification = NotificationCenterMessage::fromPending(
        pending: $pending,
        targetChannel: NotificationChannel::Email,
        family: NotificationFamily::EventUpdates,
        trigger: NotificationTrigger::EventScheduleChanged,
        priority: NotificationPriority::Medium,
        channelsAttempted: [NotificationChannel::Email->value],
        fallbackChannels: [],
        fallbackStrategy: 'skip',
        bypassQuietHours: false,
    );

    $originalLocale = app()->getLocale();
    app()->setLocale($user->preferredLocale());

    try {
        $mail = $notification->toMail($user);
    } finally {
        app()->setLocale($originalLocale);
    }

    $introLines = array_map(static fn (mixed $line): string => trim(strip_tags((string) $line)), $mail->introLines);

    expect($introLines)->toContain('Occurred at: 9 Mar 2026, 8:30 AM');
});

it('records push delivery results through the notification sent listener', function () {
    Http::fake([
        'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/1'], 200),
    ]);

    config()->set('notification-center.push.project_id', 'majlis-test');
    config()->set('notification-center.push.credentials', 'token');

    $user = User::factory()->create();

    $invalidDestination = NotificationDestination::factory()->for($user)->create([
        'channel' => NotificationChannel::Push->value,
        'address' => 'device-invalid',
        'external_id' => null,
    ]);
    $validDestination = NotificationDestination::factory()->for($user)->create([
        'channel' => NotificationChannel::Push->value,
        'address' => 'device-valid',
        'external_id' => 'push-token-valid',
    ]);

    $pending = PendingNotification::factory()->for($user)->create();
    $notification = NotificationCenterMessage::fromPending(
        pending: $pending,
        targetChannel: NotificationChannel::Push,
        family: NotificationFamily::EventUpdates,
        trigger: NotificationTrigger::EventScheduleChanged,
        priority: NotificationPriority::Medium,
        channelsAttempted: [NotificationChannel::Push->value],
        fallbackChannels: [NotificationChannel::Email->value],
        fallbackStrategy: 'next_available',
        bypassQuietHours: false,
    );

    NotificationFacade::sendNow($user, $notification);

    assertDatabaseHas('notification_deliveries', [
        'notification_message_id' => $pending->id,
        'channel' => NotificationChannel::Push->value,
        'destination_id' => $invalidDestination->id,
        'status' => NotificationDeliveryStatus::Failed->value,
    ]);

    assertDatabaseHas('notification_deliveries', [
        'notification_message_id' => $pending->id,
        'channel' => NotificationChannel::Push->value,
        'destination_id' => $validDestination->id,
        'status' => NotificationDeliveryStatus::Delivered->value,
    ]);
});

it('logs whatsapp configuration failures through the notification failed listener', function () {
    config()->set('notification-center.whatsapp.phone_number_id');
    config()->set('notification-center.whatsapp.access_token');

    $user = User::factory()->create([
        'phone' => '+60128889999',
        'phone_verified_at' => now(),
    ]);

    $destination = NotificationDestination::factory()->for($user)->create([
        'channel' => NotificationChannel::Whatsapp->value,
        'address' => '+60128889999',
        'external_id' => null,
    ]);

    $pending = PendingNotification::factory()->for($user)->create();
    $notification = NotificationCenterMessage::fromPending(
        pending: $pending,
        targetChannel: NotificationChannel::Whatsapp,
        family: NotificationFamily::EventUpdates,
        trigger: NotificationTrigger::EventCancelled,
        priority: NotificationPriority::Urgent,
        channelsAttempted: [NotificationChannel::Whatsapp->value],
        fallbackChannels: [NotificationChannel::Email->value],
        fallbackStrategy: 'next_available',
        bypassQuietHours: false,
    );

    try {
        NotificationFacade::sendNow($user, $notification);
        $this->fail('Expected WhatsApp delivery to fail when provider config is missing.');
    } catch (ChannelDeliveryException $exception) {
        expect($exception->provider)->toBe('meta_cloud');
    }

    assertDatabaseHas('notification_deliveries', [
        'notification_message_id' => $pending->id,
        'channel' => NotificationChannel::Whatsapp->value,
        'destination_id' => $destination->id,
        'status' => NotificationDeliveryStatus::Failed->value,
    ]);
});
