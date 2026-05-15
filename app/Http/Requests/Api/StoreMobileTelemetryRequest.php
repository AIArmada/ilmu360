<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\User;
use App\Support\Signals\ProductSignalsClientContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMobileTelemetryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anonymous_id' => ['nullable', 'string', 'max:120'],
            'session_identifier' => ['nullable', 'string', 'max:120'],
            'session_started_at' => ['nullable', 'date'],
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.event_name' => ['required', 'string', 'max:120'],
            'events.*.event_category' => ['nullable', 'string', 'max:80'],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.path' => ['nullable', 'string', 'max:2048'],
            'events.*.url' => ['nullable', 'string', 'max:2048'],
            'events.*.referrer' => ['nullable', 'string', 'max:2048'],
            'events.*.screen_name' => ['nullable', 'string', 'max:120'],
            'events.*.previous_screen_name' => ['nullable', 'string', 'max:120'],
            'events.*.component' => ['nullable', 'string', 'max:120'],
            'events.*.action' => ['nullable', 'string', 'max:120'],
            'events.*.utm_source' => ['nullable', 'string', 'max:120'],
            'events.*.utm_medium' => ['nullable', 'string', 'max:120'],
            'events.*.utm_campaign' => ['nullable', 'string', 'max:120'],
            'events.*.utm_content' => ['nullable', 'string', 'max:120'],
            'events.*.utm_term' => ['nullable', 'string', 'max:120'],
            'events.*.properties' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $explicitHeaderOrigin = app(ProductSignalsClientContext::class)->explicitHeaderOrigin($this);

                if ($explicitHeaderOrigin === null) {
                    $validator->errors()->add('client_origin', 'The X-Ilmu360-Client-Origin header is required for native mobile telemetry.');

                    return;
                }

                if (! in_array($explicitHeaderOrigin['origin'], ['ios', 'ipados', 'android'], true)) {
                    $validator->errors()->add('client_origin', 'The X-Ilmu360-Client-Origin header must identify a native iOS, iPadOS, or Android app.');
                }

                if (! $this->user('sanctum') instanceof User
                    && ! filled($this->input('anonymous_id'))
                    && ! filled($this->input('session_identifier'))) {
                    $validator->errors()->add('anonymous_id', 'Provide anonymous_id or session_identifier when no bearer token is present.');
                }
            },
        ];
    }

    public function actor(): ?User
    {
        $user = $this->user('sanctum');

        return $user instanceof User ? $user : null;
    }

    public function anonymousId(): ?string
    {
        return $this->stringOrNull($this->validated('anonymous_id'), 120);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsPayload(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $events = $validated['events'] ?? [];

        if (! is_array($events)) {
            return [];
        }

        $normalizedEvents = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $normalizedEvents[] = $this->normalizeEvent($event);
        }

        return $normalizedEvents;
    }

    public function sessionIdentifier(): ?string
    {
        return $this->stringOrNull($this->validated('session_identifier'), 120);
    }

    public function sessionStartedAt(): ?string
    {
        return $this->stringOrNull($this->validated('session_started_at'));
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function normalizeEvent(array $event): array
    {
        $properties = is_array($event['properties'] ?? null)
            ? $event['properties']
            : [];

        foreach (['screen_name', 'previous_screen_name', 'component', 'action'] as $field) {
            $value = $this->stringOrNull($event[$field] ?? null, 120);

            if ($value !== null) {
                $properties[$field] = $value;
            }
        }

        return array_filter([
            'event_name' => $this->stringOrNull($event['event_name'] ?? null, 120),
            'event_category' => $this->stringOrNull($event['event_category'] ?? null, 80),
            'occurred_at' => $this->stringOrNull($event['occurred_at'] ?? null),
            'path' => $this->stringOrNull($event['path'] ?? null, 2048),
            'url' => $this->stringOrNull($event['url'] ?? null, 2048),
            'referrer' => $this->stringOrNull($event['referrer'] ?? null, 2048),
            'utm_source' => $this->stringOrNull($event['utm_source'] ?? null, 120),
            'utm_medium' => $this->stringOrNull($event['utm_medium'] ?? null, 120),
            'utm_campaign' => $this->stringOrNull($event['utm_campaign'] ?? null, 120),
            'utm_content' => $this->stringOrNull($event['utm_content'] ?? null, 120),
            'utm_term' => $this->stringOrNull($event['utm_term'] ?? null, 120),
            'properties' => $properties,
        ], function (mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return true;
        });
    }

    private function stringOrNull(mixed $value, ?int $maxLength = null): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        if ($maxLength === null) {
            return $normalized;
        }

        return mb_substr($normalized, 0, $maxLength);
    }
}
