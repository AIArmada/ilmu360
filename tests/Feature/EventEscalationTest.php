<?php

use AIArmada\Events\Models\EventAttribute;
use App\Enums\EventEscalationType;
use App\Jobs\EscalatePendingEvents;
use App\Models\Event;
use App\Models\EventEscalation;
use App\Models\User;
use App\Notifications\EventEscalationNotification;
use App\States\EventStatus\Transitions\ApproveEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => false]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $roleClass = app(PermissionRegistrar::class)->getRoleClass();

    foreach (['moderator', 'super_admin'] as $role) {
        if (! $roleClass::where('name', $role)->exists()) {
            $roleClass::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function pendingEventAt(CarbonInterface $createdAt, ?CarbonInterface $startsAt = null): Event
{
    $startsAt ??= now()->addDays(30);

    return Event::factory()->create([
        'status' => 'pending',
        'created_at' => $createdAt,
        'starts_at' => $startsAt,
    ]);
}

it('escalates at the 48-hour moderator SLA boundary only', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00'));
    Notification::fake();

    $atBoundary = pendingEventAt(now()->subHours(48));
    $beforeBoundary = pendingEventAt(now()->subHours(48)->addSecond());

    (new EscalatePendingEvents)->handle();

    expect($atBoundary->fresh()->escalations()->where('type', EventEscalationType::ModeratorSla->value)->exists())->toBeTrue()
        ->and($beforeBoundary->fresh()->escalations)->toBeEmpty();

    Notification::assertSentTo(
        User::role('moderator')->get(),
        EventEscalationNotification::class,
        fn (EventEscalationNotification $notification): bool => $notification->escalationType === '48_hours',
    );
});

it('escalates to super admins only after 72 hours and a 24-hour moderator record', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00'));
    Notification::fake();

    $eligible = pendingEventAt(now()->subHours(72));
    $eligibleModeratorEscalation = EventEscalation::create([
        'event_id' => $eligible->id,
        'type' => EventEscalationType::ModeratorSla,
        'decision_key' => $eligible->id.':moderator_sla',
    ]);
    $eligibleModeratorEscalation->forceFill(['created_at' => now()->subHours(24)])->saveQuietly();

    $tooRecent = pendingEventAt(now()->subHours(72));
    $tooRecentModeratorEscalation = EventEscalation::create([
        'event_id' => $tooRecent->id,
        'type' => EventEscalationType::ModeratorSla,
        'decision_key' => $tooRecent->id.':moderator_sla',
    ]);
    $tooRecentModeratorEscalation->forceFill(['created_at' => now()->subHours(23)->subSecond()])->saveQuietly();

    (new EscalatePendingEvents)->handle();

    expect($eligible->fresh()->escalations()->where('type', EventEscalationType::SuperAdminSla->value)->exists())->toBeTrue()
        ->and($tooRecent->fresh()->escalations()->where('type', EventEscalationType::SuperAdminSla->value)->exists())->toBeFalse();

    Notification::assertSentTo(
        User::role('super_admin')->get(),
        EventEscalationNotification::class,
        fn (EventEscalationNotification $notification): bool => $notification->escalationType === '72_hours',
    );
});

it('uses the imminent window when the event starts after 6 and at most 24 hours', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00'));
    Notification::fake();

    $atTwentyFourHours = pendingEventAt(now(), now()->addHours(24));
    $atSixHours = pendingEventAt(now(), now()->addHours(6));

    (new EscalatePendingEvents)->handle();

    expect($atTwentyFourHours->fresh()->escalations()->where('type', EventEscalationType::Imminent->value)->exists())->toBeTrue()
        ->and($atSixHours->fresh()->escalations()->where('type', EventEscalationType::Imminent->value)->exists())->toBeFalse()
        ->and($atSixHours->fresh()->escalations()->where('type', EventEscalationType::Priority->value)->exists())->toBeTrue();

    Notification::assertSentTo(
        User::role('moderator')->get(),
        EventEscalationNotification::class,
        fn (EventEscalationNotification $notification): bool => $notification->escalationType === 'urgent',
    );
});

it('uses the priority window when the event starts after 0 and at most 6 hours', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00'));
    Notification::fake();

    $atSixHours = pendingEventAt(now(), now()->addHours(6));
    $alreadyStarted = pendingEventAt(now(), now()->subSecond());

    (new EscalatePendingEvents)->handle();

    expect($atSixHours->fresh()->escalations()->where('type', EventEscalationType::Priority->value)->exists())->toBeTrue()
        ->and($alreadyStarted->fresh()->escalations)->toBeEmpty();

    Notification::assertSentTo(
        User::role('moderator')->get(),
        EventEscalationNotification::class,
        fn (EventEscalationNotification $notification): bool => $notification->escalationType === 'priority',
    );
    Notification::assertSentTo(
        User::role('super_admin')->get(),
        EventEscalationNotification::class,
        fn (EventEscalationNotification $notification): bool => $notification->escalationType === 'priority',
    );
});

it('does not duplicate a decision or notification when the job is rerun', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00'));
    Notification::fake();
    $event = pendingEventAt(now()->subHours(49));
    $moderator = User::role('moderator')->first();

    (new EscalatePendingEvents)->handle();
    (new EscalatePendingEvents)->handle();

    expect($event->fresh()->escalations)->toHaveCount(1);
    Notification::assertSentTo($moderator, EventEscalationNotification::class, 1);
});

it('excludes non-pending, started, and resolved matching escalations', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00'));
    Notification::fake();

    $approved = pendingEventAt(now()->subHours(49));
    $approved->update(['status' => 'approved']);
    pendingEventAt(now()->subHours(49), now()->subSecond());

    $resolved = pendingEventAt(now()->subHours(49));
    EventEscalation::create([
        'event_id' => $resolved->id,
        'type' => EventEscalationType::ModeratorSla,
        'decision_key' => $resolved->id.':moderator_sla',
        'resolved_at' => now()->subMinute(),
    ]);

    (new EscalatePendingEvents)->handle();

    expect($approved->fresh()->escalations)->toBeEmpty()
        ->and($resolved->fresh()->escalations)->toHaveCount(1)
        ->and(EventEscalation::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('backfills historical escalation metadata idempotently', function (): void {
    $event = pendingEventAt(now()->subDays(3));

    EventAttribute::create([
        'event_id' => $event->id,
        'attribute_key' => 'is_priority',
        'attribute_value' => '1',
    ]);
    EventAttribute::create([
        'event_id' => $event->id,
        'attribute_key' => 'escalated_at',
        'attribute_value' => now()->subDays(2)->toIso8601String(),
    ]);

    $this->artisan('events:backfill-escalations')->assertSuccessful();
    $this->artisan('events:backfill-escalations')->assertSuccessful();

    expect($event->fresh()->escalations)->toHaveCount(2)
        ->and($event->fresh()->escalations->pluck('type')->map(fn ($type) => $type->value)->all())
        ->toEqualCanonicalizing([
            EventEscalationType::Priority->value,
            EventEscalationType::ModeratorSla->value,
        ]);
});

it('resolves unresolved escalations when a pending event is approved', function (): void {
    $moderator = User::factory()->create();
    $event = pendingEventAt(now()->subDays(3));

    $escalation = EventEscalation::create([
        'event_id' => $event->id,
        'type' => EventEscalationType::ModeratorSla,
        'decision_key' => $event->id.':moderator_sla',
    ]);

    new ApproveEvent($event, $moderator)->handle();

    expect($escalation->fresh()->resolved_at)->not->toBeNull();
});
