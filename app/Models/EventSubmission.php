<?php

namespace App\Models;

use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Events\Models\EventSubmission as PackageEventSubmission;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\EventSubmissionFactory;
use OwenIt\Auditing\Contracts\Auditable;

/** @extends PackageEventSubmission<Event> */
class EventSubmission extends PackageEventSubmission implements Auditable
{
    use AuditsModelChanges, HasContactMethods;

    /**
     * The package factory builds package instances without the application
     * traits, so resolve the application factory explicitly.
     */
    protected static function newFactory(): EventSubmissionFactory
    {
        return EventSubmissionFactory::new();
    }
}
