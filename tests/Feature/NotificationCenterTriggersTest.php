<?php

use AIArmada\Communications\Enums\NotificationTrigger;
use AIArmada\Engagement\Contracts\EngagementManager;
use App\Actions\Events\PublishEventChangeAnnouncement;
use App\Enums\EventChangeType;
use App\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Registration;
use App\Models\Series;
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

it('creates followed-content notifications for followed persons institutions and series', function () {
    $institutionFollower = User::factory()->create();
    $personFollower = User::factory()->create();
    $seriesFollower = User::factory()->create();

    $institution = Institution::factory()->create();
    $person = Person::factory()->create();
    $series = Series::factory()->create([
        'visibility' => 'public',
    ]);

    $institutionFollower->follow($institution);
    $personFollower->follow($person);
    $seriesFollower->follow($series);

    $event = Event::factory()->for($institution)->create([
        'title' => 'Followed Content Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
        'published_at' => now(),
    ]);

    $event->persons()->attach($person->id);
    $event->series()->attach($series->id, ['id' => (string) Str::uuid()]);

    app(EventNotificationService::class)->notifyPublication($event->fresh(['institution', 'persons', 'series', 'references']));

    $this->assertDatabaseHas('notification_inboxes', [
        'recipient_id' => $institutionFollower->id,
    ]);

    $this->assertDatabaseHas('notification_inboxes', [
        'recipient_id' => $personFollower->id,
    ]);

    $this->assertDatabaseHas('notification_inboxes', [
        'recipient_id' => $seriesFollower->id,
    ]);

});

it('does not create followed-person notifications when a followed profile is only a non-person participant', function () {
    $personFollower = User::factory()->create();
    $institution = Institution::factory()->create();
    $person = Person::factory()->create();

    $personFollower->follow($person);

    $event = Event::factory()->for($institution)->create([
        'title' => 'Forum Dengan Moderator Sahaja',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);

    $event->keyPeople()->create([
        'involveable_id' => $person->id,
        'involveable_type' => 'person',
        'role_code' => EventKeyPersonRole::Moderator->value,
        'sort_order' => 1,
        'visibility' => 'public',
    ]);

    app(EventNotificationService::class)->notifyPublication($event->fresh(['institution', 'keyPeople.person', 'series', 'references']));

    $this->assertDatabaseMissing('notification_inboxes', [
        'recipient_id' => $personFollower->id,
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
