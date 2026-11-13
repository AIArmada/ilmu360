<?php

namespace App\Features\Communications\Services;

use App\Features\Communications\Api\NotificationMessageRendering;
use BackedEnum;
use Carbon\CarbonImmutable;
use UnitEnum;

final class NotificationMessageRenderer implements NotificationMessageRendering
{
    /**
     * @param  array{key?: mixed, params?: mixed}|null  $definition
     */
    public function renderDefinition(?array $definition, ?string $timezone = null, string $fallback = ''): string
    {
        if (! is_array($definition)) {
            return $fallback;
        }

        $key = $definition['key'] ?? null;

        if (! is_string($key) || $key === '') {
            return $fallback;
        }

        $params = $definition['params'] ?? [];

        return __($key, $this->resolveParameters(is_array($params) ? $params : [], $timezone));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function resolveParameters(array $params, ?string $timezone = null): array
    {
        $resolved = [];

        foreach ($params as $key => $value) {
            $resolved[$key] = $this->resolveValue($value, $timezone);
        }

        return $resolved;
    }

    /**
     * @return array{type: string, starts_at: string|null, timezone: string|null}
     */
    public function eventTimingToken(?string $startsAt, ?string $eventTimezone): array
    {
        return [
            'type' => 'event_timing',
            'starts_at' => $startsAt,
            'timezone' => is_string($eventTimezone) && $eventTimezone !== ''
                ? $eventTimezone
                : null,
        ];
    }

    protected function resolveValue(mixed $value, ?string $timezone = null): mixed
    {
        if (is_array($value)) {
            if (($value['type'] ?? null) === 'event_timing') {
                return $this->formatEventTiming($value, $timezone);
            }

            return array_map(fn (mixed $item): mixed => $this->resolveValue($item, $timezone), $value);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return $value;
    }

    /**
     * @param  array{starts_at?: mixed, timezone?: mixed}  $token
     */
    protected function formatEventTiming(array $token, ?string $timezone = null): string
    {
        $startsAt = $token['starts_at'] ?? null;

        if (! is_string($startsAt) || $startsAt === '') {
            return __('notifications.messages.timing.to_be_confirmed');
        }

        if ($timezone === null) {
            $tokenTimezone = $token['timezone'] ?? null;

            if (is_string($tokenTimezone) && $tokenTimezone !== '') {
                $timezone = $tokenTimezone;
            }
        }

        $locale = app()->getLocale();
        $localizedStart = CarbonImmutable::parse($startsAt)
            ->setTimezone($timezone ?: (string) config('app.timezone'))
            ->locale($locale);

        return $localizedStart->translatedFormat('D, j M Y').' • '.$localizedStart->format('h:i A');
    }
}
