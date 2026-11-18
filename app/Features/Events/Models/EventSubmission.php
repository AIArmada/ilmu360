<?php

namespace App\Features\Events\Models;

use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Events\Models\EventSubmission as PackageEventSubmission;
use App\Platform\Auditing\AuditsModelChanges;
use Database\Factories\EventSubmissionFactory;
use OwenIt\Auditing\Contracts\Auditable;

/** @extends PackageEventSubmission<Event> */
class EventSubmission extends PackageEventSubmission implements Auditable
{
    use AuditsModelChanges, HasContactMethods;

    #[\Override]
    protected $table = 'event_submissions';

    /**
     * The package factory builds package instances without the application
     * traits, so resolve the application factory explicitly.
     */
    protected static function newFactory(): EventSubmissionFactory
    {
        return EventSubmissionFactory::new();
    }
}
