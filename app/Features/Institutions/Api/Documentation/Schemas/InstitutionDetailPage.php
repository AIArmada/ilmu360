<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api\Documentation\Schemas;

use App\Features\Events\Api\Documentation\Schemas\EventSummary;
use Dedoc\Scramble\Attributes\SchemaName;

/**
 * @phpstan-import-type InstitutionArray from Institution
 * @phpstan-import-type EventSummaryArray from EventSummary
 *
 * @phpstan-type InstitutionDetailPageArray array{institution: InstitutionArray, upcoming_events: list<EventSummaryArray>, upcoming_total: int, past_events: list<EventSummaryArray>, past_total: int}
 */
#[SchemaName('InstitutionDetailPage')]
final readonly class InstitutionDetailPage
{
    /**
     * @param  list<EventSummary>  $upcoming_events
     * @param  list<EventSummary>  $past_events
     */
    public function __construct(
        public Institution $institution,
        public array $upcoming_events,
        public int $upcoming_total,
        public array $past_events,
        public int $past_total,
    ) {}

    /** @return InstitutionDetailPageArray */
    public function toArray(): array
    {
        return [
            'institution' => $this->institution->toArray(),
            'upcoming_events' => array_map(static fn (EventSummary $event): array => $event->toArray(), $this->upcoming_events),
            'upcoming_total' => $this->upcoming_total,
            'past_events' => array_map(static fn (EventSummary $event): array => $event->toArray(), $this->past_events),
            'past_total' => $this->past_total,
        ];
    }
}
