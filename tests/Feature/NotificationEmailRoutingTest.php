<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Models\Event;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\Auth\WelcomeNotification;
use App\Notifications\EventEscalationNotification;
use App\Notifications\EventSubmittedNotification;
use App\Notifications\Membership\MemberInvitationNotification;
use App\Notifications\NotificationCenterMessage;
use App\Notifications\ReportResolvedNotification;
use App\Services\Notifications\NotificationSettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('only routes notification-center mail through verified email destinations', function () {
    $user = User::factory()->create([
        'email' => 'routing@example.test',
        'email_verified_at' => null,
    ]);

    app(NotificationSettingsManager::class)->syncSystemDestinations($user);

    $notification = new NotificationCenterMessage(
        pendingNotificationId: 'pending-id',
        targetChannel: NotificationChannel::Email,
        family: NotificationFamily::EventUpdates,
        trigger: NotificationTrigger::EventApproved,
        priority: NotificationPriority::Medium,
        title: 'Approved',
        body: 'Approved body',
        actionUrl: null,
        entityType: null,
        entityId: null,
        occurredAt: null,
    );

    expect($user->routeNotificationForMail($notification))->toBeNull()
        ->and($user->routeNotificationForMail(new WelcomeNotification))->toBe('routing@example.test');

    $user->forceFill(['email_verified_at' => now()])->save();
    app(NotificationSettingsManager::class)->syncSystemDestinations($user->fresh() ?? $user);

    expect(($user->fresh() ?? $user)->routeNotificationForMail($notification))->toBe('routing@example.test');
});

it('keeps the mail-capable notifications on the notifications-mail queue', function () {
    $event = Event::factory()->create();
    $report = Report::factory()->create([
        'entity_type' => 'event',
        'entity_id' => $event->id,
        'status' => 'resolved',
    ]);

    expect((new WelcomeNotification)->viaQueues()['mail'])->toBe('notifications-mail')
        ->and((new VerifyEmailNotification)->viaQueues()['mail'])->toBe('notifications-mail')
        ->and(new ResetPasswordNotification('token')->viaQueues()['mail'])->toBe('notifications-mail')
        ->and(new MemberInvitationNotification('Aisyah', 'Institution', 'Masjid Al-Falah', 'Admin', 'invitee@example.test', 'https://example.test/invite')->viaQueues()['mail'])->toBe('notifications-mail')
        ->and(new EventSubmittedNotification($event)->viaQueues()['mail'])->toBe('notifications-mail')
        ->and(new EventEscalationNotification($event, 'priority')->viaQueues()['mail'])->toBe('notifications-mail')
        ->and(new ReportResolvedNotification($report)->viaQueues()['mail'])->toBe('notifications-mail');
});

it('renders report resolved emails with localized copy and an action url', function () {
    $user = User::factory()->create([
        'name' => 'Nadia',
    ]);
    $event = Event::factory()->create([
        'title' => 'Majlis Tafsir Perdana',
        'status' => 'approved',
    ]);
    $report = Report::factory()->create([
        'reporter_id' => $user->id,
        'entity_type' => 'event',
        'entity_id' => $event->id,
        'status' => 'resolved',
        'resolution_note' => 'The event listing has been corrected.',
    ])->fresh('entity');

    $mail = new ReportResolvedNotification($report)->toMail($user);

    expect($mail->subject)->toBe(__('notifications.reports.resolved.subject_resolved'))
        ->and($mail->actionText)->toBe(__('notifications.reports.resolved.action'))
        ->and($mail->actionUrl)->toBe(route('events.show', $event));
});
