<?php

declare(strict_types=1);

namespace App\Features\Events\Persistence;

use App\Features\Events\Api\EventInvolvementFingerprint;
use Illuminate\Support\Facades\DB;

final readonly class EventInvolvementFingerprintStore implements EventInvolvementFingerprint
{
    public function current(): string
    {
        $involvementsTable = config('events.database.tables.event_involvements', 'event_involvements');
        $row = DB::table($involvementsTable)
            ->selectRaw('count(*) as total, max(updated_at) as latest')
            ->first();

        $participationCount = is_object($row) && isset($row->total) ? (int) $row->total : 0;
        $latestParticipationChange = is_object($row) && isset($row->latest) ? (string) $row->latest : '';

        return implode('|', [
            $participationCount,
            $latestParticipationChange,
        ]);
    }
}
