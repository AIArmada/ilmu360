<?php

use Illuminate\Console\Scheduling\Schedule;

it('registers notification reminders every 15 minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'notification-reminders');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *');
});

it('registers escalate pending events hourly', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'escalate-pending-events');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});

it('registers prune orphaned entities daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'prune-orphaned-entities');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});

it('registers sync public submission locks hourly', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'sync-public-submission-locks');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});

it('registers media library clean daily at 02:30', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'media-library-clean');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 2 * * *');
});

it('registers media library regenerate weekly on Sunday at 03:00', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'media-library-regenerate-missing');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * 0');
});

it('registers horizon snapshot every 5 minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'horizon-snapshot');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *');
});

it('registers communications digests every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'communications-send-digests');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});
