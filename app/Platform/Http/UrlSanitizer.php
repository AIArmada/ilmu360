<?php

declare(strict_types=1);

namespace App\Platform\Http;

/**
 * Normalizes user-supplied link URLs so dangerous schemes (including entity- or
 * percent-obfuscated javascript:/data:) and protocol-relative URLs never render.
 */
final class UrlSanitizer
{
    public static function sanitize(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = trim($url);

        if ($trimmed === '' || preg_match('/[\x00-\x20\x7F]/', $trimmed) === 1) {
            return null;
        }

        $decoded = preg_replace_callback(
            '/&#(?:x([0-9a-f]+)|([0-9]+));?/i',
            static function (array $match): string {
                $code = $match[1] !== '' ? (int) hexdec($match[1]) : (int) $match[2];

                return $code <= 127 ? chr($code) : $match[0];
            },
            $trimmed,
        ) ?? $trimmed;

        $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (
            preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
            || preg_match('/[\x00-\x1F\x7F]/', rawurldecode($decoded)) === 1
        ) {
            return null;
        }

        if (str_starts_with($trimmed, '//') || str_starts_with($decoded, '//')) {
            return null;
        }

        if (str_starts_with($trimmed, '/') && str_starts_with($decoded, '/')) {
            return $trimmed;
        }

        if (preg_match('/^([a-z][a-z0-9+\-.]*):/i', $decoded, $matches) === 1) {
            return in_array(strtolower($matches[1]), ['http', 'https'], true) ? $trimmed : null;
        }

        return null;
    }
}
