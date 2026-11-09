<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Event;
use App\Services\ModerationService;
use App\Shared\Reports\ReportEscalation;

/**
 * @internal Interim global adapter until Events owns report escalation. The
 * approved-event guard stays here so report code never depends on the event
 * status machine or the moderation service.
 */
final readonly class GlobalReportEscalation implements ReportEscalation
{
    public function __construct(
        private ModerationService $moderationService,
    ) {}

    public function remoderateEvent(string $eventId, string $note): void
    {
        $event = Event::query()->find($eventId);

        if (! $event instanceof Event || (string) $event->status !== 'approved') {
            return;
        }

        $this->moderationService->remoderate($event, null, $note);
    }
}
