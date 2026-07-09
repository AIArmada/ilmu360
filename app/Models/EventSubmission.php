<?php

namespace App\Models;

use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Events\Models\EventSubmission as PackageEventSubmission;
use App\Models\Concerns\AuditsModelChanges;
use OwenIt\Auditing\Contracts\Auditable;

class EventSubmission extends PackageEventSubmission implements Auditable
{
    use AuditsModelChanges, HasContactMethods;
}
