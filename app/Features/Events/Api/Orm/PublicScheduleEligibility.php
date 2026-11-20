<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;

/**
 * Public schedule visibility rules.
 *
 * One owner decides whether an occurrence or session belongs on the public
 * schedule so listings, detail pages, and filtering cannot drift.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, Events/Services, Contributions/EntryPoints, Discovery/Services
 */
interface PublicScheduleEligibility
{
    public function isPublicOccurrence(EventOccurrence $occurrence): bool;

    public function isMeaningfulSession(EventSession $session): bool;
}
