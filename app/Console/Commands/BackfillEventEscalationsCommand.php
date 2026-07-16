<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AIArmada\Events\Models\EventAttribute;
use App\Enums\EventEscalationType;
use App\Models\Event;
use App\Models\EventEscalation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class BackfillEventEscalationsCommand extends Command
{
    protected $signature = 'events:backfill-escalations';

    protected $description = 'Backfill canonical event escalations from historical event metadata';

    public function handle(): int
    {
        $created = 0;

        Event::query()->chunkById(100, function ($events) use (&$created): void {
            foreach ($events as $event) {
                $attributes = EventAttribute::query()
                    ->where('event_id', $event->id)
                    ->whereIn('attribute_key', ['is_priority', 'escalated_at'])
                    ->pluck('attribute_value', 'attribute_key');

                $legacyTimestamp = $this->parseTimestamp($attributes->get('escalated_at'));
                $dispatchedAt = $legacyTimestamp ?? $event->updated_at ?? now();
                $resolvedAt = (string) $event->status === 'pending' ? null : ($event->updated_at ?? now());

                if ($attributes->get('is_priority') === '1') {
                    $created += $this->createIfMissing(
                        $event,
                        EventEscalationType::Priority,
                        'Backfilled from historical priority metadata.',
                        $dispatchedAt,
                        $resolvedAt,
                    );
                }

                if ($legacyTimestamp !== null) {
                    $created += $this->createIfMissing(
                        $event,
                        EventEscalationType::ModeratorSla,
                        'Backfilled from historical escalation metadata.',
                        $dispatchedAt,
                        $resolvedAt,
                    );
                }
            }
        });

        $this->info("Created {$created} event escalation records.");

        return self::SUCCESS;
    }

    private function createIfMissing(
        Event $event,
        EventEscalationType $type,
        string $reason,
        Carbon $dispatchedAt,
        ?Carbon $resolvedAt,
    ): int {
        $escalation = EventEscalation::query()->firstOrCreate(
            ['decision_key' => $event->id.':'.$type->value],
            [
                'event_id' => $event->id,
                'type' => $type,
                'reason' => $reason,
                'dispatched_at' => $dispatchedAt,
                'resolved_at' => $resolvedAt,
            ],
        );

        return $escalation->wasRecentlyCreated ? 1 : 0;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
