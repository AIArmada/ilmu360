<?php

namespace App\Models;

use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Events\Models\EventSubmission as PackageEventSubmission;
use App\Models\Concerns\AuditsModelChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class EventSubmission extends PackageEventSubmission implements Auditable
{
    use AuditsModelChanges, HasContactMethods;

    #[\Override]
    public function event(): BelongsTo
    {
        /** @phpstan-ignore-next-line childReturnType (covariant override) */
        return $this->belongsTo(Event::class, 'event_id');
    }
}
