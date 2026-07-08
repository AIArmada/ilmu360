<?php

namespace App\Models;

use AIArmada\Moderation\Models\ModerationAction;
use App\Models\Concerns\AuditsModelChanges;
use OwenIt\Auditing\Contracts\Auditable;

class ModerationReview extends ModerationAction implements Auditable
{
    use AuditsModelChanges;
}
