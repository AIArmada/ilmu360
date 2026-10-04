<?php

declare(strict_types=1);

namespace App\Support\Prayer;

use App\Enums\EventFormat;
use App\Models\Institution;
use App\Models\Person;
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
final class PrayerTargetSelector
{
    /**
     * @return array{0: string|null, 1: string|null}
     */
    public static function forOnline(Institution|Person|null $organizer): array
    {
        return [$organizer instanceof Institution ? (string) $organizer->getKey() : null, null];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    public static function forSave(
        mixed $eventFormat,
        Institution|Person|null $organizer,
        ?string $institutionId,
        ?string $venueId,
    ): array {
        $format = $eventFormat instanceof BackedEnum ? $eventFormat->value : $eventFormat;

        if ($format === EventFormat::Online->value) {
            return self::forOnline($organizer);
        }

        return [$institutionId, $venueId];
    }
}
