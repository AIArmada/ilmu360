<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-import-type SpeakerArray from Speaker
 * @phpstan-import-type EventSummaryArray from EventSummary
 * @phpstan-import-type EventParticipationArray from EventParticipation
 *
 * @phpstan-type SpeakerDetailPageArray array{speaker: SpeakerArray, upcoming_events: list<EventSummaryArray>, upcoming_total: int, past_events: list<EventSummaryArray>, past_total: int, other_role_upcoming_participations: list<EventParticipationArray>, other_role_upcoming_total: int, other_role_past_participations: list<EventParticipationArray>, other_role_past_total: int}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('SpeakerDetailPage')]
final readonly class SpeakerDetailPage implements Arrayable
{
    /**
     * @param  list<EventSummary>  $upcoming_events
     * @param  list<EventSummary>  $past_events
     * @param  list<EventParticipation>  $other_role_upcoming_participations
     * @param  list<EventParticipation>  $other_role_past_participations
     */
    public function __construct(
        public Speaker $speaker,
        public array $upcoming_events,
        public int $upcoming_total,
        public array $past_events,
        public int $past_total,
        public array $other_role_upcoming_participations,
        public int $other_role_upcoming_total,
        public array $other_role_past_participations,
        public int $other_role_past_total,
    ) {}

    /** @return SpeakerDetailPageArray */
    public function toArray(): array
    {
        return [
            'speaker' => $this->speaker->toArray(),
            'upcoming_events' => array_map(static fn (EventSummary $event): array => $event->toArray(), $this->upcoming_events),
            'upcoming_total' => $this->upcoming_total,
            'past_events' => array_map(static fn (EventSummary $event): array => $event->toArray(), $this->past_events),
            'past_total' => $this->past_total,
            'other_role_upcoming_participations' => array_map(static fn (EventParticipation $event): array => $event->toArray(), $this->other_role_upcoming_participations),
            'other_role_upcoming_total' => $this->other_role_upcoming_total,
            'other_role_past_participations' => array_map(static fn (EventParticipation $event): array => $event->toArray(), $this->other_role_past_participations),
            'other_role_past_total' => $this->other_role_past_total,
        ];
    }
}
