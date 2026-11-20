<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api;

/**
 * Going/save toggles for public event surfaces.
 *
 * The owner keeps the auth redirect, event eligibility, and engagement
 * commands together so every surface toggles identically. A returned string is
 * the login URL the caller must navigate to; null means the toggle was applied
 * or the event was not eligible.
 */
interface EventEngagementToggles
{
    public function toggleGoing(string $eventId, string $returnUrl): ?string;

    public function toggleSave(string $eventId, string $returnUrl): ?string;
}
