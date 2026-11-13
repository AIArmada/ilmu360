<?php

declare(strict_types=1);

use App\Features\Engagement\Api\GoingWindow;

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
