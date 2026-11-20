<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api\Orm;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Features\Discovery\Api\PublicScheduleLeaf;
use App\Features\Events\Models\Event;

/**
 * Model-carrying public schedule leaf used by the public schedule ORM port.
 *
 * @internal The hydrated models exist for Livewire page state and relation
 *           rendering. Consumers must not issue new business queries from them.
 *
 * Consumers: References/EntryPoints, Events/Services, Events/Api, Events/EntryPoints, Institutions/EntryPoints, Discovery/Services
 */
interface PublicScheduleModelLeaf extends PublicScheduleLeaf
{
    /**
     * Hydrated owner event model.
     */
    public function event(): Event;

    /**
     * Hydrated package occurrence model.
     */
    public function occurrence(): EventOccurrence;

    /**
     * Hydrated session models.
     *
     * @return iterable<int, EventSession>
     */
    public function sessions(): iterable;

    /**
     * @param  list<string>  $sessionIds
     */
    public function withSessions(array $sessionIds): self;
}
