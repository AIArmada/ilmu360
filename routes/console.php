<?php

use App\Jobs\DispatchEventReminderNotifications;
use App\Jobs\EscalatePendingEvents;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Notification reminders (2-hour and check-in windows)
Schedule::job(new DispatchEventReminderNotifications)
    ->everyFifteenMinutes()
    ->timezone('UTC')
    ->name('notification-reminders')
    ->withoutOverlapping();

// Committed-share signal projection (durable cursor, idempotent)
Schedule::command('signals:drain-committed-shares')
    ->everyFiveMinutes()
    ->timezone('UTC')
    ->name('committed-share-signals')
    ->withoutOverlapping();

// Gratitude digests (calm, delayed, idempotent)
Schedule::command('rewards:dispatch-gratitude-digests')
    ->hourly()
    ->timezone('UTC')
    ->name('reward-gratitude-digests')
    ->withoutOverlapping();

// Referral invite expiry (package action; idempotent status transitions).
Schedule::command('referrals:expire-invites')
    ->hourly()
    ->timezone('UTC')
    ->name('referrals-expire-invites')
    ->withoutOverlapping();

// Source corrections (explicit, idempotent, reported)
Schedule::command('rewards:apply-source-corrections')
    ->dailyAt('02:15')
    ->timezone('UTC')
    ->name('reward-source-corrections')
    ->withoutOverlapping();

// Point-lot expiry (idempotent per scope)
Schedule::command('rewards:expire-point-lots')
    ->dailyAt('03:05')
    ->timezone('UTC')
    ->name('reward-point-lot-expiry')
    ->withoutOverlapping();

// SLA Escalation (per documentation B4a and B6b)
// Runs hourly to check for pending events needing escalation
Schedule::job(new EscalatePendingEvents)
    ->hourly()
    ->timezone('Asia/Kuala_Lumpur')
    ->name('escalate-pending-events')
    ->withoutOverlapping();

// Prune orphaned entities (institutions, persons, venues) with no events after 48 hours
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

// Share-card file retention: revision-pinned files regenerate on demand,
// so pruning is always safe.
Schedule::command('app:prune-share-cards')
    ->dailyAt('03:30')
    ->timezone('Asia/Kuala_Lumpur')
    ->name('prune-share-cards')
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

// Process scheduled digest notification batches.
Schedule::command('communications:send-digests')
    ->everyMinute()
    ->timezone('UTC')
    ->name('communications-send-digests')
    ->withoutOverlapping();

// Warm current and next prayer months so submissions resolve from cache.
// Already-warm months are skipped, so steady-state cost is near zero except at
// month rollover, when the next month is still unpublished (short negative TTL).
Schedule::command('app:prayer:prefetch')
    ->dailyAt('03:30')
    ->timezone('Asia/Kuala_Lumpur')
    ->name('prayer-prefetch')
    ->withoutOverlapping();

// Prayer queue-depth backstop alert (Horizon remains the primary monitor).
// Threshold is deliberately generous: rollover bursts stay well below it.
Schedule::command('app:prayer:stats --alert-queue-depth=5000')
    ->hourly()
    ->timezone('UTC')
    ->name('prayer-queue-depth-alert')
    ->withoutOverlapping();

// Share effects are durable receipts and the dispatcher is idempotent.
Schedule::command('share:dispatch-pending-effects')
    ->everyFiveMinutes()
    ->timezone('UTC')
    ->name('share-pending-effects')
    ->withoutOverlapping();

// Abandoned anonymous share profiles: unclaimed profiles past retention are
// pruned; claims, conversions, and published short links are preserved.
Schedule::command('share:prune-guest-profiles')
    ->dailyAt('03:45')
    ->timezone('UTC')
    ->name('prune-guest-share-profiles')
    ->withoutOverlapping();

// Commission maturity and payout claiming are safe to repeat with MLM disabled.
Schedule::command('affiliates:process-maturity')
    ->dailyAt('01:30')
    ->timezone('UTC')
    ->name('affiliate-commission-maturity')
    ->withoutOverlapping();

Schedule::command('affiliates:process-payouts')
    ->everyFifteenMinutes()
    ->timezone('UTC')
    ->name('affiliate-scheduled-payouts')
    ->withoutOverlapping();
