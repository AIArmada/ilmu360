<?php

namespace App\Models;

use AIArmada\Events\Models\EventInvolvement;
use App\Models\Concerns\HasEventInvolvementRole;

class EventKeyPerson extends EventInvolvement
{
    use HasEventInvolvementRole;
}
