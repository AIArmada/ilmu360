<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Escalations;

use AIArmada\Events\Enums\EventEscalationType;
use AIArmada\Events\Models\EventEscalation;
use App\Features\Events\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** The named, idempotent Events writer for pending-moderation escalation. */
final readonly class RecordPendingEventEscalationAction
{
    public function record(Event $event, EventEscalationType $type, string $reason, CarbonImmutable $now): ?EventEscalation
    {
        $decisionKey = $event->id.':'.$type->value;

        $created = EventEscalation::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'type' => $type->value,
            'decision_key' => $decisionKey,
            'reason' => $reason,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($created === 0) {
            return null;
        }

        return EventEscalation::query()->where('decision_key', $decisionKey)->firstOrFail();
    }

    public function markDispatched(EventEscalation $escalation, CarbonImmutable $now): void
    {
        $escalation->update(['dispatched_at' => $now]);
    }
}
