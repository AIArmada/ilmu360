<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use Carbon\CarbonInterface;

/**
 * Shared upcoming/past split for event-page schedule sections.
 *
 * Callers own their Livewire state and base query; this boundary owns the
 * leaf load, optional consumer filter, and the public time split.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, References/EntryPoints, Events/Services, Institutions/EntryPoints
 */
interface EventSchedulePageData
{
}
