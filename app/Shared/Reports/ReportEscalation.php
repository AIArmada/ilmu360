<?php

declare(strict_types=1);

namespace App\Shared\Reports;

interface ReportEscalation
{
    /**
     * Send an approved event back to moderation when reports cross a risk threshold.
     */
    public function remoderateEvent(string $eventId, string $note): void;
}
