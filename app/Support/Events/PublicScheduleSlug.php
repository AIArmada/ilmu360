<?php

declare(strict_types=1);

namespace App\Support\Events;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use Illuminate\Support\Str;

final class PublicScheduleSlug
{
    public static function occurrence(EventOccurrence $occurrence): string
    {
        $slug = trim((string) $occurrence->slug);

        if ($slug !== '') {
            return $slug;
        }

        return self::fallback(
            title: $occurrence->title,
            id: (string) $occurrence->getKey(),
            prefix: 'occurrence',
        );
    }

    public static function session(EventSession $session): string
    {
        $slug = trim((string) $session->slug);

        if ($slug !== '') {
            return $slug;
        }

        return self::fallback(
            title: $session->title,
            id: (string) $session->getKey(),
            prefix: 'session',
        );
    }

    private static function fallback(?string $title, string $id, string $prefix): string
    {
        $base = Str::slug((string) $title);

        return ($base !== '' ? $base : $prefix).'-'.Str::lower(Str::substr($id, 0, 8));
    }
}
