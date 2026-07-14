<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class InstitutionSpeakerPivot extends Pivot
{
    use HasUuids;

    protected $table = 'institution_speaker';

    public $incrementing = false;

    protected $keyType = 'string';
}
