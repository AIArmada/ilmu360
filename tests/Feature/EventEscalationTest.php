<?php

use App\Jobs\EscalatePendingEvents;
use App\Models\Event;
use App\Models\User;
use App\Notifications\EventEscalationNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // Disable teams to simplify role lookup for these tests
    config(['permission.teams' => false]);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    // Get the configured Role class
    $roleClass = app(PermissionRegistrar::class)->getRoleClass();

    // Create roles using the correct model
    if (! $roleClass::where('name', 'moderator')->exists()) {
        $roleClass::create(['name' => 'moderator', 'guard_name' => 'web']);
    }
    if (! $roleClass::where('name', 'super_admin')->exists()) {
        $roleClass::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
});

it('escalates events pending > 48 hours to moderators', function () {
    Notification::fake();

    $moderators = User::role('moderator')->get();
    expect($moderators)->not->toBeEmpty();
    $moderator = $moderators->first();

    $event = Event::factory()->create([
        'status' => 'pending',
        'created_at' => now()->subHours(49),
        'escalated_at' => null,
    ]);

    (new EscalatePendingEvents)->handle();

    $event->refresh();

    expect($event->escalated_at)->not->toBeNull();

    Notification::assertSentTo(
        $moderator,
        EventEscalationNotification::class,
        fn ($notification) => $notification->escalationType === '48_hours'
    );
});

it('escalates events pending > 72 hours to super admin', function () {
    Notification::fake();

    $superAdmins = User::role('super_admin')->get();
    expect($superAdmins)->not->toBeEmpty();
    $superAdmin = $superAdmins->first();

    $event = Event::factory()->create([
        'status' => 'pending',
        'created_at' => now()->subHours(73),
        'escalated_at' => now()->subHours(25),
    ]);

    (new EscalatePendingEvents)->handle();

    $event->refresh();

    expect($event->escalated_at->diffInMinutes(now()))->toBeLessThan(1);

    Notification::assertSentTo(
        $superAdmin,
        EventEscalationNotification::class,
        fn ($notification) => $notification->escalationType === '72_hours'
    );
});

it('notifies moderators for urgent events starting within 24 hours', function () {
    Notification::fake();
    $moderators = User::role('moderator')->get();
    expect($moderators)->not->toBeEmpty();
    $moderator = $moderators->first();

    $event = Event::factory()->create([
        'status' => 'pending',
        'starts_at' => now()->addHours(20),
        'escalated_at' => null,
        'is_priority' => null,
    ]);

    (new EscalatePendingEvents)->handle();

    $event->refresh();

    expect($event->escalated_at)->not->toBeNull();

    Notification::assertSentTo(
        $moderator,
        EventEscalationNotification::class,
        fn ($notification) => $notification->escalationType === 'urgent'
    );
});

it('marks events starting within 6 hours as priority and notifies everyone', function () {
    Notification::fake();
    $moderators = User::role('moderator')->get();
    $superAdmins = User::role('super_admin')->get();

    expect($moderators)->not->toBeEmpty();
    expect($superAdmins)->not->toBeEmpty();

    $event = Event::factory()->create([
        'status' => 'pending',
        'starts_at' => now()->addHours(4),
        'is_priority' => null,
    ]);

    (new EscalatePendingEvents)->handle();

    $event->refresh();

    expect($event->is_priority)->toBeTrue()
        ->and($event->escalated_at)->not->toBeNull();

    Notification::assertSentTo(
        $moderators,
        EventEscalationNotification::class,
        fn ($notification) => $notification->escalationType === 'priority'
    );

    Notification::assertSentTo(
        $superAdmins,
        EventEscalationNotification::class,
        fn ($notification) => $notification->escalationType === 'priority'
    );
});
