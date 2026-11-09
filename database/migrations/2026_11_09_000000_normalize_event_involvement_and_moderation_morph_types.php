<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalize legacy fully qualified morph types to configured aliases.
 *
 * Writes now use morph aliases; existing rows keep resolving during rollout
 * because the resolver compares normalized values.
 */
return new class extends Migration
{
    public function up(): void
    {
        $involvementsTable = (string) config('events.database.tables.event_involvements', 'event_involvements');
        $moderationTable = (string) config('moderation.database.tables.moderation_actions', 'moderation_actions');

        foreach ([
            'App\\Models\\Person' => 'person',
            'App\\Models\\Institution' => 'institution',
        ] as $class => $alias) {
            DB::table($involvementsTable)
                ->where('involveable_type', $class)
                ->update(['involveable_type' => $alias]);
        }

        DB::table($moderationTable)
            ->where('actioned_by_type', 'App\\Models\\User')
            ->update(['actioned_by_type' => 'user']);
    }
};
