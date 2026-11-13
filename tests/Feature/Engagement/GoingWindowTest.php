<?php

declare(strict_types=1);

use App\Features\Engagement\Api\GoingWindow;
use App\Models\Event;

/**
 * @return DateTimeImmutable
 */
function engagementGoingMoment(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('UTC'));
}

it('uses ends_at as the effective end and falls back to starts_at', function (): void {
    $startsAt = engagementGoingMoment('2026-11-12 09:00:00');
    $endsAt = engagementGoingMoment('2026-11-12 11:00:00');

    expect(GoingWindow::from($startsAt, $endsAt)->effectiveEnd()?->format('Y-m-d H:i:s'))
        ->toBe('2026-11-12 11:00:00')
        ->and(GoingWindow::from($startsAt, null)->effectiveEnd()?->format('Y-m-d H:i:s'))
        ->toBe('2026-11-12 09:00:00')
        ->and(GoingWindow::from(null, null)->effectiveEnd())
        ->toBeNull();
});

it('stays open through the effective end and closes only after it', function (): void {
    $window = GoingWindow::from(
        engagementGoingMoment('2026-11-12 09:00:00'),
        engagementGoingMoment('2026-11-12 11:00:00'),
    );

    expect($window->isOpenAt(engagementGoingMoment('2026-11-12 10:59:59')))->toBeTrue()
        ->and($window->isOpenAt(engagementGoingMoment('2026-11-12 11:00:00')))->toBeTrue()
        ->and($window->isOpenAt(engagementGoingMoment('2026-11-12 11:00:01')))->toBeFalse()
        ->and($window->hasEndedAt(engagementGoingMoment('2026-11-12 11:00:00')))->toBeFalse()
        ->and($window->hasEndedAt(engagementGoingMoment('2026-11-12 11:00:01')))->toBeTrue();
});

it('stays open when the window has no dates', function (): void {
    expect(GoingWindow::from(null, null)->isOpenAt(engagementGoingMoment('2026-11-12 11:00:00')))->toBeTrue();
});

it('uses the latest eligible grouped occurrence effective end', function (): void {
    $window = GoingWindow::grouped(
        engagementGoingMoment('2026-11-12 09:00:00'),
        engagementGoingMoment('2026-11-12 10:00:00'),
        [
            engagementGoingMoment('2026-11-12 10:30:00'),
            engagementGoingMoment('2026-11-12 12:00:00'),
            engagementGoingMoment('2026-11-12 11:15:00'),
            null,
        ],
    );

    expect($window->effectiveEnd()?->format('Y-m-d H:i:s'))->toBe('2026-11-12 12:00:00')
        ->and($window->isOpenAt(engagementGoingMoment('2026-11-12 11:59:59')))->toBeTrue()
        ->and($window->isOpenAt(engagementGoingMoment('2026-11-12 12:00:00')))->toBeTrue()
        ->and($window->isOpenAt(engagementGoingMoment('2026-11-12 12:00:01')))->toBeFalse();
});

it('falls back to the event window when grouped occurrences have no eligible end', function (): void {
    $window = GoingWindow::grouped(
        engagementGoingMoment('2026-11-12 09:00:00'),
        engagementGoingMoment('2026-11-12 10:00:00'),
        [null],
    );

    expect($window->effectiveEnd()?->format('Y-m-d H:i:s'))->toBe('2026-11-12 10:00:00');
});

it('uses the latest eligible occurrence end for a grouped submission going window', function (): void {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => null,
        'metadata' => ['public_submission_group' => ['key' => 'going-group']],
    ]);

    $ongoingEndsAt = now()->startOfSecond()->addHour();
    $laterEndsAt = now()->startOfSecond()->addDay()->addHours(2);

    $event->occurrences()->create([
        'title' => 'Grouped ongoing',
        'slug' => 'grouped-ongoing',
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'published',
        'visibility' => 'public',
        'starts_at' => now()->subHour(),
        'ends_at' => $ongoingEndsAt,
    ]);
    $event->occurrences()->create([
        'title' => 'Grouped later',
        'slug' => 'grouped-later',
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'published',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
        'ends_at' => $laterEndsAt,
    ]);

    $window = $event->fresh()->goingWindow();

    expect($window->isOpenAt(now()))->toBeTrue()
        ->and($window->effectiveEnd()?->equalTo($laterEndsAt))->toBeTrue();
});

it('closes the grouped going window when every eligible occurrence has ended', function (): void {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => null,
        'metadata' => ['public_submission_group' => ['key' => 'going-group-ended']],
    ]);

    $event->occurrences()->create([
        'title' => 'Grouped ended',
        'slug' => 'grouped-ended',
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'published',
        'visibility' => 'public',
        'starts_at' => now()->subHours(3),
        'ends_at' => now()->subHour(),
    ]);
    $event->occurrences()->create([
        'title' => 'Grouped ineligible',
        'slug' => 'grouped-ineligible',
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'draft',
        'visibility' => 'private',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
    ]);

    expect($event->fresh()->goingWindow()->isOpenAt(now()))->toBeFalse();
});
