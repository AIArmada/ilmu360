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
        $participationCount = DB::table($involvementsTable)->count();
        $latestParticipationChange = DB::table($involvementsTable)->max('updated_at');

        return implode('|', [
            $participationCount,
            (string) $latestParticipationChange,
        ]);
    }
}
