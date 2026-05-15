<?php

namespace App\Services\Notifications;

use App\Models\Event;
use BackedEnum;
use Carbon\CarbonImmutable;
use UnitEnum;

class NotificationMessageRenderer
{
    /**
     * @param  array{key?: mixed, params?: mixed}|null  $definition
     */
    public function renderDefinition(?array $definition, ?object $notifiable = null, string $fallback = ''): string
    {
        if (! is_array($definition)) {
            return $fallback;
        }

        $key = $definition['key'] ?? null;

        if (! is_string($key) || $key === '') {
            return $fallback;
        }

        $params = $definition['params'] ?? [];

        return __($key, $this->resolveParameters(is_array($params) ? $params : [], $notifiable));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function resolveParameters(array $params, ?object $notifiable = null): array
    {
        $resolved = [];

        foreach ($params as $key => $value) {
            $resolved[$key] = $this->resolveValue($value, $notifiable);
        }

        return $resolved;
    }

    /**
     * @return array{type: string, starts_at: string|null, timezone: string|null}
     */
    public function eventTimingToken(Event $event): array
    {
        return [
            'type' => 'event_timing',
            'starts_at' => $event->starts_at?->toIso8601String(),
            'timezone' => is_string($event->timezone) && $event->timezone !== ''
                ? $event->timezone
                : null,
        ];
    }

    protected function resolveValue(mixed $value, ?object $notifiable = null): mixed
    {
        if (is_array($value)) {
            if (($value['type'] ?? null) === 'event_timing') {
                return $this->formatEventTiming($value, $notifiable);
            }

            return array_map(fn (mixed $item): mixed => $this->resolveValue($item, $notifiable), $value);
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
    protected function formatEventTiming(array $token, ?object $notifiable = null): string
    {
        $startsAt = $token['starts_at'] ?? null;

        if (! is_string($startsAt) || $startsAt === '') {
            return __('notifications.messages.timing.to_be_confirmed');
        }

        $timezone = null;

        if ($notifiable !== null && method_exists($notifiable, 'preferredTimezone')) {
            $resolvedTimezone = $notifiable->preferredTimezone();

            if (is_string($resolvedTimezone) && $resolvedTimezone !== '') {
                $timezone = $resolvedTimezone;
            }
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
