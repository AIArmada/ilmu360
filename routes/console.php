<?php

use App\Jobs\DispatchEventReminderNotifications;
use App\Jobs\DispatchNotificationDigests;
use App\Jobs\EscalatePendingEvents;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Notification digests and time-based deliveries
Schedule::job(new DispatchNotificationDigests('daily'))
    ->everyFifteenMinutes()
    ->timezone('UTC')
    ->name('notification-digest-daily')
    ->withoutOverlapping();

Schedule::job(new DispatchNotificationDigests('weekly'))
    ->everyFifteenMinutes()
    ->timezone('UTC')
    ->name('notification-digest-weekly')
    ->withoutOverlapping();

Schedule::job(new DispatchEventReminderNotifications)
    ->everyFifteenMinutes()
    ->timezone('UTC')
    ->name('notification-reminders')
    ->withoutOverlapping();

// SLA Escalation (per documentation B4a and B6b)
// Runs hourly to check for pending events needing escalation
Schedule::job(new EscalatePendingEvents)
    ->hourly()
    ->timezone('Asia/Kuala_Lumpur')
    ->name('escalate-pending-events')
    ->withoutOverlapping();

// Prune orphaned entities (institutions, speakers, venues) with no events after 48 hours
Schedule::command('app:prune-orphaned-entities')
    ->daily()
    ->timezone('Asia/Kuala_Lumpur')
    ->name('prune-orphaned-entities')
    ->withoutOverlapping();

// Auto-reopen public submission when lock credibility requirements are no longer met.
Schedule::command('app:sync-public-submission-locks')
    ->hourly()
    ->timezone('Asia/Kuala_Lumpur')
    ->name('sync-public-submission-locks')
    ->withoutOverlapping();

// Media maintenance: remove orphaned/deprecated files and keep conversions healthy.
Schedule::command('media-library:clean --delete-orphaned --force')
    ->dailyAt('02:30')
    ->timezone('Asia/Kuala_Lumpur')
    ->name('media-library-clean')
    ->withoutOverlapping();

Schedule::command('media-library:regenerate --only-missing --with-responsive-images --force')
    ->weeklyOn(0, '03:00')
    ->timezone('Asia/Kuala_Lumpur')
    ->name('media-library-regenerate-missing')
    ->withoutOverlapping();

// Horizon metrics snapshots power the dashboard throughput and wait-time graphs.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->timezone('UTC')
    ->name('horizon-snapshot')
    ->withoutOverlapping();
