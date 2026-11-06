<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Events\Models\EventOccurrence;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class EventOccurrenceFeaturedObserver
{
    public function saving(EventOccurrence $occurrence): void
    {
        if (! $occurrence->isDirty('is_featured')) {
            return;
        }

        $user = auth()->user();

        if ($user instanceof User && ! $user->hasGlobalAdminAccess()) {
            throw new AuthorizationException('Only global administrators may curate featured occurrences.');
        }
    }
}
