<?php

declare(strict_types=1);

namespace App\Support\Events;

/**
 * Permanent ambient art for event hero headers.
 *
 * The backdrop is deliberately independent of the event's own cover: a single
 * calm photograph is rotated between page loads so every majlis shares the
 * same atmosphere without borrowing imagery it does not own. Each image keeps
 * its subject in the right third so the headline sits on quiet space.
 */
final class HeroBackdrop
{
    /**
     * @var list<string>
     */
    private const IMAGES = [
        'images/events/hero-backgrounds/hero-backdrop-v3-01.jpg',
        'images/events/hero-backgrounds/hero-backdrop-v3-02.jpg',
        'images/events/hero-backgrounds/hero-backdrop-v3-03.jpg',
        'images/events/hero-backgrounds/hero-backdrop-v3-04.jpg',
    ];

    /**
     * @return list<string>
     */
    public static function urls(): array
    {
        return array_map(
            static fn (string $path): string => asset($path),
            self::IMAGES,
        );
    }

    public static function randomUrl(): string
    {
        $urls = self::urls();

        return $urls[array_rand($urls)];
    }
}
