<?php

declare(strict_types=1);

namespace App\Features\Events\Persistence;

use App\Features\Events\Models\Event;

/**
 * Owns the event status-row write used by the moderation transitions.
 *
 * The state-machine transitions keep their validation, side effects,
 * notifications and after-commit work; this writer only applies the new
 * status and lifecycle timestamps and persists the row in one place.
 */
final readonly class EventStatusWriter
{
    /**
     * @param  string  $status  State class-string or backing value.
     * @param  array<string, mixed>  $attributes
     */
    public function apply(Event $event, string $status, array $attributes = []): void
    {
        $event->status = $status;

        foreach ($attributes as $attribute => $value) {
            $event->setAttribute($attribute, $value);
        }

        $event->save();
    }
}
