<?php

declare(strict_types=1);

namespace App\Features\Prayer\Api;

use App\Features\Events\Api\Enums\EventFormat;
use BackedEnum;

/**
 * Single home of the online→organizer prayer-target rule.
 *
 * Online events have no physical location: frontend submit, frontend
 * preview, and admin saves all anchor the prayer clock to the
 * organizer institution. A person organizer (or none) leaves both
 * targets null so every path degrades to the same country default.
 * Physical targets pass through untouched.
 */
final readonly class PrayerTargetSelector
{
    /**
     * @return array{0: string|null, 1: string|null}
     */
    public static function forOnline(?string $organizerKind, ?string $organizerId): array
    {
        return [$organizerKind === 'institution' ? $organizerId : null, null];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    public static function forSave(
        mixed $eventFormat,
        ?string $organizerKind,
        ?string $organizerId,
        ?string $institutionId,
        ?string $venueId,
    ): array {
        $format = $eventFormat instanceof BackedEnum ? $eventFormat->value : $eventFormat;

        if ($format === EventFormat::Online->value) {
            return self::forOnline($organizerKind, $organizerId);
        }

        return [$institutionId, $venueId];
    }
}
