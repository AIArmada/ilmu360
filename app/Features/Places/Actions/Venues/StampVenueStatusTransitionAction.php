<?php

declare(strict_types=1);

namespace App\Features\Places\Actions\Venues;

use App\Features\Places\Models\Venue;

/** Maintain status audit fields before Venue is persisted. */
final readonly class StampVenueStatusTransitionAction
{
    public function handle(Venue $venue): void
    {
        if (! $venue->isDirty('status')) {
            return;
        }

        $now = now();
        $venue->last_state_change_at = $now;

        match ((string) $venue->status) {
            'verified' => $venue->verified_at ??= $now,
            'rejected' => $venue->rejected_at ??= $now,
            default => null,
        };

        if ((string) $venue->status === 'verified') {
            $venue->verified_by ??= auth()->id();
        }
    }
}
