<?php

use App\Enums\EventEscalationType;
use App\Jobs\EscalatePendingEvents;
use App\Models\Event;
use App\Models\EventEscalation;
use App\Models\User;
use App\Notifications\EventEscalationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
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

it('persists canonical escalation records with immutable timestamps', function () {
    $event = Event::factory()->create();
    $dispatchedAt = now()->subMinute();
    $resolvedAt = now();

    $escalation = EventEscalation::create([
        'event_id' => $event->id,
        'type' => EventEscalationType::ModeratorSla,
        'decision_key' => $event->id.':moderator_sla',
        'reason' => 'Pending moderation exceeded the SLA.',
        'dispatched_at' => $dispatchedAt,
        'resolved_at' => $resolvedAt,
    ]);

    expect($escalation->id)->toBeString()
        ->and($escalation->type)->toBe(EventEscalationType::ModeratorSla)
        ->and($escalation->dispatched_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($escalation->resolved_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($event->fresh()->escalations)->toHaveCount(1);
});

it('enforces one decision key without collapsing event or escalation type isolation', function () {
    $firstEvent = Event::factory()->create();
    $secondEvent = Event::factory()->create();

    EventEscalation::create([
        'event_id' => $firstEvent->id,
        'type' => EventEscalationType::ModeratorSla,
        'decision_key' => $firstEvent->id.':moderator_sla',
    ]);

    EventEscalation::create([
        'event_id' => $firstEvent->id,
        'type' => EventEscalationType::Priority,
        'decision_key' => $firstEvent->id.':priority',
    ]);

    EventEscalation::create([
        'event_id' => $secondEvent->id,
        'type' => EventEscalationType::ModeratorSla,
        'decision_key' => $secondEvent->id.':moderator_sla',
    ]);

    expect(fn () => EventEscalation::create([
        'event_id' => $firstEvent->id,
        'type' => EventEscalationType::ModeratorSla,
        'decision_key' => $firstEvent->id.':moderator_sla',
    ]))->toThrow(QueryException::class);

    expect(EventEscalation::query()->count())->toBe(3)
        ->and($firstEvent->fresh()->escalations)->toHaveCount(2)
        ->and($secondEvent->fresh()->escalations)->toHaveCount(1);
});

it('does not expose legacy escalation state on the canonical model', function () {
    expect((new EventEscalation)->getFillable())
        ->not->toContain('escalated_at')
        ->not->toContain('is_priority');
});
