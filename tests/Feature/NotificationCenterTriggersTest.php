<?php

use App\Actions\Events\PublishEventChangeAnnouncement;
use App\Enums\EventChangeType;
use App\Enums\EventKeyPersonRole;
use App\Enums\NotificationTrigger;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Institution;
use App\Models\PendingNotification;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\Series;
use App\Models\Speaker;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates followed-content notifications for followed speakers institutions series and references', function () {
    $institutionFollower = User::factory()->create();
    $speakerFollower = User::factory()->create();
    $seriesFollower = User::factory()->create();
    $referenceFollower = User::factory()->create();

    $institution = Institution::factory()->create();
    $speaker = Speaker::factory()->create();
    $series = Series::factory()->create([
        'visibility' => 'public',
    ]);
    $reference = Reference::factory()->verified()->create();

    $institutionFollower->follow($institution);
    $speakerFollower->follow($speaker);
    $seriesFollower->follow($series);
    $referenceFollower->follow($reference);

    $event = Event::factory()->for($institution)->create([
        'title' => 'Followed Content Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);

    $event->speakers()->attach($speaker->id);
    $event->series()->attach($series->id, ['id' => (string) Str::uuid()]);
    $event->references()->attach($reference->id);

    app(EventNotificationService::class)->notifyPublication($event->fresh(['institution', 'speakers', 'series', 'references']));

    $this->assertDatabaseHas('notification_messages', [
        'user_id' => $institutionFollower->id,
        'trigger' => NotificationTrigger::FollowedInstitutionEvent->value,
    ]);

    $this->assertDatabaseHas('notification_messages', [
        'user_id' => $speakerFollower->id,
        'trigger' => NotificationTrigger::FollowedSpeakerEvent->value,
    ]);

    $this->assertDatabaseHas('notification_messages', [
        'user_id' => $seriesFollower->id,
        'trigger' => NotificationTrigger::FollowedSeriesEvent->value,
    ]);

    $this->assertDatabaseHas('notification_messages', [
        'user_id' => $referenceFollower->id,
        'trigger' => NotificationTrigger::FollowedReferenceEvent->value,
    ]);
});

it('does not create followed-speaker notifications when a followed profile is only a non-speaker participant', function () {
    $speakerFollower = User::factory()->create();
    $institution = Institution::factory()->create();
    $speaker = Speaker::factory()->create();

    $speakerFollower->follow($speaker);

    $event = Event::factory()->for($institution)->create([
        'title' => 'Forum Dengan Moderator Sahaja',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);

    $event->keyPeople()->create([
        'speaker_id' => $speaker->id,
        'role' => EventKeyPersonRole::Moderator,
        'order_column' => 1,
        'is_public' => true,
    ]);

    app(EventNotificationService::class)->notifyPublication($event->fresh(['institution', 'keyPeople.speaker', 'series', 'references']));

    $this->assertDatabaseMissing('notification_messages', [
        'user_id' => $speakerFollower->id,
        'trigger' => NotificationTrigger::FollowedSpeakerEvent->value,
    ]);
});

it('sends update alerts to saved users but no reminders for them', function () {
    $now = CarbonImmutable::parse('2026-03-08 00:00:00', 'UTC');
    Carbon::setTestNow($now);
    CarbonImmutable::setTestNow($now);

    $institution = Institution::factory()->create();
    $savedUser = User::factory()->create();

    $event = Event::factory()->for($institution)->create([
        'title' => 'Tracked Update Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => $now->addHours(2),
    ]);

    $savedUser->savedEvents()->attach($event->id);
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    try {
        app(PublishEventChangeAnnouncement::class)->handle(
            event: $event,
            actor: $administrator,
            type: EventChangeType::ScheduleChanged,
            publicMessage: 'Masa majlis telah dikemas kini.',
        );

        $service = app(EventNotificationService::class);
        $service->dispatchDueReminderNotifications($now);

        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $savedUser->id,
            'trigger' => NotificationTrigger::EventScheduleChanged->value,
        ]);

        $this->assertDatabaseMissing('notification_messages', [
            'user_id' => $savedUser->id,
            'trigger' => NotificationTrigger::Reminder2Hours->value,
        ]);
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
});

it('sends 2-hour and check-in reminders exactly once to going and registered users', function () {
    $now = CarbonImmutable::parse('2026-03-08 00:00:00', 'UTC');
    Carbon::setTestNow($now);
    CarbonImmutable::setTestNow($now);

    $institution = Institution::factory()->create();
    $goingUser = User::factory()->create();
    $registeredUser = User::factory()->create();

    $event = Event::factory()->for($institution)->create([
        'title' => 'Reminder Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => $now->addHours(2),
    ]);

    $goingUser->goingEvents()->attach($event->id);
    Registration::factory()->for($event)->for($registeredUser)->create([
        'status' => 'registered',
    ]);

    try {
        $service = app(EventNotificationService::class);
        $service->dispatchDueReminderNotifications($now);
        $service->dispatchDueReminderNotifications($now);

        expect(PendingNotification::query()
            ->whereIn('user_id', [$goingUser->id, $registeredUser->id])
            ->whereIn('trigger', [
                NotificationTrigger::Reminder2Hours->value,
                NotificationTrigger::CheckinOpen->value,
            ])
            ->count())->toBe(4);
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
});

it('creates registration and check-in confirmation notifications', function () {
    $institution = Institution::factory()->create();
    $user = User::factory()->create();
    $event = Event::factory()->for($institution)->create([
        'title' => 'Registration Flow Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
    ]);

    $registration = Registration::factory()->for($event)->for($user)->create([
        'status' => 'registered',
    ]);

    $checkin = EventCheckin::factory()->for($event)->for($user)->create();

    $service = app(EventNotificationService::class);
    $service->notifyRegistrationConfirmed($registration);
    $service->notifyCheckinConfirmed($checkin);

    $this->assertDatabaseHas('notification_messages', [
        'user_id' => $user->id,
        'trigger' => NotificationTrigger::RegistrationConfirmed->value,
    ]);

    $this->assertDatabaseHas('notification_messages', [
        'user_id' => $user->id,
        'trigger' => NotificationTrigger::CheckinConfirmed->value,
    ]);
});
