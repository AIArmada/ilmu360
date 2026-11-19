<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use voku\helper\ASCII;

/**
 * Public route key for a schedule entry.
 *
 * The schedule pages resolve occurrence and session URLs from primitives so
 * callers (blades, discovery filters, tests) never hand over models, and the
 * key changes only when the slug, title or primary key changes.
 *
 * The fallback mirrors Illuminate\Support\Str::slug() with the "en"
 * language, a "-" separator and the "@" => "at" dictionary, without taking a
 * dependency on the framework helper.
 */
final readonly class PublicScheduleSlug
{
    public static function occurrence(string $slug, ?string $title, string $id): string
    {
        $slug = trim($slug);

        if ($slug !== '') {
            return $slug;
        }

        return self::fallback(title: $title, id: $id, prefix: 'occurrence');
    }

    public static function session(string $slug, ?string $title, string $id): string
    {
        $slug = trim($slug);

        if ($slug !== '') {
            return $slug;
        }

        return self::fallback(title: $title, id: $id, prefix: 'session');
    }

    private static function fallback(?string $title, string $id, string $prefix): string
    {
        $base = self::slugify((string) $title);

        return ($base !== '' ? $base : $prefix).'-'.mb_strtolower(mb_substr($id, 0, 8, 'UTF-8'), 'UTF-8');
    }

    private static function slugify(string $value): string
    {
        $value = ASCII::to_ascii($value, 'en', false);

        // Same pipeline as Str::slug(): flip "_" to the separator, apply the
        // "@" dictionary word, lowercase before stripping everything that is
        // not a separator, letter, number or whitespace, then collapse runs.
        $value = (string) preg_replace('![_]+!u', '-', $value);
        $value = str_replace('@', '-at-', $value);
        $value = (string) preg_replace('![^-\pL\pN\s]+!u', '', mb_strtolower($value, 'UTF-8'));
        $value = (string) preg_replace('![- \s]+!u', '-', $value);

        return trim($value, '-');
    }
}
