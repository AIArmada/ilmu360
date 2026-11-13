<?php

declare(strict_types=1);

namespace App\Features\Communications\Support\Normalization;

use App\Features\Communications\Api\NotificationCadence;
use App\Features\Communications\Api\NotificationCatalog;
use App\Features\Communications\Api\NotificationChannel;

final class NotificationPreferenceNormalizer
{
    /**
     * @return list<string>
     */
    public static function channels(mixed $value): array
    {
        $fallback = self::defaultChannels();
        $channels = collect(is_array($value) ? $value : $fallback)
            ->map(static fn (mixed $channel): string => (string) $channel)
            ->filter(static fn (string $channel): bool => in_array($channel, NotificationCatalog::supportedChannels(), true))
            ->unique()
            ->values()
            ->all();

        if ($channels === []) {
            $channels = $fallback;
        }

        if (! in_array(NotificationChannel::InApp->value, $channels, true)) {
            array_unshift($channels, NotificationChannel::InApp->value);
        }

        return array_values(array_unique($channels));
    }

    /**
     * @return list<string>
     */
    public static function defaultChannels(): array
    {
        return [
            NotificationChannel::InApp->value,
            NotificationChannel::Email->value,
        ];
    }

    public static function cadence(string $value): NotificationCadence
    {
        return NotificationCadence::tryFrom($value) ?? NotificationCadence::Instant;
    }

    public static function timezone(string $value): string
    {
        return in_array($value, \DateTimeZone::listIdentifiers(), true)
            ? $value
            : (config('app.timezone') ?: 'UTC');
    }

    public static function userTimezone(?string $value): string
    {
        return $value !== null ? self::timezone($value) : 'Asia/Kuala_Lumpur';
    }

    public static function time(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\\d{2}:\\d{2}$/', $trimmed) === 1) {
            return $trimmed.':00';
        }

        return preg_match('/^\\d{2}:\\d{2}:\\d{2}$/', $trimmed) === 1 ? $trimmed : null;
    }
}
