<?php

declare(strict_types=1);

namespace App\Features\Events\Support\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class EventsSignalSchemaContributor implements SignalSchemaContributor
{
    public function supports(string $event): bool
    {
        return str_starts_with($event, 'moderation.') || str_starts_with($event, 'admin.event_cover.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array
    {
        return [
            'admin.event_cover.generated' => ['event_id', 'event_status'],
            'moderation.event.occurrence_*' => [
                'event_id', 'event_occurrence_id', 'submission_id', 'moderation_action',
                'previous_status', 'current_status', 'moderator_id', 'reason_code', 'has_note',
            ],
            'moderation.event.*' => [
                'event_id', 'moderation_action', 'previous_status', 'current_status',
                'moderator_id', 'reason_code', 'has_note',
            ],
        ];
    }
}
