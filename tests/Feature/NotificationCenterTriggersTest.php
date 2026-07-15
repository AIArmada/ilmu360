<?php

use AIArmada\Communications\Enums\NotificationTrigger;
use AIArmada\Engagement\Contracts\EngagementManager;
use App\Actions\Events\PublishEventChangeAnnouncement;
use App\Enums\EventChangeType;
use App\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Institution;
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
use Illuminate\Support\Facades\DB;
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
    $event->references()->create([
        'referenceable_id' => $reference->getKey(),
        'referenceable_type' => 'reference',
        'reference_type' => 'reference',
        'visibility' => 'public',
    ]);

    app(EventNotificationService::class)->notifyPublication($event->fresh(['institution', 'speakers', 'series', 'references']));

    $this->assertDatabaseHas('notification_inboxes', [
        'recipient_id' => $institutionFollower->id,
    ]);

    $this->assertDatabaseHas('notification_inboxes', [
        'recipient_id' => $speakerFollower->id,
    ]);

    $this->assertDatabaseHas('notification_inboxes', [
        'recipient_id' => $seriesFollower->id,
    ]);

    $this->assertDatabaseHas('notification_inboxes', [
        'recipient_id' => $referenceFollower->id,
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
        'involveable_id' => $speaker->id,
        'involveable_type' => 'speaker',
        'role_code' => EventKeyPersonRole::Moderator->value,
        'sort_order' => 1,
        'visibility' => 'public',
    ]);

    app(EventNotificationService::class)->notifyPublication($event->fresh(['institution', 'keyPeople.speaker', 'series', 'references']));

    $this->assertDatabaseMissing('notification_inboxes', [
        'recipient_id' => $speakerFollower->id,
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

    app(EngagementManager::class)->bookmark($savedUser, $event);
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

        $this->assertDatabaseHas('notification_inboxes', [
            'recipient_id' => $savedUser->id,
        ]);

        $this->assertDatabaseMissing('notification_inboxes', [
            'recipient_id' => $savedUser->id,
            'trigger' => NotificationTrigger::ScheduledDispatch->value,
        ]);
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
});

it('sends 2-hour and check-in reminders', function () {
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
        'published_at' => $now->subDay(),
        'starts_at' => $now->addHours(2),
    ]);

    $goingUser->respond($event, 'going');
    Registration::factory()->for($event)->forRegistrant($registeredUser)->create([
        'status' => 'confirmed',
    ]);

    try {
        $service = app(EventNotificationService::class);
        $service->dispatchDueReminderNotifications($now);

        // Reminder triggers map into package inbox triggers (scheduled_dispatch / check_in_recorded).
        expect(DB::table('notification_inboxes')
            ->whereIn('recipient_id', [$goingUser->id, $registeredUser->id])
            ->count())->toBeGreaterThan(0);

        $this->assertDatabaseHas('notification_inboxes', [
            'recipient_id' => $goingUser->id,
        ]);
        $this->assertDatabaseHas('notification_inboxes', [
            'recipient_id' => $registeredUser->id,
        ]);
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

    $registration = Registration::factory()->for($event)->forRegistrant($user)->create([
        'status' => 'confirmed',
    ]);

    $checkin = EventCheckin::factory()->for($event)->for($user)->create();

    $service = app(EventNotificationService::class);
    $service->notifyRegistrationConfirmed($registration);
    $service->notifyCheckinConfirmed($checkin);

    $this->assertDatabaseHas('notification_inboxes', [
        'recipient_id' => $user->id,
    ]);

    $this->assertDatabaseHas('notification_inboxes', [
        'recipient_id' => $user->id,
    ]);
});
